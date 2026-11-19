"""Leitura local de balanças seriais ASCII por COM (RS-232 ou USB/serial).

A primeira versão suporta tramas ASCII terminadas em LF, passivas ou
solicitadas com ENQ. Protocolos proprietários exigem adaptadores específicos.
Nenhum peso lido aqui autoriza cobrança sem validação metrológica/homologação.
"""
from dataclasses import dataclass
from decimal import Decimal, InvalidOperation
from collections import deque
import re
import ipaddress
import socket
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

    def tcp_address(self):
        """Somente endpoints IPv4 privados explícitos; sem DNS/Internet ou credenciais."""
        if not self.port.lower().startswith("tcp://"):
            return None
        match = re.fullmatch(r"tcp://((?:\d{1,3}\.){3}\d{1,3}):(\d{2,5})", self.port.lower())
        if not match:
            raise ValueError("Use o IP do celular no formato tcp://192.168.1.20:9101.")
        try:
            host = ipaddress.IPv4Address(match.group(1))
        except ipaddress.AddressValueError as exc:
            raise ValueError("Endereço IPv4 inválido.") from exc
        ranges = (
            ipaddress.IPv4Network("10.0.0.0/8"),
            ipaddress.IPv4Network("172.16.0.0/12"),
            ipaddress.IPv4Network("192.168.0.0/16"),
            ipaddress.IPv4Network("127.0.0.0/8"),
        )
        port = int(match.group(2))
        if not any(host in network for network in ranges) or not 1024 <= port <= 65535:
            raise ValueError("Aceitamos apenas IP privado e porta 1024–65535.")
        return str(host), port

    def validate(self):
        # COM físico ou Wi-Fi de teste; jamais interpretar URLs como portas pySerial.
        if self.tcp_address() is None and not re.fullmatch(r"COM[1-9][0-9]*", self.port.upper()):
            raise ValueError("Selecione uma porta COM ou o IP local do celular.")
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
    if isinstance(frame, bytes) and len(frame) == 7 and frame[0] == 2 and frame[-1] == 3:
        payload = frame[1:6]
        # Toledo Prt5 / Urano PROT3: 5 dígitos inteiros de GRAMAS, sem ponto.
        if payload.isdigit():
            return Decimal(int(payload)) / Decimal("1000"), True
        # IIIII = instável; NNNNN = negativo; SSSSS = sobrecarga.
        return None
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


class StreamFrames:
    """Delimita tramas TCP arbitrariamente fragmentadas: STX/ETX ou CR/LF."""
    def __init__(self):
        self.buffer = bytearray()

    def feed(self, chunk):
        results = []
        for value in chunk:
            if value == 2:  # STX inicia nova trama binária.
                self.buffer = bytearray([2])
                continue
            if not self.buffer and (value == 3 or value in (10, 13)):
                continue
            self.buffer.append(value)
            if len(self.buffer) > 128:
                self.buffer.clear()
                continue
            if self.buffer[0] == 2 and value == 3:
                results.append(bytes(self.buffer))
                self.buffer.clear()
            elif self.buffer[0] != 2 and value == 10:
                results.append(bytes(self.buffer))
                self.buffer.clear()
        return results


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
        endpoint = settings.tcp_address()
        if endpoint is not None:
            self._run_tcp(settings, stop, endpoint)
            return
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

    def _run_tcp(self, settings, stop, endpoint):
        """Socket local, sem alterar a leitura serial física nem abrir listener no PC."""
        host, port = endpoint
        while not stop.is_set():
            try:
                with socket.create_connection((host, port), timeout=2) as conn:
                    conn.settimeout(0.4)
                    self._publish("Simulador conectado — somente teste")
                    decoder = StreamFrames()
                    recent = deque(maxlen=3)
                    last_frame = time.monotonic()
                    last_request = 0.0
                    while not stop.is_set():
                        now = time.monotonic()
                        if settings.protocol == "enq" and now - last_request >= 0.5:
                            conn.sendall(b"\x05")
                            last_request = now
                        try:
                            raw = conn.recv(256)
                        except socket.timeout:
                            raw = None
                        if raw == b"":
                            raise ConnectionError("Celular desconectado")
                        if raw:
                            for frame in decoder.feed(raw):
                                parsed = parse_weight(frame, settings.unit)
                                if parsed is None:
                                    recent.clear()
                                    self._publish("Simulador — leitura instável ou inválida")
                                    continue
                                last_frame = time.monotonic()
                                kg, indicated_stable = parsed
                                recent.append(kg)
                                consistent = (len(recent) == 3 and
                                    max(recent) - min(recent) <= Decimal("0.005"))
                                stable = consistent and indicated_stable is not False
                                self._publish(
                                    "SIMULAÇÃO — peso estável" if stable else
                                    "SIMULAÇÃO — estabilizando",
                                    WeightReading(kg, stable, time.monotonic(),
                                        repr(frame[:80])),
                                )
                        if time.monotonic() - last_frame > 3:
                            recent.clear()
                            self._publish("Simulador conectado — sem dados recentes")
            except (OSError, ConnectionError, ValueError) as exc:
                self._publish("Simulador indisponível: " + str(exc)[:90])
                stop.wait(2)
        self._publish("Desconectada")
