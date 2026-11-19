"""Testes locais de balança virtual: TCP, protocolos e limites de conexão."""
import socket
import threading
import time
import unittest
from decimal import Decimal

from agent.scale import ScaleSettings, ScaleMonitor, StreamFrames, parse_weight


class VirtualScaleTests(unittest.TestCase):
    def test_toledo_and_urano_frames(self):
        self.assertEqual(parse_weight(b"\x0200350\x03"), (Decimal("0.350"), True))
        self.assertEqual(parse_weight(b"\x0299999\x03"), (Decimal("99.999"), True))
        for packet in (b"\x02IIIII\x03", b"\x02NNNNN\x03", b"\x02SSSSS\x03"):
            self.assertIsNone(parse_weight(packet))
        self.assertEqual(parse_weight(b"ST,GS,+0.350kg\r\n"), (Decimal("0.350"), True))

    def test_partial_and_multiple_frames(self):
        parser = StreamFrames()
        self.assertEqual(parser.feed(b"\x0200"), [])
        self.assertEqual(parser.feed(b"350\x03\x02IIIII\x03ST,+0.350kg\r"),
                         [b"\x0200350\x03", b"\x02IIIII\x03"])
        self.assertEqual(parser.feed(b"\n"), [b"ST,+0.350kg\r\n"])

    def test_reject_public_network_and_bad_port(self):
        for address in ("tcp://8.8.8.8:9101", "tcp://192.168.0.20:80",
                        "tcp://example.com:9101", "tcp://192.168.1.2:9101/path",
                        "tcp://999.999.999.999:9101", "tcp://192.168.1.2:0"):
            with self.subTest(address=address), self.assertRaises(ValueError):
                ScaleSettings(address).validate()
        self.assertEqual(ScaleSettings("tcp://192.168.1.2:9101").tcp_address(),
                         ("192.168.1.2", 9101))
        self.assertEqual(ScaleSettings("tcp://127.0.0.1:9101").tcp_address(),
                         ("127.0.0.1", 9101))

    def test_tcp_ascii_end_to_end(self):
        server = socket.socket()
        server.bind(("127.0.0.1", 0))
        server.listen(1)
        port = server.getsockname()[1]
        ready = threading.Event()

        def sender():
            ready.set()
            try:
                conn, _ = server.accept()
                with conn:
                    for _ in range(8):
                        conn.sendall(b"ST,GS,+0.350kg\r\n")
                        time.sleep(0.09)
            finally:
                server.close()

        worker = threading.Thread(target=sender, daemon=True)
        worker.start()
        ready.wait(1)
        monitor = ScaleMonitor()
        try:
            monitor.configure(ScaleSettings(f"tcp://127.0.0.1:{port}"))
            deadline = time.monotonic() + 3
            while time.monotonic() < deadline:
                status, reading = monitor.snapshot()
                if reading and reading.stable:
                    self.assertEqual(reading.kg, Decimal("0.350"))
                    self.assertIn("Wi-Fi", status)
                    break
                time.sleep(0.03)
            else:
                self.fail("TCP não publicou peso estável após três frames")
        finally:
            monitor.stop()
            worker.join(timeout=1)

    def test_toledo_requires_enq(self):
        server = socket.socket()
        server.bind(("127.0.0.1", 0))
        server.listen(1)
        port = server.getsockname()[1]
        requests = []

        def responder():
            try:
                conn, _ = server.accept()
                with conn:
                    conn.settimeout(3)
                    while len(requests) < 4:
                        data = conn.recv(1)
                        if not data:
                            break
                        if data == b"\x05":
                            requests.append(data)
                            # Fracionar a trama para testar o decoder.
                            conn.sendall(b"\x0200")
                            conn.sendall(b"350\x03")
            finally:
                server.close()

        worker = threading.Thread(target=responder, daemon=True)
        worker.start()
        monitor = ScaleMonitor()
        try:
            monitor.configure(ScaleSettings(f"tcp://127.0.0.1:{port}", protocol="enq"))
            deadline = time.monotonic() + 4
            while time.monotonic() < deadline:
                _, reading = monitor.snapshot()
                if reading and reading.stable:
                    self.assertEqual(reading.kg, Decimal("0.350"))
                    self.assertGreaterEqual(len(requests), 3)
                    break
                time.sleep(0.03)
            else:
                self.fail("Toledo ENQ não retornou três leituras estáveis")
        finally:
            monitor.stop()
            worker.join(timeout=1)


if __name__ == "__main__":
    unittest.main()
