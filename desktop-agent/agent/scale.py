"""Leitura local de balanças seriais ASCII por COM (RS-232 ou USB/serial).

A primeira versão suporta tramas ASCII terminadas em LF, passivas ou
solicitadas com ENQ. Protocolos proprietários exigem adaptadores específicos.
Nenhum peso lido aqui autoriza cobrança sem validação metrológica/homologação.
"""
from dataclasses import dataclass
from decimal import Decimal, InvalidOperation
from collections import deque
import re
import threading
import time

import serial
from serial.tools import list_ports

VALID_PROTOCOLS = {"continuo", "enq"}
VALID_UNITS = {"kg", "g"}
VALID_BAUDS = {1200, 2400, 4800, 9600, 19200, 38400, 57600, 115200}
_NUMBER = re.compile(r"(?<![\w])([+-]?\s*\d+(?:[.,]\d+)?)\s*(kg|g)?\b", re.IGNORECASE)


@dataclass(frozen=True)
class ScaleSettings:
    port: str
    baudrate: int = 9600
    protocol: str = "continuo"
    unit: str = "kg"

    def validate(self):
        # Limitar acesso a portas locais; nunca interpretar URL de pySerial.
        if not re.fullmatch(r"COM[1-9][0-9]*", self.port.upper()):
            raise ValueError("Selecione uma porta COM válida do Windows.")
        if self.baudrate not in VALID_BAUDS:
            raise ValueError("Velocidade serial não suportada.")
        if self.protocol not in VALID_PROTOCOLS or self.unit not in VALID_UNITS:
            raise ValueError("Protocolo ou unidade inválidos.")


@dataclass(frozen=True)
class WeightReading:
    kg: Decimal
    stable: bool
    received_at: float
    raw: str


def serial_ports():
    """Portas disponíveis, identificadas pelo Windows. Não pressupõe balança."""
    return sorted(
        ((item.device, item.description or item.device) for item in list_ports.comports()
         if re.fullmatch(r"COM[1-9][0-9]*", item.device, re.IGNORECASE)),
        key=lambda item: (int(item[0][3:]), item[0].casefold()),
    )


def parse_weight(frame, fallback_unit="kg"):
    """Retorna (peso em kg, marca de estabilidade) ou None para quadro inválido.

    A estabilidade retornada é False para US/UNSTABLE; True para ST;
    None sem indicador. O monitor ainda requer amostras consistentes.
    """
    if fallback_unit not in VALID_UNITS:
        raise ValueError("Unidade de peso inválida.")
    text = frame.decode("ascii", errors="ignore") if isinstance(frame, bytes) else str(frame)
    text = text.strip().replace("\x00", "")
    if not text or len(text) > 128:
        return None
    if re.search(r"\b(OL|OVERLOAD|ERROR|ERR|SAT)\b", text, re.I):
        return None
    # Normaliza espaços entre sinal e número.
    text = re.sub(r"([+-])\s+(?=\d)", r"\1", text)
    matches = list(_NUMBER.finditer(text))
    if not matches:
        return None
    match = matches[-1]
    try:
        value = Decimal(match.group(1).replace(" ", "").replace(",", "."))
    except InvalidOperation:
        return None
    unit = (match.group(2) or fallback_unit).lower()
    if unit == "g":
        value /= Decimal("1000")
    # Faixa conservadora para porções; nunca aceitar valores negativos.
    if not value.is_finite() or not Decimal("0") <= value <= Decimal("100"):
        return None
    unstable = bool(re.search(r"\b(US|UNST|UNSTABLE|MOTION)\b", text, re.I))
    stable_mark = bool(re.search(r"\b(ST|STABLE)\b", text, re.I))
    return value, (False if unstable else True if stable_mark else None)


class ScaleMonitor:
    """Conexão serial persistente, reconexão e última leitura estável local."""

    def __init__(self):
        self._lock = threading.RLock()
        self._stop = threading.Event()
        self._thread = None
        self._settings = None
        self._status = "Não configurada"
        self._reading = None

    def snapshot(self):
        with self._lock:
            reading = self._reading
            # Não mostrar uma leitura antiga como se estivesse ao vivo.
            if reading is not None and time.monotonic() - reading.received_at > 3:
                reading = None
            return self._status, reading

    def _publish(self, status=None, reading=None):
        with self._lock:
            if status is not None:
                self._status = status
            self._reading = reading

    def configure(self, settings):
        settings.validate()
        self.stop()
        with self._lock:
            self._settings = settings
            self._status = "Conectando à " + settings.port
        self._stop = threading.Event()
        self._thread = threading.Thread(
            target=self._run, args=(settings, self._stop),
            name="deliveryzim-balanca", daemon=True,
        )
        self._thread.start()

    def stop(self):
        self._stop.set()
        if self._thread and self._thread.is_alive():
            self._thread.join(timeout=2.0)
        self._thread = None
        self._publish("Desconectada")

    def _run(self, settings, stop):
        while not stop.is_set():
            try:
                with serial.Serial(
                    settings.port.upper(), settings.baudrate,
                    timeout=0.4, write_timeout=0.5,
                    bytesize=serial.EIGHTBITS, parity=serial.PARITY_NONE,
                    stopbits=serial.STOPBITS_ONE,
                ) as port:
                    self._publish("Conectada — aguardando leitura")
                    recent = deque(maxlen=3)
                    last_frame = time.monotonic()
                    last_request = 0.0
                    while not stop.is_set():
                        now = time.monotonic()
                        if settings.protocol == "enq" and now - last_request >= 0.5:
                            port.write(b"\x05")
                            last_request = now
                        frame = port.read_until(b"\n", 128)
                        if not frame:
                            if time.monotonic() - last_frame > 3:
                                recent.clear()
                                self._publish("Conectada — sem dados recentes")
                            continue
                        parsed = parse_weight(frame, settings.unit)
                        if parsed is None:
                            recent.clear()
                            self._publish("Conectada — quadro não reconhecido")
                            continue
                        last_frame = time.monotonic()
                        kg, device_stable = parsed
                        recent.append(kg)
                        consistent = (len(recent) == 3 and
                            max(recent) - min(recent) <= Decimal("0.005"))
                        stable = consistent and device_stable is not False
                        self._publish(
                            "Peso estável" if stable else "Aguardando estabilizar",
                            WeightReading(kg, stable, time.monotonic(),
                                          frame.decode("ascii", errors="replace").strip()[:80]),
                        )
            except (serial.SerialException, OSError, ValueError) as exc:
                self._publish("Sem conexão: " + str(exc)[:100])
                stop.wait(2)
        self._publish("Desconectada")
