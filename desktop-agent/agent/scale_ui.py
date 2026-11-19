"""Janela nativa de configuração da balança, independente do PDV online."""
import tkinter as tk
from tkinter import ttk, messagebox

from agent.scale import ScaleSettings, serial_ports

WINE = "#651424"
ORANGE = "#FF5825"
WHITE = "#FFFFFF"
DARK = "#272727"
GRAY = "#6C6770"
BG = "#F7F5F5"
BORDER = "#E8E3E4"

PROTOCOLS = {"Fluxo contínuo (ASCII)": "continuo", "Solicitar com ENQ": "enq"}
PROTOCOL_NAMES = {value: key for key, value in PROTOCOLS.items()}


class ScaleWindow:
    def __init__(self, parent, preferences, monitor):
        self.preferences = preferences
        self.monitor = monitor
        self.window = tk.Toplevel(parent)
        self.window.title("Balança — Deliveryzim Agent")
        self.window.geometry("520x570")
        self.window.resizable(False, False)
        self.window.configure(bg=BG)
        self.window.transient(parent)
        self.window.protocol("WM_DELETE_WINDOW", self.close)

        self.port = tk.StringVar(value=preferences.get("balanca_porta") or "")
        self.baudrate = tk.StringVar(value=str(preferences.get("balanca_baudrate") or 9600))
        self.protocol = tk.StringVar(value=PROTOCOL_NAMES.get(
            preferences.get("balanca_protocolo"), "Fluxo contínuo (ASCII)"))
        self.unit = tk.StringVar(value=preferences.get("balanca_unidade") or "kg")
        self.status = tk.StringVar(value="Balança não configurada")
        self.weight = tk.StringVar(value="— kg")
        self.description = tk.StringVar(value="")
        self._draw()
        self.reload_ports()
        self.refresh()
        self.window.lift()

    def label(self, parent, text, size=10, color=DARK, bold=False, **options):
        return tk.Label(
            parent, text=text, fg=color, bg=parent.cget("bg"),
            font=("Segoe UI", size, "bold" if bold else "normal"),
            anchor="w", justify="left", **options,
        )

    def button(self, parent, title, action, primary=True):
        return tk.Button(
            parent, text=title, command=action, cursor="hand2",
            bg=WINE if primary else WHITE, fg=WHITE if primary else WINE,
            activebackground=ORANGE if primary else BG,
            activeforeground=WHITE if primary else WINE,
            font=("Segoe UI", 10, "bold"), relief="flat",
            highlightthickness=1, highlightbackground=WINE if primary else BORDER,
            padx=12, pady=10, takefocus=True,
        )

    def _draw(self):
        head = tk.Frame(self.window, bg=WINE, padx=25, pady=17)
        head.pack(fill="x")
        self.label(head, "Conectar balança", 18, WHITE, True).pack(anchor="w")
        self.label(head, "Leitura local para produtos vendidos por peso",
                   10, WHITE).pack(anchor="w", pady=(5, 0))
        tk.Frame(self.window, bg=ORANGE, height=3).pack(fill="x")

        body = tk.Frame(self.window, bg=BG, padx=24, pady=17)
        body.pack(fill="both", expand=True)
        card = tk.Frame(body, bg=WHITE, padx=17, pady=14,
                        highlightbackground=BORDER, highlightthickness=1)
        card.pack(fill="x")

        line = tk.Frame(card, bg=WHITE)
        line.pack(fill="x")
        self.label(line, "Porta da balança", 10, DARK, True).pack(side="left")
        self.button(line, "Atualizar portas", self.reload_ports, False).pack(side="right")
        self.port_selector = ttk.Combobox(card, textvariable=self.port, state="readonly",
                                          font=("Segoe UI", 11))
        self.port_selector.pack(fill="x", pady=(8, 4), ipady=4)
        self.label(card, "", 9, GRAY, wraplength=430,
                   textvariable=self.description).pack(anchor="w")

        settings = tk.Frame(card, bg=WHITE)
        settings.pack(fill="x", pady=(12, 0))
        left = tk.Frame(settings, bg=WHITE)
        right = tk.Frame(settings, bg=WHITE)
        left.pack(side="left", fill="x", expand=True, padx=(0, 7))
        right.pack(side="left", fill="x", expand=True, padx=(7, 0))
        self.label(left, "Velocidade", 9, DARK, True).pack(anchor="w")
        ttk.Combobox(left, textvariable=self.baudrate, state="readonly",
                     values=("1200", "2400", "4800", "9600", "19200",
                             "38400", "57600", "115200")).pack(fill="x", pady=(5, 0))
        self.label(right, "Unidade recebida", 9, DARK, True).pack(anchor="w")
        ttk.Combobox(right, textvariable=self.unit, state="readonly",
                     values=("kg", "g")).pack(fill="x", pady=(5, 0))

        self.label(card, "Comunicação", 9, DARK, True).pack(anchor="w", pady=(12, 0))
        ttk.Combobox(card, textvariable=self.protocol, state="readonly",
                     values=tuple(PROTOCOLS), font=("Segoe UI", 10)).pack(fill="x", pady=(5, 0))

        readout = tk.Frame(body, bg=WHITE, padx=17, pady=13,
                           highlightbackground=BORDER, highlightthickness=1)
        readout.pack(fill="x", pady=(12, 0))
        self.label(readout, "PESO ATUAL", 9, WINE, True).pack(anchor="w")
        self.label(readout, "", 29, WINE, True,
                   textvariable=self.weight).pack(anchor="w", pady=(0, 3))
        self.label(readout, "", 9, GRAY,
                   textvariable=self.status, wraplength=436).pack(anchor="w")

        actions = tk.Frame(body, bg=BG)
        actions.pack(fill="x", pady=(12, 0))
        self.button(actions, "Conectar e salvar", self.connect).pack(side="left", fill="x", expand=True, padx=(0, 5))
        self.button(actions, "Desconectar", self.disconnect, False).pack(side="right", padx=(5, 0))
        self.label(
            body,
            "Sem leitura? Confira o cabo, a porta e o protocolo do fabricante. "
            "A tara e o zero são feitos na balança física. "
            "O peso exibido ainda não altera pedidos ou valores no Deliveryzim.",
            9, GRAY, wraplength=458,
        ).pack(anchor="w", pady=(12, 0))

    def reload_ports(self):
        ports = serial_ports()
        values = [p[0] for p in ports]
        selected = self.port.get()
        if selected and selected not in values:
            values.insert(0, selected)  # Preserva configuração de porta desconectada.
        self.port_selector["values"] = values
        if values and not selected:
            self.port.set(values[0])
        port_desc = next((description for port, description in ports
                          if port == self.port.get()), None)
        self.description.set(
            (str(len(ports)) + " porta(s) detectada(s). " + port_desc)
            if port_desc else "Nenhuma balança identificada automaticamente. "
            "Selecione a porta COM em que ela foi instalada."
        )

    def connect(self):
        try:
            settings = ScaleSettings(
                port=self.port.get().strip(),
                baudrate=int(self.baudrate.get()),
                protocol=PROTOCOLS[self.protocol.get()],
                unit=self.unit.get(),
            )
            settings.validate()
            self.monitor.configure(settings)
            self.preferences.set(
                balanca_ativa=True, balanca_porta=settings.port.upper(),
                balanca_baudrate=settings.baudrate,
                balanca_protocolo=settings.protocol, balanca_unidade=settings.unit,
            )
        except (ValueError, KeyError, OSError) as exc:
            messagebox.showerror("Balança", str(exc), parent=self.window)

    def disconnect(self):
        self.monitor.stop()
        self.preferences.set(balanca_ativa=False)

    def refresh(self):
        if not self.window.winfo_exists():
            return
        status, reading = self.monitor.snapshot()
        self.status.set(status)
        if reading is None:
            self.weight.set("— kg")
        else:
            self.weight.set(f"{reading.kg:.3f}".replace(".", ",") + " kg")
            if not reading.stable:
                self.status.set(status + " — não utilizar para venda")
        self.window.after(300, self.refresh)

    def close(self):
        # A leitura continua na bandeja se foi ativada em preferências.
        self.window.destroy()
