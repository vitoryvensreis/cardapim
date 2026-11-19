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
from PIL import Image, ImageTk

from agent.api import ApiClient, AuthenticationError, NeedTwoFactor
from agent.config import Preferences, EventStore, save_credential, load_credential, delete_credential
from agent.printing import printers, receipt, spool

WINE = "#651424"
ORANGE = "#FF5825"
WHITE = "#FFFFFF"
DARK = "#272727"
GRAY = "#6C6770"
BACKGROUND = "#F7F5F5"
BORDER = "#E8E3E4"
SUBTLE = "#F8F4F4"


class DeliveryzimApp:
    def __init__(self):
        self.root = tk.Tk()
        self.root.title("Deliveryzim Agent")
        self.root.geometry("580x730")
        self.root.resizable(False, False)
        self.root.minsize(580, 730)
        self.root.maxsize(580, 730)
        self.root.configure(bg=BACKGROUND)
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

    def _label(self, parent, text, size=10, color=DARK, bold=False, wrap=0):
        return tk.Label(
            parent, text=text, font=("Segoe UI", size, "bold" if bold else "normal"),
            bg=parent.cget("bg"), fg=color, anchor="w", justify="left",
            wraplength=wrap or 0,
        )

    def _button(self, parent, text, command, primary=True, **options):
        color = WINE if primary else WHITE
        foreground = WHITE if primary else WINE
        button = tk.Button(
            parent, text=text, command=command,
            font=("Segoe UI", 10, "bold"), bg=color, fg=foreground,
            activebackground=ORANGE if primary else SUBTLE,
            activeforeground=WHITE if primary else WINE,
            relief="flat", bd=0, cursor="hand2",
            padx=18, pady=11, highlightthickness=1,
            highlightbackground=WINE if primary else BORDER,
            highlightcolor=ORANGE, takefocus=True,
            **options,
        )
        return button

    def _render(self):
        self.style = ttk.Style(self.root)
        self.style.theme_use("clam")
        self.style.configure(
            "Agent.TEntry", fieldbackground=WHITE, foreground=DARK,
            bordercolor=BORDER, lightcolor=BORDER, darkcolor=BORDER,
            insertcolor=DARK, relief="flat", padding=(12, 9),
            font=("Segoe UI", 11),
        )
        self.style.map("Agent.TEntry", bordercolor=[("focus", ORANGE)])
        self.style.configure(
            "Agent.TCombobox", fieldbackground=WHITE, background=WHITE,
            foreground=DARK, arrowcolor=WINE, bordercolor=BORDER,
            lightcolor=BORDER, darkcolor=BORDER, padding=(12, 8),
            font=("Segoe UI", 10),
        )
        self.style.map(
            "Agent.TCombobox",
            bordercolor=[("focus", ORANGE)],
            fieldbackground=[("readonly", WHITE)],
            selectbackground=[("readonly", WHITE)],
            selectforeground=[("readonly", DARK)],
        )
        self.root.option_add("*TCombobox*Listbox.font", ("Segoe UI", 10))
        self.root.option_add("*TCombobox*Listbox.background", WHITE)

        header = tk.Frame(self.root, bg=WINE, padx=30, pady=18, height=138)
        header.pack(fill="x")
        header.pack_propagate(False)
        brand = tk.Frame(header, bg=WINE)
        brand.pack(fill="x", anchor="w")
        try:
            original = self._icon_image()
            self.brand_photo = ImageTk.PhotoImage(original.resize((52, 52), Image.Resampling.LANCZOS))
            tk.Label(brand, image=self.brand_photo, bg=WINE, bd=0).pack(side="left", padx=(0, 13))
        except Exception:
            self.brand_photo = None
        title = tk.Frame(brand, bg=WINE)
        title.pack(side="left", anchor="center")
        tk.Label(
            title, text="Deliveryzim", bg=WINE, fg=WHITE,
            font=("Segoe UI", 22, "bold"),
        ).pack(anchor="w")
        tk.Label(
            title, text="AGENT  /  WINDOWS", bg=WINE, fg="#F9C9D1",
            font=("Segoe UI", 9, "bold"),
        ).pack(anchor="w", pady=(0, 1))
        tk.Label(
            header, text="Pedidos em tempo real, sem complicação.", bg=WINE,
            fg="#F6EAED", font=("Segoe UI", 10),
        ).pack(anchor="w", pady=(11, 0))
        tk.Frame(self.root, bg=ORANGE, height=4).pack(fill="x")

        self.screen = tk.Frame(self.root, bg=BACKGROUND)
        self.screen.pack(fill="both", expand=True)
        self.login_page = tk.Frame(self.screen, bg=BACKGROUND, padx=34, pady=25)
        self.settings_page = tk.Frame(self.screen, bg=BACKGROUND, padx=27, pady=16)

        # Etapa 1: a interface de operacao so fica visivel com vinculo aprovado.
        self._label(self.login_page, "ETAPA 01  /  ACESSO SEGURO", 9, WINE, True).pack(anchor="w")
        self._label(self.login_page, "Conecte seu restaurante", 19, DARK, True).pack(anchor="w", pady=(7, 4))
        self._label(
            self.login_page, "Use a conta de gerente ou caixa cadastrada no Deliveryzim.",
            10, GRAY, wrap=500,
        ).pack(anchor="w")

        card = tk.Frame(
            self.login_page, bg=WHITE, padx=24, pady=22,
            highlightbackground=BORDER, highlightthickness=1,
        )
        card.pack(fill="x", pady=(22, 0))

        self._label(card, "E-mail ou usuário", 10, DARK, True).pack(anchor="w", pady=(0, 7))
        self.username = ttk.Entry(card, style="Agent.TEntry", font=("Segoe UI", 11))
        self.username.pack(fill="x", ipady=3)
        self._label(card, "Senha", 10, DARK, True).pack(anchor="w", pady=(17, 7))
        self.password = ttk.Entry(card, show="●", style="Agent.TEntry", font=("Segoe UI", 11))
        self.password.pack(fill="x", ipady=3)

        self.code_holder = tk.Frame(card, bg=WHITE)
        self._label(self.code_holder, "Código de verificação", 10, DARK, True).pack(anchor="w", pady=(0, 7))
        self.code = ttk.Entry(self.code_holder, style="Agent.TEntry", font=("Segoe UI", 11))
        self.code.pack(fill="x", ipady=3)

        self.login_feedback = tk.StringVar(value="")
        self.login_feedback_label = tk.Label(
            card, textvariable=self.login_feedback, fg=WINE, bg=WHITE,
            font=("Segoe UI", 9), wraplength=438, justify="left", anchor="w",
        )
        self.login_feedback_label.pack(fill="x", pady=(15, 1))
        self.login_button = self._button(card, "Entrar e conectar computador", self._login)
        self.login_button.pack(fill="x", pady=(7, 0), ipady=4)

        info = tk.Frame(self.login_page, bg=SUBTLE, padx=15, pady=13,
                        highlightbackground=BORDER, highlightthickness=1)
        info.pack(fill="x", pady=(17, 0))
        self._label(info, "CONEXÃO PROTEGIDA", 9, WINE, True).pack(anchor="w")
        self._label(
            info, "A senha não é armazenada neste computador. O vínculo é exclusivo do restaurante.",
            9, GRAY, wrap=460,
        ).pack(anchor="w", pady=(3, 0))

        # Etapa 2: configuracoes do equipamento, carregada apos vinculo.
        heading = tk.Frame(self.settings_page, bg=BACKGROUND)
        heading.pack(fill="x")
        self._label(heading, "ETAPA 02  /  CONFIGURAÇÃO", 9, WINE, True).pack(anchor="w")
        self._label(heading, "Central de impressão", 19, DARK, True).pack(anchor="w", pady=(3, 0))

        statecard = tk.Frame(
            self.settings_page, bg=WHITE, padx=16, pady=12,
            highlightbackground=BORDER, highlightthickness=1,
        )
        statecard.pack(fill="x", pady=(15, 11))
        self.name_label = tk.Label(
            statecard, textvariable=self.name_var, bg=WHITE, fg=WINE,
            font=("Segoe UI", 11, "bold"), anchor="w",
        )
        self.name_label.pack(fill="x")
        self.status_label = tk.Label(
            statecard, textvariable=self.status_var, bg=WHITE, fg=GRAY,
            font=("Segoe UI", 9), anchor="w", justify="left", wraplength=472,
        )
        self.status_label.pack(fill="x", pady=(4, 0))

        modecard = tk.Frame(
            self.settings_page, bg=WHITE, padx=16, pady=14,
            highlightbackground=BORDER, highlightthickness=1,
        )
        modecard.pack(fill="x", pady=(0, 11))
        self._label(modecard, "COMO RECEBER PEDIDOS", 9, WINE, True).pack(anchor="w")
        modes = tk.Frame(modecard, bg=WHITE)
        modes.pack(fill="x", pady=(11, 0))
        self.mode_buttons = {}
        for key, title, detail in (
            ("auto", "Automático", "Imprime ao receber"),
            ("manual", "Com confirmação", "Pergunta antes de imprimir"),
        ):
            container = tk.Frame(modes, bg=WHITE)
            container.pack(side="left", fill="x", expand=True, padx=(0, 5) if key == "auto" else (5, 0))
            button = tk.Button(
                container, text=title + "\n" + detail,
                command=lambda chosen=key: self._select_mode(chosen),
                font=("Segoe UI", 10, "bold"),
                bg=WHITE, fg=DARK, justify="center", cursor="hand2",
                relief="flat", highlightthickness=2, bd=0, pady=11,
                activeforeground=WINE, activebackground=SUBTLE,
            )
            button.pack(fill="both", expand=True)
            self.mode_buttons[key] = button
        self._paint_mode_buttons()
        self._label(
            modecard, "O alerta sonoro toca sempre, inclusive no modo automático.",
            9, GRAY, wrap=476,
        ).pack(anchor="w", pady=(12, 0))

        printcard = tk.Frame(
            self.settings_page, bg=WHITE, padx=16, pady=14,
            highlightbackground=BORDER, highlightthickness=1,
        )
        printcard.pack(fill="x", pady=(0, 11))
        row = tk.Frame(printcard, bg=WHITE)
        row.pack(fill="x")
        self._label(row, "IMPRESSORA DO WINDOWS", 9, WINE, True).pack(side="left")
        self._button(row, "Atualizar", self._reload_printers, primary=False, padx=9, pady=4).pack(side="right")
        self.printer_selector = ttk.Combobox(
            printcard, textvariable=self.printer_var,
            state="readonly", style="Agent.TCombobox", font=("Segoe UI", 10),
        )
        self.printer_selector.pack(fill="x", pady=(10, 0), ipady=3)
        self.printer_selector.bind("<<ComboboxSelected>>", lambda _: self._save_preferences())
        self.printer_hint = tk.Label(
            printcard, text="", bg=WHITE, fg=GRAY, justify="left",
            anchor="w", font=("Segoe UI", 9), wraplength=466,
        )
        self.printer_hint.pack(fill="x", pady=(6, 0))
        paper = tk.Frame(printcard, bg=WHITE)
        paper.pack(fill="x", pady=(8, 0))
        self._label(paper, "Largura do papel", 9, DARK, True).pack(side="left")
        for value in ("58", "80"):
            tk.Radiobutton(
                paper, text=value + " mm", variable=self.paper_var, value=value,
                command=self._save_preferences, bg=WHITE, fg=DARK,
                font=("Segoe UI", 9), activebackground=WHITE, selectcolor=WHITE,
            ).pack(side="right", padx=(12, 0))

        foot = tk.Frame(self.settings_page, bg=BACKGROUND)
        foot.pack(fill="x")
        tk.Checkbutton(
            foot, text="Iniciar com o Windows", variable=self.start_var,
            command=self._save_preferences, bg=BACKGROUND, fg=DARK,
            activebackground=BACKGROUND, selectcolor=BACKGROUND,
            font=("Segoe UI", 10),
        ).pack(anchor="w", pady=(1, 8))
        self._button(
            foot, "Testar alerta e impressão", self._test, primary=True,
        ).pack(fill="x", ipady=3)
        footer = tk.Frame(foot, bg=BACKGROUND)
        footer.pack(fill="x", pady=(10, 0))
        self._button(footer, "Desvincular", self._unlink, primary=False, padx=12, pady=7).pack(side="left")
        self._button(
            footer, "Abrir Deliveryzim",
            lambda: webbrowser.open("https://deliveryzim.com.br"),
            primary=False, padx=12, pady=7,
        ).pack(side="right")
        self._show_link_state()

    def _select_mode(self, mode):
        self.mode_var.set(mode)
        self._paint_mode_buttons()
        self._save_preferences()

    def _paint_mode_buttons(self):
        for mode, widget in self.mode_buttons.items():
            selected = mode == self.mode_var.get()
            widget.config(
                bg=SUBTLE if selected else WHITE,
                fg=WINE if selected else GRAY,
                activebackground=SUBTLE if selected else WHITE,
                highlightbackground=ORANGE if selected else BORDER,
            )

    def _show_link_state(self):
        if self.token:
            self.login_page.pack_forget()
            self.settings_page.pack(fill="both", expand=True)
            self._reload_printers()
        else:
            self.settings_page.pack_forget()
            self.login_page.pack(fill="both", expand=True)
            self.root.after(50, self.username.focus_set)

    def _reload_printers(self):
        try:
            available = printers()
            self.printer_selector["values"] = available
            selected = self.printer_var.get()
            if selected not in available:
                self.printer_var.set(available[0] if available else "")
                if available:
                    self._save_preferences()
            if available:
                self.printer_hint.config(
                    text=str(len(available)) + " impressora(s) disponível(is). Escolha onde enviar os cupons.",
                    fg=GRAY,
                )
            else:
                self.printer_hint.config(
                    text="Nenhuma impressora encontrada. Instale uma nas configurações do Windows e clique em Atualizar.",
                    fg=WINE,
                )
        except Exception as exc:
            self.printer_selector["values"] = []
            self.printer_hint.config(text=str(exc)[:230], fg=WINE)

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
        if not self.token:
            return
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
        for dialog in list(self.dialogs.values()):
            if dialog.winfo_exists():
                dialog.destroy()
        self.dialogs.clear()
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
                    previous = int(self.preferences.get("device_id") or 0)
                    new_device = int(payload.get("device_id") or 0)
                    if previous and previous != new_device:
                        self.store.clear_on_relink()
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
