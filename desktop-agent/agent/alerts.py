"""Pedidos esperando "Receber": o som repete até alguém aceitar (no Agent, no caixa ou em outro computador).

Sem Tk: a regra fica testável sozinha. A janela (notifier.py) e o laço do som (app.py) só perguntam a ela.
"""
import threading

# "recebido" = pedido Novo, ainda não aceito. Qualquer outra situação encerra o aviso sonoro.
AGUARDANDO = "recebido"
RING_INTERVAL_MS = 4000


class AlertBoard:
    def __init__(self):
        self._lock = threading.RLock()
        self._orders = {}      # id do pedido -> pedido
        self._silenced = set()

    def add(self, order):
        """Guarda o pedido; devolve True quando ele precisa ser aceito (toca até receber)."""
        try:
            pedido_id = int(order.get("id") or 0)
        except (TypeError, ValueError):
            return False
        # Pedido de teste (botão "Testar") usa id negativo e nunca vai ao servidor.
        if (pedido_id < 1 and not order.get("teste")) or pedido_id == 0 \
                or str(order.get("status") or AGUARDANDO) != AGUARDANDO:
            return False
        with self._lock:
            self._orders[pedido_id] = order
        return True

    def resolve(self, pedido_id):
        with self._lock:
            self._silenced.discard(int(pedido_id))
            return self._orders.pop(int(pedido_id), None) is not None

    def silence(self, pedido_id):
        with self._lock:
            if int(pedido_id) in self._orders:
                self._silenced.add(int(pedido_id))

    def ids(self):
        with self._lock:
            return sorted(self._orders)

    def waiting(self, pedido_id):
        with self._lock:
            return int(pedido_id) in self._orders

    def should_ring(self, sound_on):
        """Toca se o som está ligado e há pedido esperando que não foi silenciado."""
        if not sound_on:
            return False
        with self._lock:
            return any(i not in self._silenced for i in self._orders)

    def apply_status(self, rows):
        """Recebe [{id, status}] do servidor; devolve os ids aceitos/cancelados em outro lugar."""
        resolved = []
        for row in rows or []:
            if not isinstance(row, dict):
                continue
            try:
                pedido_id = int(row.get("id") or 0)
            except (TypeError, ValueError):
                continue
            if str(row.get("status") or "") != AGUARDANDO and self.resolve(pedido_id):
                resolved.append(pedido_id)
        return resolved

    def clear(self):
        with self._lock:
            self._orders.clear()
            self._silenced.clear()
