"""Persistencia local, idempotencia e segredo de dispositivo protegido com Windows DPAPI."""
import json
import os
from pathlib import Path
import sqlite3
import threading
import win32crypt

DIRECTORY = Path(os.getenv("LOCALAPPDATA", str(Path.home()))) / "DeliveryzimAgent"
DIRECTORY.mkdir(parents=True, exist_ok=True)
PREFERENCES = DIRECTORY / "preferencias.json"
CREDENTIAL = DIRECTORY / "dispositivo.dpapi"
DATABASE = DIRECTORY / "fila.sqlite3"


class Preferences:
    def __init__(self):
        self.lock = threading.RLock()
        self.data = {
            "automatico": False, "impressora": "", "papel": 80,
            "iniciar_windows": False, "cursor": 0, "restaurante": "", "device_id": 0,
            "som_ativo": True, "configurado": False,
            "balanca_ativa": False, "balanca_porta": "", "balanca_baudrate": 9600,
            "balanca_protocolo": "continuo", "balanca_unidade": "kg",
        }
        if PREFERENCES.exists():
            try:
                value = json.loads(PREFERENCES.read_text(encoding="utf-8"))
                if isinstance(value, dict):
                    self.data.update({k: v for k, v in value.items() if k in self.data})
            except (OSError, ValueError):
                pass

    def get(self, name):
        with self.lock:
            return self.data.get(name)

    def set(self, **kwargs):
        with self.lock:
            self.data.update(kwargs)
            temp = PREFERENCES.with_suffix(".tmp")
            temp.write_text(json.dumps(self.data, ensure_ascii=False, indent=2), encoding="utf-8")
            temp.replace(PREFERENCES)


def save_credential(token):
    encrypted = win32crypt.CryptProtectData(token.encode("utf-8"), "Deliveryzim Agent", None, None, None, 0)
    temporary = CREDENTIAL.with_suffix(".tmp")
    temporary.write_bytes(encrypted)
    temporary.replace(CREDENTIAL)


def load_credential():
    try:
        return win32crypt.CryptUnprotectData(CREDENTIAL.read_bytes(), None, None, None, 0)[1].decode("utf-8")
    except (OSError, ValueError, Exception):
        return ""


def delete_credential():
    CREDENTIAL.unlink(missing_ok=True)


class EventStore:
    """A mesma entrada nao deve ser alertada/impressa duas vezes apos reconexao."""
    def __init__(self):
        self.lock = threading.RLock()
        self.db = sqlite3.connect(str(DATABASE), check_same_thread=False)
        self.db.execute("PRAGMA journal_mode=WAL")
        self.db.execute("""CREATE TABLE IF NOT EXISTS trabalhos (
            evento_id INTEGER PRIMARY KEY,
            payload TEXT NOT NULL,
            estado TEXT NOT NULL,
            atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )""")
        self.db.commit()

    def receive(self, item):
        with self.lock:
            result = self.db.execute(
                "INSERT OR IGNORE INTO trabalhos (evento_id,payload,estado) VALUES (?,?,?)",
                (int(item["evento_id"]), json.dumps(item, ensure_ascii=False), "pendente"),
            )
            self.db.commit()
            return result.rowcount == 1

    def update(self, event_id, state):
        with self.lock:
            self.db.execute(
                "UPDATE trabalhos SET estado=?, atualizado_em=CURRENT_TIMESTAMP WHERE evento_id=?",
                (state, int(event_id)),
            )
            self.db.commit()

    def pending(self):
        with self.lock:
            rows = self.db.execute(
                "SELECT payload, estado FROM trabalhos WHERE estado IN ('pendente','erro','imprimindo') ORDER BY evento_id LIMIT 50"
            ).fetchall()
            return [(json.loads(x), state) for x, state in rows]

    def clear_on_relink(self):
        with self.lock:
            self.db.execute("DELETE FROM trabalhos")
            self.db.commit()
