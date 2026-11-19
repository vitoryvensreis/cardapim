"""Entrada do Deliveryzim Agent para Windows."""
import ctypes
import multiprocessing
import sys

MUTEX = "Local\\DeliveryzimAgent"  # o instalador usa o mesmo nome para fechar o Agent antes de atualizar


def ja_aberto():
    """Um Agent por usuário: abrir de novo (atalho ou Windows) não duplica avisos nem impressões."""
    try:
        kernel = ctypes.windll.kernel32
        kernel.CreateMutexW(None, False, MUTEX)
        return kernel.GetLastError() == 183  # ERROR_ALREADY_EXISTS
    except (AttributeError, OSError):
        return False


if __name__ == "__main__":
    multiprocessing.freeze_support()
    if ja_aberto():
        try:
            ctypes.windll.user32.MessageBoxW(
                None, "O Deliveryzim Agent já está aberto. Procure o ícone ao lado do relógio do Windows.",
                "Deliveryzim Agent", 0x40)
        except (AttributeError, OSError):
            pass
        sys.exit(0)
    from agent.app import DeliveryzimApp
    DeliveryzimApp().run()
