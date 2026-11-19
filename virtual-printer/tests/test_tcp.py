"""Teste ESC/POS via Wi-Fi ou USB tethering.
Uso: python test_tcp.py --host IP_DO_CELULAR
"""
import argparse
import socket

parser = argparse.ArgumentParser()
parser.add_argument("--host", required=True)
parser.add_argument("--port", type=int, default=9100)
args = parser.parse_args()
with socket.create_connection((args.host, args.port), timeout=5) as sock:
    sock.settimeout(3)
    sock.sendall(b"\x10\x04\x04")
    status = sock.recv(1)
    if not status:
        raise RuntimeError("Impressora nao respondeu DLE EOT 4")
    print("Status:", status.hex())
    sock.sendall(
        b"\x1b@\x1bE\x01DELIVERYZIM - TESTE\x1bE\x00\n"
        b"2x X-Bacon\n1x Refrigerante\nTOTAL R$ 50,00\n\n"
        b"\x1dV\x00"
    )
print("Cupom enviado. Confira o app Android.")
