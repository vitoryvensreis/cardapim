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
import ctypes
import pystray
from PIL import Image, ImageTk

from agent.api import ApiClient, AuthenticationError, NeedTwoFactor
from agent.config import Preferences, EventStore, save_credential, load_credential, delete_credential
from agent.printing import printers, receipt, spool
from agent.scale import ScaleMonitor, ScaleSettings
from agent.scale_panel import ScalePanel
from agent.sound import play as play_order_sound

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
        # AppUserModelID mantém a identidade do EXE correta na barra de tarefas.
        try:
            ctypes.windll.shell32.SetCurrentProcessExplicitAppUserModelID("Deliveryzim.Agent.Windows")
        except (AttributeError, OSError):
            pass
        self.root = tk.Tk()
        self.root.title("Deliveryzim Agent")
        self.root.geometry("650x790")
        self.root.resizable(True, True)
        self.root.minsize(560, 610)
        try:
            self.window_icon_photo = ImageTk.PhotoImage(self._icon_image().resize((64, 64)))
            self.root.iconphoto(True, self.window_icon_photo)
        except (OSError, RuntimeError):
            pass
        self.root.configure(bg=BACKGROUND)
        self.root.protocol("WM_DELETE_WINDOW", self.hide)
        self.preferences = Preferences()
        self.store = EventStore()
        self.scale_monitor = ScaleMonitor()
        self.scale_panel = None
        self.scale_sync_thread = None
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
        self.sound_var = tk.BooleanVar(value=bool(self.preferences.get("som_ativo")))
        self.status_var = tk.StringVar(value="Conectando..." if self.token else "Nao vinculado")
        self.scale_sync_status = tk.StringVar(value="PDV: aguardando ativação")
        self.name_var = tk.StringVar(value=self.preferences.get("restaurante") or "")
        self._render()
        self._start_scale_if_configured()
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
        defaults = dict(
            font=("Segoe UI", 10, "bold"), bg=color, fg=foreground,
            activebackground=ORANGE if primary else SUBTLE,
            activeforeground=WHITE if primary else WINE,
            relief="flat", bd=0, cursor="hand2",
            padx=18, pady=11, highlightthickness=1,
            highlightbackground=WINE if primary else BORDER,
            highlightcolor=ORANGE, takefocus=True,
        )
        defaults.update(options)
        return tk.Button(parent, text=text, command=command, **defaults)

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
            original = self._logo_image()
            self.brand_photo = ImageTk.PhotoImage(original.resize((245, 58), Image.Resampling.LANCZOS))
            tk.Label(brand, image=self.brand_photo, bg=WINE, bd=0).pack(side="left", padx=(0, 13))
        except Exception:
            self.brand_photo = None
        title = tk.Frame(brand, bg=WINE)
        title.pack(side="left", anchor="center")
        tk.Label(title, text="AGENT  /  WINDOWS", bg=WINE, fg="#F9C9D1",
                 font=("Segoe UI", 10, "bold")).pack(anchor="w")
        tk.Label(
            header, text="Pedidos em tempo real, sem complicação.", bg=WINE,
            fg="#F6EAED", font=("Segoe UI", 10),
        ).pack(anchor="w", pady=(11, 0))
        tk.Frame(self.root, bg=ORANGE, height=4).pack(fill="x")

        self.screen = tk.Frame(self.root, bg=BACKGROUND)
        self.screen.pack(fill="both", expand=True)
        self.login_page = tk.Frame(self.screen, bg=BACKGROUND, padx=34, pady=25)
        self.settings_scroll = tk.Frame(self.screen, bg=BACKGROUND)
        self.settings_canvas = tk.Canvas(self.settings_scroll, bg=BACKGROUND, bd=0,
                                         highlightthickness=0)
        self.settings_scrollbar = ttk.Scrollbar(self.settings_scroll, orient="vertical",
                                                command=self.settings_canvas.yview)
        self.settings_canvas.configure(yscrollcommand=self.settings_scrollbar.set)
        self.settings_canvas.pack(side="left", fill="both", expand=True)
        self.settings_scrollbar.pack(side="right", fill="y")
        self.settings_page = tk.Frame(self.settings_canvas, bg=BACKGROUND, padx=27, pady=16)
        self.settings_window = self.settings_canvas.create_window((0, 0), window=self.settings_page,
                                                                   anchor="nw")
        self.settings_page.bind("<Configure>", lambda _: self.settings_canvas.configure(
            scrollregion=self.settings_canvas.bbox("all")))
        self.settings_canvas.bind("<Configure>", lambda e: self.settings_canvas.itemconfigure(
            self.settings_window, width=e.width))
        self.root.bind_all("<MouseWheel>", self._scroll_settings)

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

        # Configuração de impressora, balança e alertas na MESMA página pós-login.
        heading = tk.Frame(self.settings_page, bg=BACKGROUND)
        heading.pack(fill="x")
        self._label(heading, "CONFIGURAÇÃO DO COMPUTADOR", 9, WINE, True).pack(anchor="w")
        self._label(heading, "Seu ponto de atendimento", 19, DARK, True).pack(anchor="w", pady=(4, 3))
        self._label(heading, "Configure uma vez. Depois, o Agent continua na bandeja do Windows.",
                    9, GRAY, wrap=540).pack(anchor="w")

        statecard = tk.Frame(self.settings_page, bg=WHITE, padx=17, pady=13,
                            highlightbackground=BORDER, highlightthickness=1)
        statecard.pack(fill="x", pady=(14, 10))
        self.name_label = tk.Label(statecard, textvariable=self.name_var, bg=WHITE, fg=WINE,
                                   font=("Segoe UI", 12, "bold"), anchor="w")
        self.name_label.pack(fill="x")
        self.status_label = tk.Label(statecard, textvariable=self.status_var, bg=WHITE, fg=GRAY,
                                    font=("Segoe UI", 9), anchor="w", justify="left", wraplength=520)
        self.status_label.pack(fill="x", pady=(4, 0))

        printcard = tk.Frame(self.settings_page, bg=WHITE, padx=17, pady=15,
                             highlightbackground=BORDER, highlightthickness=1)
        printcard.pack(fill="x", pady=(0, 10))
        row = tk.Frame(printcard, bg=WHITE)
        row.pack(fill="x")
        self._label(row, "01  /  IMPRESSORA", 10, WINE, True).pack(side="left")
        self._button(row, "Atualizar lista", self._reload_printers, primary=False,
                     padx=9, pady=5).pack(side="right")
        self._label(printcard, "Escolha a impressora que receberá os pedidos.",
                    9, GRAY).pack(anchor="w", pady=(5, 11))
        self.printer_selector = ttk.Combobox(printcard, textvariable=self.printer_var,
                                             state="readonly", style="Agent.TCombobox",
                                             font=("Segoe UI", 10))
        self.printer_selector.pack(fill="x", ipady=3)
        self.printer_selector.bind("<<ComboboxSelected>>", lambda _: self._save_preferences())
        self.printer_hint = tk.Label(printcard, text="", bg=WHITE, fg=GRAY, justify="left",
                                     anchor="w", font=("Segoe UI", 9), wraplength=520)
        self.printer_hint.pack(fill="x", pady=(6, 9))
        paper = tk.Frame(printcard, bg=WHITE)
        paper.pack(fill="x")
        self._label(paper, "Tamanho do papel", 9, DARK, True).pack(side="left")
        for value in ("58", "80"):
            tk.Radiobutton(paper, text=value + " mm", variable=self.paper_var, value=value,
                           command=self._save_preferences, bg=WHITE, fg=DARK,
                           activebackground=WHITE, selectcolor=WHITE,
                           font=("Segoe UI", 9)).pack(side="right", padx=(12, 0))

        balcard = tk.Frame(self.settings_page, bg=WHITE, padx=17, pady=15,
                           highlightbackground=BORDER, highlightthickness=1)
        balcard.pack(fill="x", pady=(0, 10))
        self.scale_panel = ScalePanel(balcard, self.preferences, self.scale_monitor,
                                      self.scale_sync_status)
        self.scale_panel.frame.pack(fill="x")

        alertcard = tk.Frame(self.settings_page, bg=WHITE, padx=17, pady=14,
                             highlightbackground=BORDER, highlightthickness=1)
        alertcard.pack(fill="x", pady=(0, 11))
        self._label(alertcard, "03  /  NOVOS PEDIDOS", 10, WINE, True).pack(anchor="w")
        tk.Checkbutton(alertcard, text="Tocar alerta sonoro quando chegar um pedido",
                       variable=self.sound_var, command=self._save_preferences,
                       bg=WHITE, fg=DARK, activebackground=WHITE, selectcolor=WHITE,
                       font=("Segoe UI", 10), anchor="w").pack(fill="x", pady=(10, 0))
        self._button(alertcard, "Ouvir som de teste", self._test_sound,
                     primary=False, padx=10, pady=6).pack(anchor="w", pady=(6, 11))
        self._label(alertcard, "Impressão de pedidos", 9, DARK, True).pack(anchor="w")
        modes = tk.Frame(alertcard, bg=WHITE)
        modes.pack(fill="x", pady=(8, 0))
        self.mode_buttons = {}
        for key, title, detail in (
            ("auto", "Automática", "Imprime ao receber"),
            ("manual", "Com confirmação", "Pergunta antes de imprimir"),
        ):
            button = tk.Button(modes, text=title + "\n" + detail,
                               command=lambda chosen=key: self._select_mode(chosen),
                               font=("Segoe UI", 9, "bold"), bg=WHITE, fg=DARK,
                               justify="center", cursor="hand2", relief="flat",
                               highlightthickness=2, bd=0, pady=9,
                               activeforeground=WINE, activebackground=SUBTLE)
            button.pack(side="left", fill="x", expand=True,
                        padx=(0, 6) if key == "auto" else (6, 0))
            self.mode_buttons[key] = button
        self._paint_mode_buttons()

        foot = tk.Frame(self.settings_page, bg=BACKGROUND)
        foot.pack(fill="x", pady=(2, 15))
        tk.Checkbutton(foot, text="Iniciar automaticamente com o Windows",
                       variable=self.start_var, command=self._save_preferences,
                       bg=BACKGROUND, fg=DARK, activebackground=BACKGROUND,
                       selectcolor=BACKGROUND, font=("Segoe UI", 10)).pack(anchor="w", pady=(0, 10))
        self._button(foot, "Testar impressão e alerta", self._test,
                     primary=False).pack(fill="x", pady=(0, 9))
        self._button(foot, "Concluir e deixar em segundo plano",
                     self._finish_setup, primary=True).pack(fill="x", ipady=4)
        self._label(foot, "O Agent continuará na área de notificação ao lado do relógio.",
                    9, GRAY).pack(anchor="w", pady=(7, 10))
        footer = tk.Frame(foot, bg=BACKGROUND)
        footer.pack(fill="x")
        self._button(footer, "Desvincular", self._unlink,
                     primary=False, padx=12, pady=7).pack(side="left")
        self._button(footer, "Abrir Deliveryzim", self._open_deliveryzim,
                     primary=False, padx=12, pady=7).pack(side="right")
        self._show_link_state()

    def _open_deliveryzim(self):
        # A configuracao permanece no Agent. No caixa, nao se escolhe dispositivo.
        if not self.token or not self.preferences.get("balanca_ativa"):
            webbrowser.open("https://deliveryzim.com.br")
            return
        self.status_var.set("Vinculando a balança a este navegador...")
        threading.Thread(target=self._open_deliveryzim_task, daemon=True).start()

    def _open_deliveryzim_task(self):
        try:
            client = ApiClient()
            client.token = self.token
            self.messages.put(("open_pos", client.browser_pairing()))
        except Exception as exc:
            self.messages.put(("pair_error", str(exc)))

    def _start_scale_if_configured(self):
        if not self.token or not self.preferences.get("balanca_ativa"):
            return
        try:
            self.scale_monitor.configure(ScaleSettings(
                port=str(self.preferences.get("balanca_porta") or ""),
                baudrate=int(self.preferences.get("balanca_baudrate") or 9600),
                protocol=str(self.preferences.get("balanca_protocolo") or "continuo"),
                unit=str(self.preferences.get("balanca_unidade") or "kg"),
            ))
        except (ValueError, OSError):
            # Mantém o agente de pedidos operacional mesmo se a balança falhar.
            self.scale_monitor.stop()

    def _scroll_settings(self, event):
        if self.token and self.settings_scroll.winfo_ismapped():
            self.settings_canvas.yview_scroll(-int(event.delta / 120), "units")

    def _test_sound(self):
        if not play_order_sound():
            messagebox.showwarning("Alerta de pedidos",
                                   "O som não pôde ser reproduzido. Verifique a saída de áudio do Windows.")

    def _finish_setup(self):
        self._save_preferences()
        if self.icon is None:
            messagebox.showwarning("Área de notificação",
                                   "A bandeja do Windows não está disponível. Mantenha o Agent aberto.")
            return
        if self.mode_var.get() == "auto" and not self.printer_var.get():
            messagebox.showwarning("Impressora", "Escolha uma impressora ou use o modo com confirmação.")
            return
        self.preferences.set(configurado=True)
        self.status_var.set("Configurado. Monitorando novos pedidos em segundo plano.")
        if self.icon:
            try:
                self.icon.notify("Pedidos e alertas ativos.", "Deliveryzim Agent")
            except (NotImplementedError, RuntimeError, OSError):
                pass
        self.hide()

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
            self.settings_scroll.pack(fill="both", expand=True)
            self._reload_printers()
        else:
            self.settings_scroll.pack_forget()
            self.login_page.pack(fill="both", expand=True)
            self.root.after(50, self.username.focus_set)

    def _reload_printers(self):
        try:
            available = printers()
            self.printer_selector["values"] = available
            selected = self.printer_var.get()
            if selected not in available:
                # Não escolher aleatoriamente a primeira impressora em caixas com várias.
                self.printer_var.set(available[0] if len(available) == 1 else "")
                if len(available) == 1:
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
            som_ativo=bool(self.sound_var.get()),
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
        if not self.token:
            return
        # Um vínculo novo não deve ficar sem polling porque o worker anterior
        # ainda aguarda timeout de uma requisição de rede.
        if (self.worker and self.worker.is_alive() and self.client is not None
                and self.client.token == self.token):
            return
        self.client = ApiClient()
        self.client.token = self.token
        self.worker = threading.Thread(target=self._poll, args=(self.client,), daemon=True,
                                       name="deliveryzim-pedidos")
        self.worker.start()
        self.scale_sync_thread = threading.Thread(
            target=self._push_weights, args=(self.client,), daemon=True,
            name="deliveryzim-sincronizar-peso",
        )
        self.scale_sync_thread.start()

    def _push_weights(self, client):
        """Publica apenas leitura estável e fresca; nunca altera valores de pedidos."""
        backoff = 1.2
        was_active = False
        last_status = None
        while not self.quit_flag.is_set() and self.client is client:
            enabled = bool(self.preferences.get("balanca_ativa"))
            if not enabled and not was_active:
                self.quit_flag.wait(2.0)
                continue
            _, reading = self.scale_monitor.snapshot()
            stable = bool(enabled and reading is not None and reading.stable
                          and reading.kg <= 99.999)
            weight = f"{reading.kg:.3f}" if stable else None
            try:
                client.publish_weight(weight, stable)
                current_status = ("PDV: peso disponível" if stable else
                                  "PDV: aguardando peso estável" if enabled else
                                  "PDV: balança desconectada")
                was_active = enabled
                backoff = 1.2
            except AuthenticationError:
                current_status = "PDV: vínculo expirado. Conecte novamente o agente."
                self.messages.put(("scale_sync", current_status))
                return
            except Exception:
                current_status = "PDV: sem sincronização. Confira a API e a internet."
                backoff = min(30, backoff * 1.8)
            if current_status != last_status:
                self.messages.put(("scale_sync", current_status))
                last_status = current_status
            self.quit_flag.wait(backoff)

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
        # É acionado por cada pedido novo, mesmo quando a janela fica na bandeja.
        if self.sound_var.get():
            play_order_sound()

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
        self.scale_monitor.stop()
        self.preferences.set(cursor=0, restaurante="", device_id=0, balanca_ativa=False, configurado=False)
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
                self.code_holder.pack(fill="x", pady=(14, 0), before=self.login_feedback_label)
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
                    self._start_scale_if_configured()
                    self._start_worker()
                except Exception as exc:
                    messagebox.showerror("Credencial", "Nao foi possivel salvar a credencial: " + str(exc))
            elif kind == "open_pos":
                webbrowser.open(payload)
                self.status_var.set("Caixa aberto. Se não estiver logado, entre e abra novamente pelo Agent.")
            elif kind == "pair_error":
                self.status_var.set("Não foi possível vincular a balança: " + str(payload)[:100])
                messagebox.showwarning("Balança", "Não foi possível associar o navegador. " + str(payload))
            elif kind == "connection":
                if self.token:
                    self.status_var.set(payload)
            elif kind == "auth_expired":
                self.status_var.set("Precisa vincular novamente: " + payload)
                self.token = ""
                self.client = None
                self.scale_monitor.stop()
                delete_credential()
                self._show_link_state()
            elif kind == "scale_sync":
                self.scale_sync_status.set(str(payload))
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
        self.scale_monitor.stop()
        if self.icon is not None:
            self.icon.stop()
        self.root.destroy()

    def _asset(self, name):
        if getattr(sys, "frozen", False):
            return os.path.join(sys._MEIPASS, "agent", "assets", name)
        return os.path.join(os.path.dirname(__file__), "assets", name)

    def _icon_image(self):
        return Image.open(self._asset("icone.png")).convert("RGBA")

    def _logo_image(self):
        return Image.open(self._asset("logo-branca.png")).convert("RGBA")

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
        if self.token and self.preferences.get("configurado") and self.icon is not None:
            # Não pisca uma janela na tela ao iniciar automaticamente com o Windows.
            self.root.withdraw()
        self.root.mainloop()
