"""Configuração da balança integrada à tela pós-login do Deliveryzim Agent."""
import tkinter as tk
from tkinter import ttk, messagebox

from agent.scale import ScaleSettings, serial_ports

WINE = "#651424"
ORANGE = "#FF5825"
GRAY = "#6C6770"
WHITE = "#FFFFFF"
BORDER = "#E8E3E4"


class ScalePanel:
    """Mostra controles locais; a porta nunca é configurada dentro do PDV web."""

    def __init__(self, parent, preferences, monitor, sync_status):
        self.preferences = preferences
        self.monitor = monitor
        self.sync_status = sync_status
        self.frame = tk.Frame(parent, bg=WHITE)
        self.port = tk.StringVar(value=str(preferences.get("balanca_porta") or ""))
        self.baudrate = tk.StringVar(value=str(preferences.get("balanca_baudrate") or 9600))
        self.protocol = tk.StringVar(value=str(preferences.get("balanca_protocolo") or "continuo"))
        self.unit = tk.StringVar(value=str(preferences.get("balanca_unidade") or "kg"))
        self.status = tk.StringVar(value="Balança não configurada")
        self.weight = tk.StringVar(value="— kg")
        self.details = tk.StringVar(value="")
        self._render()
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
        self._label(self.frame, "Conecte uma balança serial ao computador.",
                    9, color=GRAY).pack(anchor="w", pady=(4, 12))

        line = tk.Frame(self.frame, bg=WHITE)
        line.pack(fill="x")
        self._label(line, "Porta da balança", 9, True).pack(anchor="w")
        self.port_box = ttk.Combobox(line, textvariable=self.port, state="readonly",
                                     style="Agent.TCombobox", font=("Segoe UI", 10))
        self.port_box.pack(fill="x", pady=(5, 5))
        self._label(line, "", 9, color=GRAY).pack_forget()
        tk.Label(self.frame, textvariable=self.details, bg=WHITE, fg=GRAY,
                 wraplength=520, justify="left", anchor="w",
                 font=("Segoe UI", 9)).pack(fill="x")

        options = tk.Frame(self.frame, bg=WHITE)
        options.pack(fill="x", pady=(12, 0))
        for title, variable, values in (
            ("Velocidade", self.baudrate,
             ("1200", "2400", "4800", "9600", "19200", "38400", "57600", "115200")),
            ("Unidade", self.unit, ("kg", "g")),
            ("Comunicação", self.protocol, ("continuo", "enq")),
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
        self._label(readout, "", 22, True).pack_forget()
        tk.Label(readout, textvariable=self.weight, bg="#F8F4F4", fg=WINE,
                 anchor="w", font=("Segoe UI", 23, "bold")).pack(anchor="w")
        tk.Label(readout, textvariable=self.status, bg="#F8F4F4", fg=GRAY,
                 anchor="w", font=("Segoe UI", 9)).pack(fill="x")
        tk.Label(readout, textvariable=self.sync_status, bg="#F8F4F4", fg=GRAY,
                 anchor="w", font=("Segoe UI", 9)).pack(fill="x", pady=(3, 0))

    def reload_ports(self):
        ports = serial_ports()
        values = [p[0] for p in ports]
        current = self.port.get()
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
            settings = ScaleSettings(port=self.port.get().strip(), baudrate=int(self.baudrate.get()),
                                     protocol=self.protocol.get(), unit=self.unit.get())
            settings.validate()
            self.monitor.configure(settings)
            self.preferences.set(balanca_ativa=True, balanca_porta=settings.port.upper(),
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
