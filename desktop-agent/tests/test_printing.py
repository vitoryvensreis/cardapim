import os
import tempfile
import unittest
from PIL import Image
from agent.printing import DOTS, logo_raster, receipt, money

class ReceiptTests(unittest.TestCase):
    def test_cupom_escpos(self):
        order = {
            "numero": 42, "tipo": "entrega", "cliente": "Joao",
            "itens": [{"nome": "X-Bacon", "quantidade": "2", "total": "48.90",
                       "observacao": "Sem cebola"}],
            "total": "48.90", "pagamento": "pix"
        }
        for paper in (58, 80):
            payload = receipt(order, paper)
            self.assertTrue(payload.startswith(b"\x1b@"))
            self.assertTrue(payload.endswith(b"\x1dV\x00"))
            self.assertIn(b"X-Bacon", payload)
            self.assertIn(b"Sem cebola", payload)
    def test_logo_oficial_vira_imagem_escpos_centralizada(self):
        order = {"numero": 1, "itens": [], "total": "0"}
        for paper in (58, 80):
            image = logo_raster(paper)
            self.assertTrue(image.startswith(b"\x1ba\x01\x1dv0\x00"), paper)
            row_bytes = image[7] | (image[8] << 8)
            height = image[9] | (image[10] << 8)
            self.assertLessEqual(row_bytes * 8, DOTS[paper])
            self.assertEqual(len(image), 11 + row_bytes * height + 4)
            self.assertTrue(image.endswith(b"\n\x1ba\x00"))
            with_logo = receipt(order, paper)
            self.assertIn(image, with_logo)
            self.assertNotIn(b"DELIVERYZIM", with_logo)
            without = receipt(order, paper, logo=False)
            self.assertNotIn(b"\x1dv0", without)
            self.assertIn(b"DELIVERYZIM", without)

    def test_logo_pixels_and_fallback(self):
        with tempfile.TemporaryDirectory() as folder:
            path = os.path.join(folder, "logo.png")
            img = Image.new("RGBA", (10, 2), (255, 255, 255, 0))
            img.putpixel((0, 0), (255, 255, 255, 255))
            img.putpixel((9, 1), (255, 255, 255, 255))
            img.save(path)
            data = logo_raster(80, path)
            self.assertEqual(data[7:11], bytes((2, 0, 2, 0)))
            self.assertEqual(data[11:15], bytes((0x80, 0x00, 0x00, 0x40)))
            self.assertEqual(logo_raster(80, os.path.join(folder, "nao-existe.png")), b"")

    def test_moeda(self):
        self.assertEqual(money("1234.56"), "R$ 1.234,56")

if __name__ == "__main__":
    unittest.main()
