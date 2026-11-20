"""Janela nativa leve, bandeja, fila, escolha de impressao e alerta sempre ativo."""
import os
import queue
import sys
import threading
import time
import tkinter as tk
from tkinter import messagebox
import customtkinter as ctk
import webbrowser
import winreg
import ctypes
import pystray
from PIL import Image, ImageTk

from agent import __version__
from agent.alerts import AlertBoard, RING_INTERVAL_MS
from agent.api import ApiClient, AuthenticationError, NeedTwoFactor
from agent.notifier import OrderToasts
from agent.config import Preferences, EventStore, save_credential, load_credential, delete_credential
from agent.printing import job_receipt, printers, receipt, spool
from agent.printer_list import by_key, from_preferences, normalize, principal, resolve, server_payload
from agent.printer_panel import PrinterPanel
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
RADIUS = 6  # cantos discretos, como no painel web

MODE_LABELS = {"auto": "Assim que chegar", "manual": "Ao clicar em Receber"}
MODE_BY_LABEL = {label: mode for mode, label in MODE_LABELS.items()}
MODE_HINTS = {
    "auto": "O cupom sai sozinho quando o pedido chega. O som toca até alguém clicar em Receber.",
    "manual": "O cupom sai quando alguém clicar em Receber no aviso do canto da tela.",
}


class Choice:
    """Grupo de botões (substitui o CTkSegmentedButton, que não tem cor de texto para o item escolhido)."""

    def __init__(self, ui, parent, values, variable, command):
        self.variable = variable
        self.command = command
        self.frame = ctk.CTkFrame(parent, fg_color=WHITE, corner_radius=0)
        self.buttons = {}
        values = list(values)
        for index, value in enumerate(values):
            button = ctk.CTkButton(
                self.frame, text=value, height=38, corner_radius=RADIUS, border_width=1,
                font=ui._font(10, True), command=lambda v=value: self.select(v),
            )
            button.pack(side="left", fill="x", expand=True, padx=(0, 6) if index < len(values) - 1 else 0)
            self.buttons[value] = button
        variable.trace_add("write", lambda *_: self.paint())
        self.paint()

    def select(self, value):
        self.variable.set(value)
        if self.command:
            self.command(value)

    def paint(self):
        current = self.variable.get()
        for value, button in self.buttons.items():
            chosen = value == current
            button.configure(
                fg_color=WINE if chosen else WHITE, hover_color=WINE if chosen else SUBTLE,
                text_color=WHITE if chosen else DARK, border_color=WINE if chosen else BORDER,
            )

    def pack(self, **options):
        self.frame.pack(**options)
        return self


class DeliveryzimApp:
    def __init__(self):
        # AppUserModelID mantém a identidade do EXE correta na barra de tarefas.
        try:
            ctypes.windll.shell32.SetCurrentProcessExplicitAppUserModelID("Deliveryzim.Agent.Windows")
        except (AttributeError, OSError):
            pass
        self.root = ctk.CTk(fg_color=BACKGROUND)
        self.root.title("Deliveryzim Agent")
        self.root.geometry("620x780")
        # Tamanho fixo: o conteúdo rola por dentro, a janela não estica nem encolhe.
        self.root.resizable(False, False)
        self._set_window_icon()
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
        self.alerts = AlertBoard()
        self.status_thread = None
        self.icon = None
        # Lista de impressoras (Balcão, Cozinha...); a primeira é a principal. Quem vem da 0.7 ganha a "Principal".
        self.printer_items = from_preferences(self.preferences.data)
        self.printer_sync_lock = threading.Lock()
        self.mode_var = tk.StringVar(value="auto" if self.preferences.get("automatico") else "manual")
        # O instalador já liga "abrir com o Windows"; a caixa de seleção mostra o que está valendo.
        self.start_var = tk.BooleanVar(value=bool(self.preferences.get("iniciar_windows")) or self._startup_registered())
        self.logo_var = tk.BooleanVar(value=self.preferences.get("logo_cupom") is not False)
        self.open_panel_var = tk.BooleanVar(value=self.preferences.get("abrir_painel") is not False)
        self.sound_var = tk.BooleanVar(value=bool(self.preferences.get("som_ativo")))
        # Cópia simples para a bandeja (outra thread) ler sem tocar em variáveis do Tk.
        self.sound_on = bool(self.preferences.get("som_ativo"))
        self.toasts = OrderToasts(self.root, self._receive, self._open_order,
                                  self._silence, self._print)
        self.status_var = tk.StringVar(value="Conectando..." if self.token else "Nao vinculado")
        self.scale_sync_status = tk.StringVar(value="PDV: aguardando ativação")
        self.name_var = tk.StringVar(value=self.preferences.get("restaurante") or "")
        self._render()
        self._start_scale_if_configured()
        self.root.after(150, self._drain)
        self.root.after(RING_INTERVAL_MS, self._ring_loop)
        if self.token:
            self._start_worker()
        self.root.after(500, self._recover_jobs)

    # ---------- componentes (CustomTkinter, cores oficiais) ----------
    def _font(self, size=10, bold=False):
        return ctk.CTkFont(family="Segoe UI", size=size + 3, weight="bold" if bold else "normal")

    def _label(self, parent, text="", size=10, color=DARK, bold=False, wrap=0, **options):
        return ctk.CTkLabel(parent, text=text, text_color=color, font=self._font(size, bold),
                            anchor="w", justify="left", wraplength=wrap or 0, **options)

    def _button(self, parent, text, command, primary=True, **options):
        style = dict(
            fg_color=ORANGE if primary else WHITE, hover_color=WINE if primary else SUBTLE,
            text_color=WHITE if primary else WINE, border_width=0 if primary else 1,
            border_color=BORDER, corner_radius=RADIUS, height=40 if primary else 36,
            font=self._font(10, True),
        )
        style.update(options)
        return ctk.CTkButton(parent, text=text, command=command, **style)

    def _card(self, parent):
        return ctk.CTkFrame(parent, fg_color=WHITE, corner_radius=RADIUS, border_width=1, border_color=BORDER)

    def _checkbox(self, parent, text, variable, command):
        return ctk.CTkCheckBox(parent, text=text, variable=variable, command=command,
                               onvalue=True, offvalue=False, fg_color=WINE, hover_color=ORANGE,
                               border_color=GRAY, text_color=DARK, font=self._font(10),
                               corner_radius=4, checkbox_width=20, checkbox_height=20)

    def _segmented(self, parent, values, variable, command):
        """Escolha entre opções lado a lado. A escolhida fica vinho com letra branca (legível)."""
        return Choice(self, parent, values, variable, command)

    def _entry(self, parent, **options):
        return ctk.CTkEntry(parent, height=40, corner_radius=RADIUS, border_width=1, border_color=BORDER,
                            fg_color=WHITE, text_color=DARK, font=self._font(11), **options)

    def _render(self):
        ctk.set_appearance_mode("light")
        # Cabeçalho vinho com a logo oficial (arquivo da marca, sem redesenhar).
        header = ctk.CTkFrame(self.root, fg_color=WINE, corner_radius=0, height=96)
        header.pack(fill="x")
        header.pack_propagate(False)
        brand = ctk.CTkFrame(header, fg_color=WINE, corner_radius=0)
        brand.pack(side="left", padx=26, pady=18)
        try:
            self.brand_photo = ctk.CTkImage(light_image=self._logo_image(), size=(196, 46))
            ctk.CTkLabel(brand, image=self.brand_photo, text="").pack(side="left")
        except Exception:
            self.brand_photo = None
            ctk.CTkLabel(brand, text="Deliveryzim", text_color=WHITE, font=self._font(18, True)).pack(side="left")
        ctk.CTkLabel(header, text="Agent para Windows · v" + __version__, text_color="#F6EAED",
                     font=self._font(9)).pack(side="right", padx=26)
        ctk.CTkFrame(self.root, fg_color=ORANGE, corner_radius=0, height=3).pack(fill="x")

        self.screen = ctk.CTkFrame(self.root, fg_color=BACKGROUND, corner_radius=0)
        self.screen.pack(fill="both", expand=True)

        # ----- Login: só aparece até o computador ser vinculado ao restaurante -----
        self.login_page = ctk.CTkFrame(self.screen, fg_color=BACKGROUND, corner_radius=0)
        self._label(self.login_page, "Conecte seu restaurante", 18, DARK, True).pack(anchor="w", padx=32, pady=(26, 2))
        self._label(self.login_page, "Entre com a conta de gerente ou caixa do Deliveryzim.",
                    10, GRAY, wrap=520).pack(anchor="w", padx=32)
        card = self._card(self.login_page)
        card.pack(fill="x", padx=32, pady=(18, 0))
        inner = ctk.CTkFrame(card, fg_color=WHITE, corner_radius=0)
        inner.pack(fill="x", padx=22, pady=20)
        self._label(inner, "E-mail ou usuário", 10, DARK, True).pack(anchor="w", pady=(0, 6))
        self.username = self._entry(inner)
        self.username.pack(fill="x")
        self._label(inner, "Senha", 10, DARK, True).pack(anchor="w", pady=(14, 6))
        self.password = self._entry(inner, show="●")
        self.password.pack(fill="x")
        self.password.bind("<Return>", lambda _e: self.login_button.invoke())
        self.code_holder = ctk.CTkFrame(inner, fg_color=WHITE, corner_radius=0)
        self._label(self.code_holder, "Código de verificação", 10, DARK, True).pack(anchor="w", pady=(0, 6))
        self.code = self._entry(self.code_holder)
        self.code.pack(fill="x")
        self.code.bind("<Return>", lambda _e: self.login_button.invoke())
        self.login_feedback = tk.StringVar(value="")
        self.login_feedback_label = self._label(inner, "", 9, WINE, wrap=470, textvariable=self.login_feedback)
        self.login_feedback_label.pack(fill="x", pady=(12, 0))
        self.login_button = self._button(inner, "Entrar e conectar este computador", self._login)
        self.login_button.pack(fill="x", pady=(8, 0))
        self._label(self.login_page, "A senha não fica guardada neste computador. O vínculo vale só para o seu restaurante.",
                    9, GRAY, wrap=520).pack(anchor="w", padx=32, pady=(14, 0))

        # ----- Configuração: impressora, balança e pedidos na mesma página -----
        self.settings_scroll = ctk.CTkScrollableFrame(self.screen, fg_color=BACKGROUND, corner_radius=0)
        self.settings_page = self.settings_scroll
        pad = dict(fill="x", padx=22)

        top = ctk.CTkFrame(self.settings_page, fg_color=BACKGROUND, corner_radius=0)
        top.pack(**pad, pady=(16, 10))
        self.name_label = self._label(top, "", 16, DARK, True, textvariable=self.name_var)
        self.name_label.pack(fill="x")
        self.status_label = self._label(top, "", 9, GRAY, wrap=540, textvariable=self.status_var)
        self.status_label.pack(fill="x")

        # Novos pedidos (o mais importante primeiro).
        alert = self._card(self.settings_page)
        alert.pack(**pad, pady=(0, 10))
        box = ctk.CTkFrame(alert, fg_color=WHITE, corner_radius=0)
        box.pack(fill="x", padx=18, pady=14)
        self._label(box, "Novos pedidos", 12, DARK, True).pack(anchor="w")
        self._label(box, "Aparece um aviso no canto da tela com Receber e Ver no sistema.", 9, GRAY,
                    wrap=520).pack(anchor="w", pady=(2, 10))
        self._checkbox(box, "Tocar até alguém receber o pedido", self.sound_var,
                       self._save_preferences).pack(anchor="w")
        self._button(box, "Ouvir o som", self._test_sound, primary=False, width=130).pack(anchor="w", pady=(8, 12))
        self._label(box, "Quando imprimir o pedido", 10, DARK, True).pack(anchor="w", pady=(0, 6))
        self.mode_label_var = tk.StringVar(value=MODE_LABELS[self.mode_var.get()])
        self.mode_selector = self._segmented(box, MODE_LABELS.values(), self.mode_label_var,
                                             lambda label: self._select_mode(MODE_BY_LABEL[label]))
        self.mode_selector.pack(fill="x")
        self.mode_hint = self._label(box, "", 9, GRAY, wrap=520)
        self.mode_hint.pack(anchor="w", pady=(4, 0))
        self._paint_mode_buttons()

        # Impressoras (lista sem limite, com apelido).
        printer = self._card(self.settings_page)
        printer.pack(**pad, pady=(0, 10))
        self.printer_panel = PrinterPanel(printer, self.printer_items, self, self._printers_changed)
        self.printer_panel.frame.pack(fill="x", padx=18, pady=14)
        logo = ctk.CTkFrame(printer, fg_color=WHITE, corner_radius=0)
        logo.pack(fill="x", padx=18, pady=(0, 14))
        self._checkbox(logo, "Imprimir a logo do Deliveryzim no cupom", self.logo_var,
                       self._save_preferences).pack(anchor="w")
        self._label(logo, "Se aparecerem símbolos estranhos no topo do cupom, desmarque.", 9, GRAY,
                    wrap=520).pack(anchor="w", padx=(28, 0))

        # Balança.
        scale = self._card(self.settings_page)
        scale.pack(**pad, pady=(0, 10))
        self.scale_panel = ScalePanel(scale, self.preferences, self.scale_monitor, self.scale_sync_status, self)
        self.scale_panel.frame.pack(fill="x", padx=18, pady=14)

        # Rodapé: ação principal em destaque.
        foot = ctk.CTkFrame(self.settings_page, fg_color=BACKGROUND, corner_radius=0)
        foot.pack(**pad, pady=(4, 18))
        self._checkbox(foot, "Abrir sozinho quando o Windows ligar", self.start_var,
                       self._save_preferences).pack(anchor="w", pady=(0, 8))
        self._checkbox(foot, "Abrir o painel do Deliveryzim no navegador ao iniciar", self.open_panel_var,
                       self._save_preferences).pack(anchor="w", pady=(0, 10))
        self._button(foot, "Testar impressão e aviso", self._test, primary=False).pack(fill="x", pady=(0, 8))
        self._button(foot, "Concluir e deixar em segundo plano", self._finish_setup).pack(fill="x")
        self._label(foot, "O Agent continua no ícone ao lado do relógio do Windows.", 9, GRAY).pack(anchor="w", pady=(6, 10))
        links = ctk.CTkFrame(foot, fg_color=BACKGROUND, corner_radius=0)
        links.pack(fill="x")
        self._button(links, "Desvincular", self._unlink, primary=False, width=130, height=32,
                     text_color=GRAY).pack(side="left")
        self._button(links, "Abrir Deliveryzim", self._open_deliveryzim, primary=False, width=160,
                     height=32).pack(side="right")
        self._show_link_state()

    def _open_deliveryzim(self, quiet=False):
        """Abre o painel no navegador padrão (ou no app instalado) já ligado à balança deste computador.

        O link do Agent associa o navegador e leva o gerente à gerência e o caixa ao caixa; sem login,
        o sistema pede para entrar e conclui depois.
        """
        if not self.token:
            webbrowser.open("https://deliveryzim.com.br/login")
            return
        if not quiet:
            self.status_var.set("Abrindo o Deliveryzim no navegador...")
        threading.Thread(target=self._open_deliveryzim_task, args=(quiet,), daemon=True).start()

    def _open_deliveryzim_task(self, quiet=False):
        try:
            client = ApiClient()
            client.token = self.token
            self.messages.put(("open_pos", client.browser_pairing(destino="gerencia")))
        except Exception as exc:
            if quiet:
                webbrowser.open("https://deliveryzim.com.br/login")
            else:
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
        if self.mode_var.get() == "auto" and principal(self.printer_items) is None:
            messagebox.showwarning("Impressora", "Escolha a impressora do Windows da impressora principal ou use o modo com confirmação.")
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
        mode = self.mode_var.get()
        self.mode_label_var.set(MODE_LABELS[mode])
        self.mode_hint.configure(text=MODE_HINTS[mode])

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
            first = self.printer_panel.row_list[0] if self.printer_panel.row_list else None
            if available:
                message = str(len(available)) + " impressora(s) do Windows encontrada(s)."
            else:
                message = "Nenhuma impressora encontrada. Instale uma nas configurações do Windows e clique em Atualizar lista."
            self.printer_panel.set_available(available, message, error=not available)
            # Uma impressora só no Windows e a principal sem escolha: usa ela (não escolhe ao acaso com várias).
            if first is not None and not first.windows.get() and len(available) == 1:
                first.windows.set(available[0])
                self.printer_panel.changed()
        except Exception as exc:
            self.printer_panel.set_available([], str(exc)[:230], error=True)

    def _printers_changed(self):
        self.printer_items = normalize(self.printer_panel.values())
        self._save_preferences()
        self._sync_printers()

    def _sync_printers(self):
        """Manda a lista (chave + apelido) para o sistema, para o gerente escolher os pontos de impressão."""
        if not self.token:
            return
        items = server_payload(self.printer_items)
        token = self.token

        def task():
            with self.printer_sync_lock:
                try:
                    client = ApiClient()
                    client.token = token
                    client.register_printers(items)
                except AuthenticationError:
                    pass
                except Exception as exc:
                    self.messages.put(("job_status", "Impressoras não enviadas ao sistema: " + str(exc)[:90]))
        threading.Thread(target=task, daemon=True, name="deliveryzim-impressoras").start()

    def _test_printer(self, printer):
        """Botão Testar de cada impressora: cupom curto com o apelido."""
        if not printer.get("windows"):
            messagebox.showwarning("Impressoras", "Escolha a impressora do Windows antes de testar.")
            return
        name = printer.get("apelido") or "Impressora"
        job = {"id": 0, "titulo": "Teste " + name, "blocos": [
            {"t": "titulo", "texto": "TESTE"}, {"t": "centro", "texto": "Impressora: " + name},
            {"t": "texto", "texto": time.strftime("%d/%m/%Y %H:%M")}, {"t": "linha"},
            {"t": "texto", "texto": "Se você leu isto, a impressora está pronta."}]}
        logo = bool(self.logo_var.get())

        def task():
            try:
                spool(printer["windows"], job_receipt(job, printer["papel"], logo), "Deliveryzim teste " + name)
                self.messages.put(("job_status", "Teste enviado para " + name + "."))
            except Exception as exc:
                self.messages.put(("job_status", "Falha no teste de " + name + ": " + str(exc)[:100]))
        threading.Thread(target=task, daemon=True).start()

    def _save_preferences(self):
        self.preferences.set(
            automatico=self.mode_var.get() == "auto",
            impressoras=self.printer_items,
            # Campos da 0.7 (uma impressora): continuam com a principal, caso volte para a versão antiga.
            impressora=(principal(self.printer_items) or {}).get("windows", ""),
            papel=int((principal(self.printer_items) or {}).get("papel", 80)),
            iniciar_windows=bool(self.start_var.get()),
            som_ativo=bool(self.sound_var.get()),
            logo_cupom=bool(self.logo_var.get()),
            abrir_painel=bool(self.open_panel_var.get()),
        )
        self.sound_on = bool(self.sound_var.get())
        if self.icon is not None:
            try:
                self.icon.update_menu()
            except Exception:
                pass
        self._startup(bool(self.start_var.get()))

    @staticmethod
    def _startup_registered():
        try:
            with winreg.OpenKey(winreg.HKEY_CURRENT_USER, r"Software\Microsoft\Windows\CurrentVersion\Run") as key:
                winreg.QueryValueEx(key, "DeliveryzimAgent")
                return True
        except OSError:
            return False

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
        self.login_button.configure(state="disabled")
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
        self.login_button.configure(state="disabled")
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
        self.status_thread = threading.Thread(
            target=self._watch_orders, args=(self.client,), daemon=True,
            name="deliveryzim-situacao-pedidos",
        )
        self.status_thread.start()
        self._sync_printers()

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
                # Cupons pedidos pelo caixa/garçom ("Imprimir"): o sistema já reservou para este computador.
                for job in response.get("impressoes") or []:
                    if isinstance(job, dict) and isinstance(job.get("id"), int) and isinstance(job.get("blocos"), list):
                        self.messages.put(("print_job", job))
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

    def _watch_orders(self, client):
        """A cada 5 s pergunta a situação dos pedidos esperando: aceito no caixa ou cancelado = para de tocar."""
        while not self.quit_flag.is_set() and self.client is client:
            ids = [i for i in self.alerts.ids() if i > 0]
            if ids:
                try:
                    self.messages.put(("order_status", client.order_status(ids)))
                except AuthenticationError:
                    return
                except Exception:
                    pass  # sem internet: continua tocando até conseguir conferir
            self.quit_flag.wait(5.0)

    def _recover_jobs(self):
        """Ao abrir: retoma impressões interrompidas e volta a avisar pedidos que ninguém aceitou."""
        if not self.token:
            return
        candidates = {}
        for order, status in self.store.pending():
            if status == "imprimindo":
                order = dict(order, _aviso="Envio anterior indefinido. Confira a fila do Windows antes de imprimir de novo.")
            elif status == "erro":
                order = dict(order, _aviso="A impressão anterior falhou. Confira a impressora.")
            elif self.mode_var.get() == "auto":
                self._print(order)
            candidates[int(order.get("id") or 0)] = order
        for order in self.store.recent():
            candidates.setdefault(int(order.get("id") or 0), order)
        candidates.pop(0, None)
        if candidates:
            threading.Thread(target=self._recover_task, args=(list(candidates.values()),), daemon=True).start()

    def _recover_task(self, orders):
        try:
            client = ApiClient()
            client.token = self.token
            current = {int(r.get("id") or 0): str(r.get("status") or "")
                       for r in client.order_status([o["id"] for o in orders]) if isinstance(r, dict)}
        except Exception:
            current = {}
        for order in orders:
            status = current.get(int(order["id"]))
            if status == "recebido" or order.get("_aviso"):
                self.messages.put(("recovered", dict(order, status=status or order.get("status"))))

    def _test(self):
        moment = int(time.time() * 1000)
        order = {
            "evento_id": -moment, "id": -moment, "numero": "TESTE", "tipo": "retirada",
            "status": "recebido", "teste": True,
            "cliente": "Cliente de teste", "criado_em": time.strftime("%d/%m/%Y %H:%M"),
            "itens": [{"nome": "X-Bacon", "quantidade": "2", "total": "40.00",
                       "observacao": "Sem cebola"},
                      {"nome": "Refrigerante", "quantidade": "1", "total": "6.00"}],
            "pagamento": "dinheiro", "total": "46.00",
            "observacao": "Cupom simulado - nao registra venda."
        }
        self.store.receive(order)
        self._new_order(order)

    def _new_order(self, order, warning="", recovered=False):
        needs_action = self.alerts.add(order)
        self.toasts.show(order, needs_action=needs_action, warning=warning)
        if needs_action or not recovered:
            self._ring()
        if self.mode_var.get() == "auto" and not recovered:
            self._print(order)

    def _ring(self):
        # Também toca com a janela fechada na bandeja; o laço abaixo repete até alguém receber.
        if self.sound_on:
            play_order_sound()

    def _ring_loop(self):
        if self.alerts.should_ring(self.sound_on):
            play_order_sound()
        self.root.after(RING_INTERVAL_MS, self._ring_loop)

    def _silence(self, order):
        self.alerts.silence(int(order.get("id") or 0))

    def _receive(self, order):
        """"Receber" = aceitar o pedido no sistema (Novo -> Em preparo), como o botão Aceitar do caixa."""
        pedido_id = int(order.get("id") or 0)
        if order.get("teste"):
            self._finish_receive(order, True, "em_preparo", "Pedido de teste recebido.")
            return
        if not self.token:
            self.toasts.set_message(pedido_id, "Este computador não está vinculado. Entre de novo no Agent.", error=True)
            return
        self.toasts.set_busy(pedido_id, True, "Aceitando o pedido...")
        threading.Thread(target=self._receive_task, args=(order,), daemon=True).start()

    def _receive_task(self, order):
        try:
            client = ApiClient()
            client.token = self.token
            accepted, status, message = client.receive_order(int(order["id"]))
            self.messages.put(("received", (order, accepted, status, message)))
        except AuthenticationError as exc:
            self.messages.put(("receive_error", (order, str(exc))))
            self.messages.put(("auth_expired", str(exc)))
        except Exception as exc:
            self.messages.put(("receive_error", (order, str(exc)[:160])))

    def _finish_receive(self, order, accepted, status, message):
        pedido_id = int(order.get("id") or 0)
        self.alerts.resolve(pedido_id)
        self.toasts.close(pedido_id)
        numero = str(order.get("numero", ""))
        if accepted:
            self.status_var.set("Pedido #" + numero + " aceito. Em preparo.")
            # Modo "Ao receber": o cupom sai agora (no automático já saiu quando o pedido chegou).
            if self.mode_var.get() != "auto":
                self._print(order)
        elif status == "cancelado":
            self.status_var.set("Pedido #" + numero + " foi cancelado.")
        else:
            self.status_var.set("Pedido #" + numero + ": " + (message or "já foi aceito em outro lugar."))

    def _open_order(self, order):
        client = self.client or ApiClient()
        webbrowser.open(client.order_url(order) if not order.get("teste") else client.url("/login"))

    def _print(self, order):
        """Pedido do delivery: sai nas impressoras deste computador marcadas no ponto "Pedido novo do delivery".

        Sem configuração no sistema ("impressoras" ausente ou None) sai na principal, como antes.
        Lista vazia: o gerente escolheu impressoras de outro computador; aqui só avisa.
        """
        event_id = int(order["evento_id"])
        keys = order.get("impressoras") if isinstance(order.get("impressoras"), list) else None
        targets = resolve(self.printer_items, keys)
        if keys is not None and not targets:
            self.store.update(event_id, "sem_impressora_aqui")
            return
        self.store.update(event_id, "imprimindo")
        logo = bool(self.logo_var.get())
        threading.Thread(target=self._print_task, args=(order, targets, logo), daemon=True).start()

    def _print_task(self, order, targets, logo=True):
        try:
            if not targets:
                raise RuntimeError("Selecione uma impressora nas configuracoes.")
            for target in targets:
                payload = receipt(order, target["papel"], logo)
                spool(target["windows"], payload, "Deliveryzim pedido #" + str(order.get("numero", "")))
            self.messages.put(("printed", order))
        except Exception as exc:
            self.messages.put(("print_error", (order, str(exc))))

    def _print_job(self, job):
        """Imprime na hora o cupom pedido no sistema (independe de "Automática" / "Ao receber").

        job["impressora"] = chave da impressora escolhida no sistema ("" = a principal deste computador).
        """
        target = by_key(self.printer_items, str(job.get("impressora") or ""))
        logo = bool(self.logo_var.get())
        threading.Thread(target=self._print_job_task, args=(job, target, logo), daemon=True,
                         name="deliveryzim-cupom").start()

    def _print_job_task(self, job, target, logo=True):
        title = str(job.get("titulo") or "Cupom")[:80]
        try:
            if target is None or not target.get("windows"):
                raise RuntimeError("Impressora não configurada neste computador. Confira o Deliveryzim Agent.")
            spool(target["windows"], job_receipt(job, target["papel"], logo), "Deliveryzim " + title)
            ok, error = True, ""
            self.messages.put(("job_status", title + ": enviado ao Windows."))
        except Exception as exc:
            ok, error = False, str(exc)[:160] or "Falha no Windows."
            self.messages.put(("job_status", "Falha ao imprimir " + title + ": " + error[:100]))
        try:
            client = ApiClient()
            client.token = self.token
            client.print_result(int(job["id"]), ok, error)
        except Exception:
            pass  # sem internet: o caixa vê que o computador não confirmou e confere o papel

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
        self.toasts.close_all()
        self.alerts.clear()
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
                self.login_button.configure(text="Confirmar codigo", command=self._verify, state="normal")
                self.password.delete(0, "end")
                self.status_var.set("Digite o codigo da verificacao em duas etapas.")
            elif kind == "login_error":
                self.login_button.configure(state="normal")
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
                self.status_var.set("Deliveryzim aberto no navegador. Se pedir login, é só entrar.")
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
            elif kind == "print_job":
                self._print_job(payload)
            elif kind == "job_status":
                self.status_var.set(str(payload)[:150])
            elif kind == "printed":
                self.store.update(payload["evento_id"], "enviado_ao_windows")
                self.status_var.set("Pedido #" + str(payload.get("numero")) + " enviado ao Windows.")
            elif kind == "print_error":
                order, reason = payload
                self.store.update(order["evento_id"], "erro")
                self.status_var.set("Falha de impressao: " + reason[:130])
                self.toasts.show(order, needs_action=self.alerts.waiting(int(order.get("id") or 0)),
                                 warning="Falha na impressão: " + reason[:100])
            elif kind == "order_status":
                for pedido_id in self.alerts.apply_status(payload):
                    self.toasts.close(pedido_id)
            elif kind == "recovered":
                self._new_order(payload, warning=str(payload.get("_aviso") or ""), recovered=True)
            elif kind == "received":
                self._finish_receive(*payload)
            elif kind == "receive_error":
                order, reason = payload
                self.toasts.set_busy(int(order.get("id") or 0), False)
                self.toasts.set_message(int(order.get("id") or 0),
                                        "Não deu para aceitar: " + reason + " Tente de novo ou aceite pelo caixa.",
                                        error=True)
            elif kind == "toggle_sound":
                self.sound_var.set(not self.sound_var.get())
                self._save_preferences()
                self.status_var.set("Som dos pedidos " + ("ligado." if self.sound_on else "desligado."))
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

    def _set_window_icon(self):
        """Ícone oficial na janela e na barra de tarefas (o CustomTkinter troca pelo dele se não for avisado)."""
        try:
            self.window_icon_photo = ImageTk.PhotoImage(self._icon_image().resize((64, 64)))
            self.root.iconphoto(True, self.window_icon_photo)
        except (OSError, RuntimeError):
            pass
        ico = self._asset("deliveryzim.ico")
        try:
            if os.path.exists(ico):
                self.root.iconbitmap(ico)
            else:
                self.root._iconbitmap_method_called = True  # desenvolvimento: mantém o iconphoto acima
        except Exception:
            pass

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
                    pystray.MenuItem("Som dos pedidos", lambda *_: self.messages.put(("toggle_sound", None)),
                                     checked=lambda _item: self.sound_on),
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
        if self.token and self.preferences.get("abrir_painel") is not False:
            # Ao ligar o computador o painel já abre no navegador/app, com a balança associada.
            self.root.after(2500, lambda: self._open_deliveryzim(quiet=True))
        self.root.mainloop()
