"""Protocolos ASCII de balanças sem precisar de hardware nos testes."""
import unittest
from decimal import Decimal
from unittest.mock import patch
from agent.scale import ScaleSettings, ScaleMonitor, parse_weight, serial_ports


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


if __name__ == "__main__":
    unittest.main()
