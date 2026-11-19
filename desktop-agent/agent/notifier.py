"""Aviso de pedido novo no canto inferior direito do Windows, acima do relógio.

Cada pedido vira um cartão empilhado com "Receber" (aceita no sistema), "Ver no sistema" (abre o pedido
no navegador/app) e "Silenciar". Fica por cima das outras janelas, sem roubar o foco de quem digita.
"""
import ctypes
from decimal import Decimal, InvalidOperation
import tkinter as tk

WINE = "#651424"
ORANGE = "#FF5825"
WHITE = "#FFFFFF"
DARK = "#272727"
GRAY = "#6C6770"
BORDER = "#E8E3E4"
SUBTLE = "#F8F4F4"
WIDTH = 360
GAP = 10
MARGIN = 14
MAX_VISIBLE = 4


def money(value):
    try:
        number = Decimal(str(value or "0"))
    except (InvalidOperation, ValueError):
        number = Decimal("0")
    return "R$ " + f"{number:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")


def work_area(root):
    """Área útil da tela principal (sem a barra de tarefas)."""
    try:
        class RECT(ctypes.Structure):
            _fields_ = [("left", ctypes.c_long), ("top", ctypes.c_long),
                        ("right", ctypes.c_long), ("bottom", ctypes.c_long)]
        rect = RECT()
        if ctypes.windll.user32.SystemParametersInfoW(0x0030, 0, ctypes.byref(rect), 0):
            return rect.left, rect.top, rect.right, rect.bottom
    except (AttributeError, OSError):
        pass
    return 0, 0, root.winfo_screenwidth(), root.winfo_screenheight() - 48


class OrderToasts:
    def __init__(self, root, on_receive, on_open, on_silence, on_reprint):
        self.root = root
        self.on_receive = on_receive
        self.on_open = on_open
        self.on_silence = on_silence
        self.on_reprint = on_reprint
        self.cards = {}   # id do pedido -> dict(window, status, buttons)
        self.order = []   # ordem de chegada (mais antigo embaixo)

    # ---------- montagem ----------
    def _button(self, parent, text, command, primary=False):
        return tk.Button(
            parent, text=text, command=command, cursor="hand2", relief="flat", bd=0,
            font=("Segoe UI", 10, "bold"), padx=12, pady=8, takefocus=True,
            bg=ORANGE if primary else WHITE, fg=WHITE if primary else WINE,
            activebackground=WINE if primary else SUBTLE,
            activeforeground=WHITE if primary else WINE,
            highlightthickness=1, highlightbackground=ORANGE if primary else BORDER,
        )

    def show(self, order, needs_action=True, warning=""):
        pedido_id = int(order.get("id") or 0)
        if pedido_id in self.cards:
            if warning:
                self.set_message(pedido_id, warning, error=True)
            self.cards[pedido_id]["window"].lift()
            return
        win = tk.Toplevel(self.root)
        win.withdraw()
        win.overrideredirect(True)
        win.attributes("-topmost", True)
        win.configure(bg=WINE, padx=1, pady=1)  # moldura vinho de 1 px
        body = tk.Frame(win, bg=WHITE, padx=16, pady=12)
        body.pack(fill="both", expand=True)

        head = tk.Frame(body, bg=WHITE)
        head.pack(fill="x")
        title = ("Pedido novo #" if needs_action else "Pedido #") + str(order.get("numero", ""))
        tk.Label(head, text=title, bg=WHITE, fg=WINE, anchor="w",
                 font=("Segoe UI", 14, "bold")).pack(side="left")
        if str(order.get("origem") or "") == "ifood":
            tk.Label(head, text="iFood", bg=WINE, fg=WHITE, padx=6,
                     font=("Segoe UI", 8, "bold")).pack(side="left", padx=(8, 0))
        close = tk.Label(head, text="Fechar", bg=WHITE, fg=GRAY, cursor="hand2", font=("Segoe UI", 9, "underline"))
        if not needs_action:
            close.pack(side="right")
            close.bind("<Button-1>", lambda _e: self.close(pedido_id))

        kind = "Entrega" if order.get("tipo") == "entrega" else "Retirada"
        details = " · ".join(x for x in (str(order.get("cliente") or "").strip(), kind,
                                          money(order.get("total"))) if x)
        tk.Label(body, text=details, bg=WHITE, fg=DARK, anchor="w", justify="left",
                 wraplength=WIDTH - 40, font=("Segoe UI", 10)).pack(fill="x", pady=(4, 0))
        count = len(order.get("itens") or [])
        tk.Label(body, text=str(count) + (" item" if count == 1 else " itens"), bg=WHITE, fg=GRAY,
                 anchor="w", font=("Segoe UI", 9)).pack(fill="x")

        status = tk.Label(body, text="", bg=WHITE, fg=GRAY, anchor="w", justify="left",
                          wraplength=WIDTH - 40, font=("Segoe UI", 9))
        status.pack(fill="x", pady=(6, 0))

        actions = tk.Frame(body, bg=WHITE)
        actions.pack(fill="x", pady=(8, 0))
        buttons = []
        if needs_action:
            receive = self._button(actions, "Receber", lambda: self.on_receive(order), primary=True)
            receive.pack(side="left", fill="x", expand=True, padx=(0, 6))
            buttons.append(receive)
        see = self._button(actions, "Ver no sistema", lambda: self.on_open(order))
        see.pack(side="left", fill="x", expand=True)
        buttons.append(see)

        extra = tk.Frame(body, bg=WHITE)
        extra.pack(fill="x", pady=(6, 0))
        silence = None
        if needs_action:
            silence = tk.Label(extra, text="Silenciar este pedido", bg=WHITE, fg=GRAY, cursor="hand2",
                               font=("Segoe UI", 9, "underline"))
            silence.pack(side="left")
            silence.bind("<Button-1>", lambda _e: self._silence(pedido_id, order))
        reprint = tk.Label(extra, text="Imprimir de novo", bg=WHITE, fg=GRAY, cursor="hand2",
                           font=("Segoe UI", 9, "underline"))
        reprint.bind("<Button-1>", lambda _e: self.on_reprint(order))

        self.cards[pedido_id] = {"window": win, "status": status, "buttons": buttons,
                                 "silence": silence, "reprint": reprint}
        self.order.append(pedido_id)
        if warning:
            self.set_message(pedido_id, warning, error=True)
        if not needs_action:
            win.after(20000, lambda: self.close(pedido_id))
        self._layout()

    # ---------- estado ----------
    def _silence(self, pedido_id, order):
        card = self.cards.get(pedido_id)
        if card and card["silence"] is not None:
            card["silence"].config(text="Silenciado", cursor="arrow", font=("Segoe UI", 9))
            card["silence"].unbind("<Button-1>")
        self.on_silence(order)

    def set_busy(self, pedido_id, busy, text=""):
        card = self.cards.get(int(pedido_id))
        if not card:
            return
        for button in card["buttons"]:
            button.config(state="disabled" if busy else "normal")
        self.set_message(pedido_id, text)

    def set_message(self, pedido_id, text, error=False):
        card = self.cards.get(int(pedido_id))
        if not card:
            return
        card["status"].config(text=text, fg=WINE if error else GRAY,
                              font=("Segoe UI", 9, "bold" if error else "normal"))
        if error and not card["reprint"].winfo_ismapped():
            card["reprint"].pack(side="right")
        self._layout()

    def close(self, pedido_id):
        card = self.cards.pop(int(pedido_id), None)
        if int(pedido_id) in self.order:
            self.order.remove(int(pedido_id))
        if card and card["window"].winfo_exists():
            card["window"].destroy()
        self._layout()

    def close_all(self):
        for pedido_id in list(self.cards):
            self.close(pedido_id)

    def _layout(self):
        """Empilha de baixo para cima; o mais antigo fica mais perto do relógio."""
        left, top, right, bottom = work_area(self.root)
        y = bottom - MARGIN
        for index, pedido_id in enumerate(self.order):
            win = self.cards[pedido_id]["window"]
            if index >= MAX_VISIBLE:
                win.withdraw()
                continue
            win.update_idletasks()
            height = win.winfo_reqheight()
            y -= height
            if y < top:
                win.withdraw()
                continue
            win.geometry(f"{WIDTH}x{height}+{right - WIDTH - MARGIN}+{y}")
            win.deiconify()
            win.lift()
            y -= GAP
