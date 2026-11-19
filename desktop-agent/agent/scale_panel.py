"""Configuração da balança integrada à tela pós-login do Deliveryzim Agent (CustomTkinter)."""
import tkinter as tk
from tkinter import messagebox

import customtkinter as ctk

from agent.scale import PORTA_WIFI, ScaleSettings, endereco_wifi, serial_ports

WINE = "#651424"
ORANGE = "#FF5825"
GRAY = "#6C6770"
DARK = "#272727"
WHITE = "#FFFFFF"
BORDER = "#E8E3E4"
SUBTLE = "#F8F4F4"
CABO = "Cabo (porta COM)"
WIFI = "Wi-Fi (rede local)"
PROTOCOLOS = {"continuo": "Envia o peso sozinha", "enq": "Responde quando pedimos (ENQ)"}
VELOCIDADES = ("1200", "2400", "4800", "9600", "19200", "38400", "57600", "115200")


class ScalePanel:
    """Mostra controles locais; a porta nunca é configurada dentro do PDV web."""

    def __init__(self, parent, preferences, monitor, sync_status, ui):
        self.preferences = preferences
        self.monitor = monitor
        self.sync_status = sync_status
        self.ui = ui  # componentes padrão do app (fontes, botões, campos)
        self.frame = ctk.CTkFrame(parent, fg_color=WHITE, corner_radius=0)
        saved = str(preferences.get("balanca_porta") or "")
        wifi = saved.lower().startswith("tcp://")
        host, _, porta = saved[6:].partition(":") if wifi else ("", "", "")
        # Cabo (porta COM) ou Wi-Fi (balança de rede ou app Android da balança virtual).
        self.source = tk.StringVar(value=WIFI if wifi else CABO)
        self.port = tk.StringVar(value="" if wifi else saved)
        self.tcp_host = tk.StringVar(value=host)
        self.tcp_port = tk.StringVar(value=porta or str(PORTA_WIFI))
        self.baudrate = tk.StringVar(value=str(preferences.get("balanca_baudrate") or 9600))
        self.protocol = tk.StringVar(value=PROTOCOLOS.get(
            str(preferences.get("balanca_protocolo") or "continuo"), PROTOCOLOS["continuo"]))
        self.unit = tk.StringVar(value=str(preferences.get("balanca_unidade") or "kg"))
        self.status = tk.StringVar(value="Balança não configurada")
        self.weight = tk.StringVar(value="— kg")
        self.details = tk.StringVar(value="")
        self._render()
        self._toggle_source()
        self.reload_ports()
        self.refresh()

    def _combo(self, parent, variable, values, command=None):
        return ctk.CTkComboBox(
            parent, variable=variable, values=list(values), state="readonly", height=38,
            corner_radius=6, border_width=1, border_color=BORDER, button_color=WINE,
            button_hover_color=ORANGE, fg_color=WHITE, text_color=DARK, font=self.ui._font(10),
            dropdown_font=self.ui._font(10), command=command,
        )

    def _render(self):
        ui = self.ui
        top = ctk.CTkFrame(self.frame, fg_color=WHITE, corner_radius=0)
        top.pack(fill="x")
        ui._label(top, "Balança", 12, DARK, True).pack(side="left")
        ui._label(self.frame, "Opcional. Ligue a balança no cabo ou pelo Wi-Fi da mesma rede.",
                  9, GRAY, wrap=520).pack(anchor="w", pady=(2, 10))

        ui._segmented(self.frame, (CABO, WIFI), self.source,
                      lambda _v: self._toggle_source()).pack(fill="x")

        self.serial_box = ctk.CTkFrame(self.frame, fg_color=WHITE, corner_radius=0)
        row = ctk.CTkFrame(self.serial_box, fg_color=WHITE, corner_radius=0)
        row.pack(fill="x", pady=(10, 0))
        ui._label(row, "Porta da balança", 10, DARK, True).pack(side="left")
        ui._button(row, "Procurar portas", self.reload_ports, primary=False, width=130,
                   height=30).pack(side="right")
        self.port_box = self._combo(self.serial_box, self.port, [])
        self.port_box.pack(fill="x", pady=(6, 4))
        ui._label(self.serial_box, "", 9, GRAY, wrap=520, textvariable=self.details).pack(fill="x")

        self.wifi_box = ctk.CTkFrame(self.frame, fg_color=WHITE, corner_radius=0)
        row = ctk.CTkFrame(self.wifi_box, fg_color=WHITE, corner_radius=0)
        row.pack(fill="x", pady=(10, 0))
        ip_col = ctk.CTkFrame(row, fg_color=WHITE, corner_radius=0)
        ip_col.pack(side="left", fill="x", expand=True, padx=(0, 8))
        ui._label(ip_col, "IP da balança ou do celular", 10, DARK, True).pack(anchor="w")
        ui._entry(ip_col, textvariable=self.tcp_host, placeholder_text="192.168.0.4").pack(fill="x", pady=(6, 0))
        port_col = ctk.CTkFrame(row, fg_color=WHITE, corner_radius=0)
        port_col.pack(side="left")
        ui._label(port_col, "Porta", 10, DARK, True).pack(anchor="w")
        ui._entry(port_col, textvariable=self.tcp_port, width=90).pack(pady=(6, 0))
        ui._label(self.wifi_box, "Só funciona na rede local, nunca pela internet.", 9, GRAY).pack(anchor="w", pady=(4, 0))

        self.options_box = ctk.CTkFrame(self.frame, fg_color=WHITE, corner_radius=0)
        self.options_box.pack(fill="x", pady=(10, 0))
        for column, (title, variable, values) in enumerate((
            ("Velocidade", self.baudrate, VELOCIDADES),
            ("Unidade", self.unit, ("kg", "g")),
            ("Como envia o peso", self.protocol, tuple(PROTOCOLOS.values())),
        )):
            self.options_box.grid_columnconfigure(column, weight=2 if column == 2 else 1, uniform="balanca")
            cell = ctk.CTkFrame(self.options_box, fg_color=WHITE, corner_radius=0)
            cell.grid(row=0, column=column, sticky="ew", padx=(0, 8) if column < 2 else 0)
            ui._label(cell, title, 9, GRAY, True).pack(anchor="w")
            self._combo(cell, variable, values).pack(fill="x", pady=(4, 0))

        actions = ctk.CTkFrame(self.frame, fg_color=WHITE, corner_radius=0)
        actions.pack(fill="x", pady=(12, 0))
        ui._button(actions, "Conectar e salvar", self.connect).pack(side="left", fill="x", expand=True, padx=(0, 8))
        ui._button(actions, "Desconectar", self.disconnect, primary=False, width=130).pack(side="right")

        readout = ctk.CTkFrame(self.frame, fg_color=SUBTLE, corner_radius=6)
        readout.pack(fill="x", pady=(12, 0))
        ui._label(readout, "Peso lido", 9, GRAY, True).pack(anchor="w", padx=14, pady=(10, 0))
        ctk.CTkLabel(readout, textvariable=self.weight, text_color=WINE, anchor="w",
                     font=ui._font(22, True)).pack(anchor="w", padx=14)
        ui._label(readout, "", 9, GRAY, textvariable=self.status).pack(fill="x", padx=14)
        ui._label(readout, "", 9, GRAY, textvariable=self.sync_status).pack(fill="x", padx=14, pady=(0, 10))

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
        self.port_box.configure(values=values)
        if not current and values:
            self.port.set(values[0])
        description = next((desc for port, desc in ports if port == self.port.get()), "")
        self.details.set(description if description else
                         "Nenhuma porta encontrada. Ligue a balança no cabo ou instale o driver do fabricante.")

    def connect(self):
        try:
            protocolo = next(k for k, v in PROTOCOLOS.items() if v == self.protocol.get())
            settings = ScaleSettings(port=self._endereco(), baudrate=int(self.baudrate.get()),
                                     protocol=protocolo, unit=self.unit.get())
            settings.validate()
            self.monitor.configure(settings)
            self.preferences.set(balanca_ativa=True, balanca_porta=settings.port,
                                 balanca_baudrate=settings.baudrate,
                                 balanca_protocolo=settings.protocol, balanca_unidade=settings.unit)
        except (ValueError, OSError, StopIteration) as exc:
            messagebox.showerror("Balança", str(exc) or "Confira os dados da balança.",
                                 parent=self.frame.winfo_toplevel())

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
