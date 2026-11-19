"""Janela nativa leve, bandeja, fila, escolha de impressao e alerta sempre ativo."""
import os
import queue
import sys
import threading
import time
import tkinter as tk
from tkinter import ttk, messagebox
import webbrowser
import winreg
import winsound
import pystray
from PIL import Image

from agent.api import ApiClient, AuthenticationError, NeedTwoFactor
from agent.config import Preferences, EventStore, save_credential, load_credential, delete_credential
from agent.printing import printers, receipt, spool

WINE = "#651424"
ORANGE = "#FF5825"
WHITE = "#FFFFFF"
DARK = "#272727"
GRAY = "#666666"


class DeliveryzimApp:
    def __init__(self):
        self.root = tk.Tk()
        self.root.title("Deliveryzim Agent")
        self.root.geometry("550x620")
        self.root.minsize(485, 555)
        self.root.configure(bg=WHITE)
        self.root.protocol("WM_DELETE_WINDOW", self.hide)
        self.preferences = Preferences()
        self.store = EventStore()
        self.messages = queue.Queue()
        self.quit_flag = threading.Event()
        self.worker = None
        self.token = load_credential()
        self.client = None
        self.authentication = None
        self.waiting_code = False
        self.dialogs = {}
        self.icon = None
        self.printer_var = tk.StringVar(value=self.preferences.get("impressora") or "")
        self.mode_var = tk.StringVar(value="auto" if self.preferences.get("automatico") else "manual")
        self.paper_var = tk.StringVar(value=str(self.preferences.get("papel") or 80))
        self.start_var = tk.BooleanVar(value=bool(self.preferences.get("iniciar_windows")))
        self.status_var = tk.StringVar(value="Conectando..." if self.token else "Nao vinculado")
        self.name_var = tk.StringVar(value=self.preferences.get("restaurante") or "")
        self._render()
        self.root.after(150, self._drain)
        self.root.after(20000, self._remind)
        if self.token:
            self._start_worker()
        self.root.after(500, self._recover_jobs)

    def _label(self, parent, text, size=10, color=DARK, bold=False):
        return tk.Label(parent, text=text, font=("Segoe UI", size, "bold" if bold else "normal"),
                        bg=parent.cget("bg"), fg=color, anchor="w")

    def _render(self):
        header = tk.Frame(self.root, bg=WINE, padx=22, pady=18)
        header.pack(fill="x")
        tk.Label(header, text="Deliveryzim Agent", bg=WINE, fg=WHITE,
                 font=("Segoe UI", 20, "bold")).pack(anchor="w")
        tk.Label(header, text="Pedidos, impressao termica e alertas", bg=WINE, fg=WHITE,
                 font=("Segoe UI", 10)).pack(anchor="w")
        body = tk.Frame(self.root, bg=WHITE, padx=22, pady=16)
        body.pack(fill="both", expand=True)

        self._label(body, "STATUS DO RESTAURANTE", size=10, color=WINE, bold=True).pack(anchor="w")
        self._label(body, "", size=2).pack()
        self.status_label = tk.Label(body, textvariable=self.status_var, fg=WINE,
                                     bg=WHITE, font=("Segoe UI", 11, "bold"), anchor="w")
        self.status_label.pack(fill="x")
        self.name_label = tk.Label(body, textvariable=self.name_var, bg=WHITE, fg=GRAY, anchor="w")
        self.name_label.pack(fill="x")
        self._label(body, "", size=5).pack()

        self.login_frame = tk.Frame(body, bg="#F7F7F7", padx=10, pady=10)
        self.login_frame.pack(fill="x")
        self._label(self.login_frame, "VINCULAR COMPUTADOR", color=WINE, bold=True).pack(anchor="w")
        self._label(self.login_frame, "Entre com uma conta gerente ou caixa do Deliveryzim.").pack(anchor="w")
        self.username = ttk.Entry(self.login_frame)
        self.username.pack(fill="x", pady=(8, 3))
        self.username.insert(0, "")
        self.password = ttk.Entry(self.login_frame, show="*")
        self.password.pack(fill="x", pady=3)
        self._label(self.login_frame, "E-mail/usuario acima; senha abaixo.", size=9, color=GRAY).pack(anchor="w")
        self.code = ttk.Entry(self.login_frame)
        self.login_button = ttk.Button(self.login_frame, text="Entrar e vincular", command=self._login)
        self.login_button.pack(fill="x", pady=7)
        self.linked_frame = tk.Frame(body, bg=WHITE)
        self.linked_frame.pack(fill="x")
        ttk.Button(self.linked_frame, text="Desvincular este computador", command=self._unlink).pack(anchor="w")

        self._label(body, "", size=8).pack()
        self._label(body, "COMO IMPRIMIR NOVOS PEDIDOS", color=WINE, bold=True).pack(anchor="w")
        ttk.Radiobutton(body, text="Imprimir automaticamente", variable=self.mode_var,
                        value="auto", command=self._save_preferences).pack(anchor="w", pady=3)
        ttk.Radiobutton(body, text="Perguntar antes de imprimir", variable=self.mode_var,
                        value="manual", command=self._save_preferences).pack(anchor="w", pady=3)
        self._label(body, "O alerta sonoro toca nos dois modos.", size=9, color=GRAY).pack(anchor="w")

        self._label(body, "", size=6).pack()
        self._label(body, "IMPRESSORA DO WINDOWS", color=WINE, bold=True).pack(anchor="w")
        self.printer_selector = ttk.Combobox(body, textvariable=self.printer_var, state="readonly",
                                             values=printers())
        self.printer_selector.pack(fill="x", pady=(4, 4))
        self.printer_selector.bind("<<ComboboxSelected>>", lambda _: self._save_preferences())
        ttk.Button(body, text="Atualizar lista de impressoras", command=self._reload_printers).pack(anchor="w")
        paper = tk.Frame(body, bg=WHITE)
        paper.pack(anchor="w", pady=(7, 2))
        self._label(paper, "Papel: ").pack(side="left")
        ttk.Radiobutton(paper, text="58 mm", value="58", variable=self.paper_var,
                        command=self._save_preferences).pack(side="left")
        ttk.Radiobutton(paper, text="80 mm", value="80", variable=self.paper_var,
                        command=self._save_preferences).pack(side="left", padx=10)

        ttk.Checkbutton(body, text="Iniciar automaticamente com o Windows",
                        variable=self.start_var, command=self._save_preferences).pack(anchor="w", pady=12)
        actions = tk.Frame(body, bg=WHITE)
        actions.pack(fill="x", pady=(1, 0))
        ttk.Button(actions, text="Testar pedido e alerta", command=self._test).pack(side="left")
        ttk.Button(actions, text="Abrir Deliveryzim", command=lambda: webbrowser.open("https://deliveryzim.com.br")).pack(side="right")
        self._label(body, "Fechar a janela nao encerra o agente: ele permanece na bandeja do Windows.",
                    size=9, color=GRAY).pack(anchor="w", pady=12)
        self._show_link_state()

    def _show_link_state(self):
        if self.token:
            self.login_frame.pack_forget()
            self.linked_frame.pack(fill="x", after=self.name_label)
        else:
            self.linked_frame.pack_forget()
            self.login_frame.pack(fill="x", after=self.name_label)

    def _reload_printers(self):
        available = printers()
        self.printer_selector["values"] = available
        if not self.printer_var.get() and available:
            self.printer_var.set(available[0])
            self._save_preferences()

    def _save_preferences(self):
        self.preferences.set(
            automatico=self.mode_var.get() == "auto",
            impressora=self.printer_var.get(),
            papel=int(self.paper_var.get()),
            iniciar_windows=bool(self.start_var.get()),
        )
        self._startup(bool(self.start_var.get()))

    def _startup(self, enabled):
        try:
            keypath = r"Software\Microsoft\Windows\CurrentVersion\Run"
            with winreg.CreateKey(winreg.HKEY_CURRENT_USER, keypath) as key:
                if enabled:
                    if getattr(sys, "frozen", False):
                        winreg.SetValueEx(key, "DeliveryzimAgent", 0, winreg.REG_SZ, '"' + sys.executable + '"')
                    else:
                        # Nao configurar o Python do desenvolvedor como inicializacao de producao.
                        return
                else:
                    try:
                        winreg.DeleteValue(key, "DeliveryzimAgent")
                    except FileNotFoundError:
                        pass
        except OSError as exc:
            self.status_var.set("Falha ao configurar inicializacao: " + str(exc))

    def _login(self):
        username = self.username.get().strip()
        password = self.password.get()
        if not username or not password:
            messagebox.showwarning("Login", "Informe usuario e senha.")
            return
        self.login_button.config(state="disabled")
        self.status_var.set("Validando login...")
        self.authentication = ApiClient()
        threading.Thread(target=self._login_task, args=(username, password), daemon=True).start()

    def _login_task(self, username, password):
        try:
            result = self.authentication.login(username, password)
            self.messages.put(("linked", result))
        except NeedTwoFactor:
            self.messages.put(("need_2fa", None))
        except Exception as exc:
            self.messages.put(("login_error", str(exc)))

    def _verify(self):
        code = self.code.get().strip()
        if not code:
            messagebox.showwarning("Verificacao", "Digite o codigo de verificacao.")
            return
        self.login_button.config(state="disabled")
        threading.Thread(target=self._verify_task, args=(code,), daemon=True).start()

    def _verify_task(self, code):
        try:
            result = self.authentication.verify(code)
            self.messages.put(("linked", result))
        except Exception as exc:
            self.messages.put(("login_error", str(exc)))

    def _start_worker(self):
        if self.worker and self.worker.is_alive():
            return
        if not self.token:
            return
        self.client = ApiClient()
        self.client.token = self.token
        self.worker = threading.Thread(target=self._poll, args=(self.client,), daemon=True,
                                       name="deliveryzim-pedidos")
        self.worker.start()

    def _poll(self, client):
        delay = 2.5
        while not self.quit_flag.is_set() and self.client is client:
            try:
                cursor = int(self.preferences.get("cursor") or 0)
                response = client.events(cursor)
                orders = response.get("pedidos")
                if not isinstance(orders, list):
                    raise ValueError("Resposta da API invalida.")
                for order in orders:
                    if not isinstance(order, dict) or not isinstance(order.get("evento_id"), int):
                        continue
                    if self.store.receive(order):
                        self.messages.put(("new", order))
                new_cursor = int(response.get("cursor", cursor))
                if new_cursor >= cursor:
                    self.preferences.set(cursor=new_cursor)
                self.messages.put(("connection", "Conectado - aguardando pedidos"))
                delay = 2.5
            except AuthenticationError as exc:
                self.messages.put(("auth_expired", str(exc)))
                return
            except Exception as exc:
                self.messages.put(("connection", "Sem conexao: " + str(exc)[:120]))
                delay = min(30, delay * 1.7)
            self.quit_flag.wait(delay)

    def _recover_jobs(self):
        for order, status in self.store.pending():
            if status == "imprimindo":
                self._prompt(order, warning="Envio anterior indefinido. Verifique a fila do Windows antes de reimprimir.")
            elif status == "erro":
                self._prompt(order, warning="Impressao anterior falhou. Confira a impressora.")
            elif self.mode_var.get() == "auto":
                self._print(order)
            else:
                self._prompt(order)

    def _test(self):
        moment = int(time.time() * 1000)
        order = {
            "evento_id": -moment, "id": 0, "numero": "TESTE", "tipo": "retirada",
            "cliente": "Cliente de teste", "criado_em": time.strftime("%d/%m/%Y %H:%M"),
            "itens": [{"nome": "X-Bacon", "quantidade": "2", "total": "40.00",
                       "observacao": "Sem cebola"},
                      {"nome": "Refrigerante", "quantidade": "1", "total": "6.00"}],
            "pagamento": "dinheiro", "total": "46.00",
            "observacao": "Cupom simulado - nao registra venda."
        }
        self.store.receive(order)
        self._new_order(order)

    def _new_order(self, order):
        self._ring()
        if self.mode_var.get() == "auto":
            self._print(order)
        else:
            self._prompt(order)

    def _ring(self):
        try:
            winsound.PlaySound("SystemExclamation", winsound.SND_ALIAS | winsound.SND_ASYNC)
        except RuntimeError:
            winsound.MessageBeep(winsound.MB_ICONEXCLAMATION)

    def _remind(self):
        if any(w.winfo_exists() for w in self.dialogs.values()):
            self._ring()
        self.root.after(20000, self._remind)

    def _prompt(self, order, warning=""):
        event_id = int(order["evento_id"])
        if event_id in self.dialogs and self.dialogs[event_id].winfo_exists():
            self.dialogs[event_id].lift()
            return
        dialog = tk.Toplevel(self.root)
        self.dialogs[event_id] = dialog
        dialog.title("Novo pedido - Deliveryzim")
        dialog.configure(bg=WHITE)
        dialog.geometry("420x295")
        dialog.attributes("-topmost", True)
        dialog.lift()
        dialog.protocol("WM_DELETE_WINDOW", lambda: None)
        tk.Label(dialog, text="PEDIDO NOVO", bg=WINE, fg=WHITE,
                 font=("Segoe UI", 15, "bold"), padx=14, pady=12).pack(fill="x")
        content = tk.Frame(dialog, bg=WHITE, padx=18, pady=12)
        content.pack(fill="both", expand=True)
        self._label(content, "Pedido #" + str(order.get("numero", "")), 15, WINE, True).pack(anchor="w")
        self._label(content, str(order.get("cliente") or "Cliente nao informado"), 11).pack(anchor="w", pady=4)
        self._label(content, "Itens: " + str(len(order.get("itens") or [])), 10).pack(anchor="w")
        if warning:
            self._label(content, warning, 9, ORANGE, True).pack(anchor="w", pady=10)
        controls = tk.Frame(content, bg=WHITE)
        controls.pack(fill="x", pady=(18, 0))
        ttk.Button(controls, text="Receber e imprimir",
                   command=lambda: self._choose(order, True)).pack(fill="x", pady=4)
        ttk.Button(controls, text="Somente receber",
                   command=lambda: self._choose(order, False)).pack(fill="x", pady=4)
        self._label(content, "Receber confirma o aviso neste computador; nao altera status do pedido.",
                    8, GRAY).pack(anchor="w", pady=5)

    def _choose(self, order, do_print):
        event_id = int(order["evento_id"])
        window = self.dialogs.pop(event_id, None)
        if window and window.winfo_exists():
            window.destroy()
        if do_print:
            self._print(order)
        else:
            self.store.update(event_id, "recebido")
            self.status_var.set("Pedido #" + str(order.get("numero")) + " recebido sem imprimir.")

    def _print(self, order):
        event_id = int(order["evento_id"])
        self.store.update(event_id, "imprimindo")
        target = self.printer_var.get()
        paper = int(self.paper_var.get())
        threading.Thread(target=self._print_task, args=(order, target, paper), daemon=True).start()

    def _print_task(self, order, printer_name, paper):
        try:
            payload = receipt(order, paper)
            spool(printer_name, payload, "Deliveryzim pedido #" + str(order.get("numero", "")))
            self.messages.put(("printed", order))
        except Exception as exc:
            self.messages.put(("print_error", (order, str(exc))))

    def _unlink(self):
        if not messagebox.askyesno("Desvincular", "Desvincular este dispositivo do restaurante?"):
            return
        old_client = self.client
        self.client = None
        self.token = ""
        self.preferences.set(cursor=0, restaurante="", device_id=0)
        self.name_var.set("")
        self.store.clear_on_relink()
        delete_credential()
        self.status_var.set("Desvinculado")
        self._show_link_state()
        if old_client:
            threading.Thread(target=lambda: self._safe_revoke(old_client), daemon=True).start()

    @staticmethod
    def _safe_revoke(client):
        try:
            client.revoke()
        except Exception:
            pass

    def _drain(self):
        while True:
            try:
                kind, payload = self.messages.get_nowait()
            except queue.Empty:
                break
            if kind == "need_2fa":
                self.waiting_code = True
                self.code.pack(fill="x", pady=4, before=self.login_button)
                self.login_button.config(text="Confirmar codigo", command=self._verify, state="normal")
                self.password.delete(0, "end")
                self.status_var.set("Digite o codigo da verificacao em duas etapas.")
            elif kind == "login_error":
                self.login_button.config(state="normal")
                self.status_var.set(str(payload)[:150])
                messagebox.showerror("Login", str(payload))
            elif kind == "linked":
                self.password.delete(0, "end")
                self.code.delete(0, "end")
                try:
                    save_credential(payload["token"])
                    self.token = payload["token"]
                    self.preferences.set(cursor=int(payload["cursor"]),
                                         restaurante=str(payload.get("restaurante") or ""),
                                         device_id=int(payload.get("device_id") or 0))
                    self.name_var.set(str(payload.get("restaurante") or ""))
                    self.status_var.set("Conectado - aguardando pedidos")
                    self._show_link_state()
                    self._start_worker()
                except Exception as exc:
                    messagebox.showerror("Credencial", "Nao foi possivel salvar a credencial: " + str(exc))
            elif kind == "connection":
                if self.token:
                    self.status_var.set(payload)
            elif kind == "auth_expired":
                self.status_var.set("Precisa vincular novamente: " + payload)
                self.token = ""
                self.client = None
                delete_credential()
                self._show_link_state()
            elif kind == "new":
                self._new_order(payload)
            elif kind == "printed":
                self.store.update(payload["evento_id"], "enviado_ao_windows")
                self.status_var.set("Pedido #" + str(payload.get("numero")) + " enviado ao Windows.")
            elif kind == "print_error":
                order, reason = payload
                self.store.update(order["evento_id"], "erro")
                self.status_var.set("Falha de impressao: " + reason[:130])
                self._prompt(order, warning="Falha de impressao: " + reason[:100])
            elif kind == "show":
                self.root.deiconify()
                self.root.lift()
            elif kind == "quit":
                self._quit()
        self.root.after(150, self._drain)

    def hide(self):
        if self.icon is not None:
            self.root.withdraw()
        else:
            self.root.iconify()

    def _quit(self):
        if not messagebox.askyesno("Encerrar", "Encerrar o agente? Pedidos nao serao avisados enquanto ele estiver fechado."):
            return
        self.quit_flag.set()
        self.client = None
        if self.icon is not None:
            self.icon.stop()
        self.root.destroy()

    def _icon_image(self):
        if getattr(sys, "frozen", False):
            path = os.path.join(sys._MEIPASS, "agent", "assets", "icon-192.png")
        else:
            path = os.path.join(os.path.dirname(__file__), "assets", "icon-192.png")
        return Image.open(path).convert("RGBA")

    def _start_tray(self):
        try:
            image = self._icon_image()
            self.icon = pystray.Icon(
                "Deliveryzim Agent", image, "Deliveryzim Agent",
                menu=pystray.Menu(
                    pystray.MenuItem("Abrir configuracoes", lambda *_: self.messages.put(("show", None)), default=True),
                    pystray.MenuItem("Encerrar agente", lambda *_: self.messages.put(("quit", None))),
                ),
            )
            threading.Thread(target=self.icon.run, daemon=True).start()
        except Exception:
            self.icon = None

    def run(self):
        self._start_tray()
        self.root.mainloop()
