"""Alerta sonoro original de pedidos, independente dos sons do tema Windows."""
from pathlib import Path
import math
import os
import struct
import tempfile
import wave
import winsound

SAMPLE_RATE = 22050
NOTES = ((880, 0.16), (1100, 0.17), (1320, 0.28))


def sound_path():
    """Armazena WAV pequeno somente no perfil do usuário, sem rede ou mixer externo."""
    folder = Path(os.getenv("LOCALAPPDATA", tempfile.gettempdir())) / "DeliveryzimAgent"
    folder.mkdir(parents=True, exist_ok=True)
    return folder / "alerta-pedido-v1.wav"


def generate(path):
    with wave.open(str(path), "wb") as audio:
        audio.setnchannels(1)
        audio.setsampwidth(2)
        audio.setframerate(SAMPLE_RATE)
        frames = bytearray()
        for frequency, duration in NOTES:
            n = int(SAMPLE_RATE * duration)
            for i in range(n):
                envelope = min(1.0, i / (SAMPLE_RATE * .012),
                               (n - i) / (SAMPLE_RATE * .045))
                sample = int(15500 * max(0.0, envelope)
                             * math.sin(2 * math.pi * frequency * i / SAMPLE_RATE))
                frames.extend(struct.pack("<h", sample))
            frames.extend(b"\x00\x00" * int(SAMPLE_RATE * .055))
        audio.writeframes(frames)


def play():
    """Executa sem bloquear interface; fallback quando WAV não estiver disponível."""
    try:
        path = sound_path()
        if not path.exists() or path.stat().st_size < 1000:
            generate(path)
        winsound.PlaySound(str(path),
                           winsound.SND_FILENAME | winsound.SND_ASYNC | winsound.SND_NODEFAULT)
        return True
    except (OSError, RuntimeError, ValueError):
        try:
            winsound.MessageBeep(winsound.MB_ICONEXCLAMATION)
            return True
        except (OSError, RuntimeError):
            return False
