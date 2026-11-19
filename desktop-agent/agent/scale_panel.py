"""Configuração da balança integrada à tela pós-login do Deliveryzim Agent."""
import tkinter as tk
from tkinter import ttk, messagebox

from agent.scale import PORTA_WIFI, ScaleSettings, endereco_wifi, serial_ports

WINE = "#651424"
ORANGE = "#FF5825"
GRAY = "#6C6770"
WHITE = "#FFFFFF"
BORDER = "#E8E3E4"
CABO = "Cabo (porta COM)"
WIFI = "Wi-Fi (rede local)"


class ScalePanel:
    """Mostra controles locais; a porta nunca é configurada dentro do PDV web."""

    def __init__(self, parent, preferences, monitor, sync_status):
        self.preferences = preferences
        self.monitor = monitor
        self.sync_status = sync_status
        self.frame = tk.Frame(parent, bg=WHITE)
        saved = str(preferences.get("balanca_porta") or "")
        wifi = saved.lower().startswith("tcp://")
        host, _, porta = saved[6:].partition(":") if wifi else ("", "", "")
        # Cabo (porta COM) ou Wi-Fi (balança de rede ou app Android da balança virtual).
        self.source = tk.StringVar(value=WIFI if wifi else CABO)
        self.port = tk.StringVar(value="" if wifi else saved)
        self.tcp_host = tk.StringVar(value=host)
        self.tcp_port = tk.StringVar(value=porta or str(PORTA_WIFI))
        self.baudrate = tk.StringVar(value=str(preferences.get("balanca_baudrate") or 9600))
        self.protocol = tk.StringVar(value={
            "continuo": "Fluxo contínuo",
            "enq": "Solicitar leitura (ENQ)",
        }.get(str(preferences.get("balanca_protocolo") or "continuo"), "Fluxo contínuo"))
        self.unit = tk.StringVar(value=str(preferences.get("balanca_unidade") or "kg"))
        self.status = tk.StringVar(value="Balança não configurada")
        self.weight = tk.StringVar(value="— kg")
        self.details = tk.StringVar(value="")
        self._render()
        self._toggle_source()
        self.reload_ports()
        self.refresh()

    def _label(self, parent, text, size=10, bold=False, color=WINE):
        return tk.Label(parent, text=text, bg=parent["bg"], fg=color,
                        anchor="w", justify="left", font=("Segoe UI", size, "bold" if bold else "normal"))

    def _render(self):
        top = tk.Frame(self.frame, bg=WHITE)
        top.pack(fill="x")
        self._label(top, "02  /  BALANÇA", 10, True).pack(side="left")
        tk.Button(top, text="Detectar portas", command=self.reload_ports,
                  bg=WHITE, fg=WINE, bd=0, cursor="hand2",
                  font=("Segoe UI", 9, "bold")).pack(side="right")
        self._label(self.frame, "Ligue a balança no cabo ou pelo Wi-Fi da mesma rede.",
                    9, color=GRAY).pack(anchor="w", pady=(4, 12))

        self._label(self.frame, "Como a balança está ligada", 9, True).pack(anchor="w")
        source = ttk.Combobox(self.frame, textvariable=self.source, state="readonly",
                              values=(CABO, WIFI), style="Agent.TCombobox", font=("Segoe UI", 10))
        source.pack(fill="x", pady=(5, 10))
        source.bind("<<ComboboxSelected>>", lambda _e: self._toggle_source())

        self.serial_box = tk.Frame(self.frame, bg=WHITE)
        self._label(self.serial_box, "Porta da balança", 9, True).pack(anchor="w")
        self.port_box = ttk.Combobox(self.serial_box, textvariable=self.port, state="readonly",
                                     style="Agent.TCombobox", font=("Segoe UI", 10))
        self.port_box.pack(fill="x", pady=(5, 5))
        tk.Label(self.serial_box, textvariable=self.details, bg=WHITE, fg=GRAY,
                 wraplength=520, justify="left", anchor="w",
                 font=("Segoe UI", 9)).pack(fill="x")

        self.wifi_box = tk.Frame(self.frame, bg=WHITE)
        row = tk.Frame(self.wifi_box, bg=WHITE)
        row.pack(fill="x")
        ip_col = tk.Frame(row, bg=WHITE)
        ip_col.pack(side="left", fill="x", expand=True, padx=(0, 8))
        self._label(ip_col, "IP da balança ou do celular", 9, True).pack(anchor="w")
        ttk.Entry(ip_col, textvariable=self.tcp_host, font=("Segoe UI", 10)).pack(fill="x", pady=(5, 0), ipady=3)
        port_col = tk.Frame(row, bg=WHITE)
        port_col.pack(side="left")
        self._label(port_col, "Porta", 9, True).pack(anchor="w")
        ttk.Entry(port_col, textvariable=self.tcp_port, width=7, font=("Segoe UI", 10)).pack(pady=(5, 0), ipady=3)
        self._label(self.wifi_box, "Exemplo: 192.168.0.4 e porta 9101. Só funciona na rede local, nunca pela internet.",
                    9, color=GRAY).pack(anchor="w", pady=(5, 0))

        options = tk.Frame(self.frame, bg=WHITE)
        self.options_box = options
        options.pack(fill="x", pady=(12, 0))
        for title, variable, values in (
            ("Velocidade", self.baudrate,
             ("1200", "2400", "4800", "9600", "19200", "38400", "57600", "115200")),
            ("Unidade", self.unit, ("kg", "g")),
            ("Leitura", self.protocol, ("Fluxo contínuo", "Solicitar leitura (ENQ)")),
        ):
            column = tk.Frame(options, bg=WHITE)
            column.pack(side="left", fill="x", expand=True, padx=(0, 8))
            self._label(column, title, 9, True, GRAY).pack(anchor="w")
            ttk.Combobox(column, textvariable=variable, values=values, state="readonly",
                         style="Agent.TCombobox", font=("Segoe UI", 10)).pack(fill="x", pady=(5, 0))

        tools = tk.Frame(self.frame, bg=WHITE)
        tools.pack(fill="x", pady=(14, 0))
        tk.Button(tools, text="Conectar e salvar", command=self.connect,
                  bg=WINE, fg=WHITE, activebackground=ORANGE, activeforeground=WHITE,
                  relief="flat", bd=0, padx=12, pady=9, cursor="hand2",
                  font=("Segoe UI", 10, "bold")).pack(side="left", fill="x", expand=True, padx=(0, 8))
        tk.Button(tools, text="Desconectar", command=self.disconnect,
                  bg=WHITE, fg=WINE, highlightbackground=BORDER, highlightthickness=1,
                  relief="flat", bd=0, padx=12, pady=9, cursor="hand2",
                  font=("Segoe UI", 10, "bold")).pack(side="right")

        readout = tk.Frame(self.frame, bg="#F8F4F4", padx=13, pady=10)
        readout.pack(fill="x", pady=(13, 0))
        self._label(readout, "PESO LIDO", 9, True).pack(anchor="w")
        tk.Label(readout, textvariable=self.weight, bg="#F8F4F4", fg=WINE,
                 anchor="w", font=("Segoe UI", 23, "bold")).pack(anchor="w")
        tk.Label(readout, textvariable=self.status, bg="#F8F4F4", fg=GRAY,
                 anchor="w", font=("Segoe UI", 9)).pack(fill="x")
        tk.Label(readout, textvariable=self.sync_status, bg="#F8F4F4", fg=GRAY,
                 anchor="w", font=("Segoe UI", 9)).pack(fill="x", pady=(3, 0))

    def _toggle_source(self):
        if self.source.get() == WIFI:
            self.serial_box.pack_forget()
            self.wifi_box.pack(fill="x", before=self.options_box)
        else:
            self.wifi_box.pack_forget()
            self.serial_box.pack(fill="x", before=self.options_box)

    def _endereco(self):
        if self.source.get() == WIFI:
            return endereco_wifi(self.tcp_host.get(), self.tcp_port.get())
        return self.port.get().strip().upper()

    def reload_ports(self):
        ports = serial_ports()
        values = [p[0] for p in ports]
        current = self.port.get()
        if current.lower().startswith("tcp://"):
            current = ""
            self.port.set("")
        if current and current not in values:
            values.insert(0, current)
        self.port_box["values"] = values
        if not current and values:
            self.port.set(values[0])
        description = next((desc for port, desc in ports if port == self.port.get()), "")
        self.details.set(description if description else
                         "Nenhuma porta detectada. Conecte a balança ou instale o driver.")

    def connect(self):
        try:
            settings = ScaleSettings(port=self._endereco(), baudrate=int(self.baudrate.get()),
                                     protocol={
                                         "Fluxo contínuo": "continuo",
                                         "Solicitar leitura (ENQ)": "enq",
                                     }[self.protocol.get()], unit=self.unit.get())
            settings.validate()
            self.monitor.configure(settings)
            self.preferences.set(balanca_ativa=True, balanca_porta=settings.port,
                                 balanca_baudrate=settings.baudrate,
                                 balanca_protocolo=settings.protocol, balanca_unidade=settings.unit)
        except (ValueError, OSError) as exc:
            messagebox.showerror("Balança", str(exc), parent=self.frame.winfo_toplevel())

    def disconnect(self):
        self.monitor.stop()
        self.preferences.set(balanca_ativa=False)

    def refresh(self):
        if not self.frame.winfo_exists():
            return
        status, reading = self.monitor.snapshot()
        self.status.set(status)
        self.weight.set((f"{reading.kg:.3f}".replace(".", ",") + " kg") if reading else "— kg")
        if reading and not reading.stable:
            self.status.set(status + " · aguardando estabilizar")
        self.frame.after(450, self.refresh)
