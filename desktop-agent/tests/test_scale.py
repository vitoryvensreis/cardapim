"""Protocolos ASCII de balanças sem precisar de hardware nos testes."""
import unittest
import time
from decimal import Decimal
from unittest.mock import patch
from agent.scale import ScaleSettings, ScaleMonitor, endereco_wifi, parse_weight, serial_ports


class ParseScaleTests(unittest.TestCase):
    def test_ascii_kg_stable(self):
        self.assertEqual(parse_weight(b"ST,GS,+0000.350kg\r\n"), (Decimal("0.350"), True))

    def test_grams(self):
        self.assertEqual(parse_weight(b"ST,+00350g\r\n"), (Decimal("0.350"), True))

    def test_fallback_unit(self):
        self.assertEqual(parse_weight(b"0,275\r\n", "kg"), (Decimal("0.275"), None))
        self.assertEqual(parse_weight(b"350\r\n", "g"), (Decimal("0.35"), None))

    def test_unstable(self):
        self.assertEqual(parse_weight(b"US,GS,+0.351kg"), (Decimal("0.351"), False))

    def test_bad_frames(self):
        for frame in (b"", b"OVERLOAD", b"ERR", b"ST,-0.5kg",
                      b"101.2kg", b"no weight", b"0.25kg" * 50):
            self.assertIsNone(parse_weight(frame))

    def test_validation(self):
        ScaleSettings("COM3", baudrate=9600).validate()
        for port in ("", "tcp://192.168.1.10", "COM0", "COM3;ls"):
            with self.assertRaises(ValueError):
                ScaleSettings(port).validate()
        with self.assertRaises(ValueError):
            ScaleSettings("COM3", protocol="proprietario").validate()
        # Wi-Fi na rede local (balança de rede ou app Android) volta a ser aceito.
        ScaleSettings("tcp://192.168.0.4:9101").validate()
        ScaleSettings("TCP://192.168.0.4:9101").validate()

    def test_endereco_wifi(self):
        self.assertEqual(endereco_wifi("192.168.0.4", "9101"), "tcp://192.168.0.4:9101")
        self.assertEqual(endereco_wifi("192.168.0.4:9200", "9101"), "tcp://192.168.0.4:9200")
        self.assertEqual(endereco_wifi(" tcp://10.0.0.7 ", ""), "tcp://10.0.0.7:9101")

    def test_ports_filter(self):
        class Port:
            def __init__(self, device):
                self.device, self.description = device, "USB serial"

        with patch("agent.scale.list_ports.comports", return_value=[
            Port("COM11"), Port("COM2"), Port("/dev/ttyUSB0"),
        ]):
            self.assertEqual([port for port, _ in serial_ports()], ["COM2", "COM11"])

    def test_stale_weight_not_returned(self):
        monitor = ScaleMonitor()
        monitor._publish("Conectada")
        self.assertEqual(monitor.snapshot(), ("Conectada", None))
        monitor.stop()

    def test_serial_monitor_marks_stable_only_after_consistent_frames(self):
        frames = [b"ST,+0.350kg\r\n", b"ST,+0.351kg\r\n",
                  b"ST,+0.350kg\r\n", b"ST,+0.350kg\r\n"]

        class Port:
            def __enter__(self):
                return self

            def __exit__(self, *_):
                pass

            def read_until(self, *_):
                if frames:
                    return frames.pop(0)
                time.sleep(0.02)
                return b""

            def write(self, data):
                return len(data)

        monitor = ScaleMonitor()
        with patch("agent.scale.serial.Serial", return_value=Port()):
            monitor.configure(ScaleSettings("COM7"))
            deadline = time.monotonic() + 1.0
            try:
                while time.monotonic() < deadline:
                    status, reading = monitor.snapshot()
                    if reading and reading.stable:
                        self.assertEqual(reading.kg, Decimal("0.350"))
                        self.assertEqual(status, "Peso estável")
                        break
                    time.sleep(0.02)
                else:
                    self.fail("Leitura estável não foi publicada")
            finally:
                monitor.stop()



if __name__ == "__main__":
    unittest.main()
