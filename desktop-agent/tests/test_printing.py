import os
import tempfile
import unittest
from PIL import Image
from agent.printing import DOTS, MARGEM_PADRAO, fim_do_cupom, job_receipt, logo_raster, receipt, money

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

    def test_espaco_antes_do_corte(self):
        # Avanca o papel antes da guilhotina (ESC d n) para nao cortar a ultima linha.
        self.assertEqual(fim_do_cupom(), b"\x1bd" + bytes((MARGEM_PADRAO,)) + b"\x1dV\x00")
        self.assertEqual(fim_do_cupom(10), b"\x1bd\x0a\x1dV\x00")
        self.assertEqual(fim_do_cupom("x"), fim_do_cupom())
        self.assertEqual(fim_do_cupom(99), fim_do_cupom())
        job = {"blocos": [{"t": "texto", "texto": "Fim"}]}
        self.assertTrue(job_receipt(job, 80, False, 7).endswith(b"\x1bd\x07\x1dV\x00"))
        self.assertTrue(receipt({"numero": 1, "itens": []}, 80, False, 7).endswith(b"\x1bd\x07\x1dV\x00"))

    def test_moeda(self):
        self.assertEqual(money("1234.56"), "R$ 1.234,56")

if __name__ == "__main__":
    unittest.main()


class ImageReceiptTests(unittest.TestCase):
    """Cupom desenhado pelo sistema (0.9.0+): o Agent só converte a imagem e corta."""

    def _png(self, width, height, black_rows=()):
        import base64
        import io
        image = Image.new("L", (width, height), 255)
        for y in black_rows:
            for x in range(width):
                image.putpixel((x, y), 0)
        buffer = io.BytesIO()
        image.save(buffer, format="PNG")
        return base64.b64encode(buffer.getvalue()).decode()

    def test_imagem_vira_raster_em_faixas_e_corta(self):
        from agent.printing import image_receipt
        payload = image_receipt(self._png(576, 300, black_rows=(0,)), 80)
        self.assertTrue(payload.startswith(b"\x1b@\x1ba\x01\x1dv0\x00"))
        self.assertTrue(payload.endswith(b"\x1ba\x00\x1dV\x00"))
        self.assertEqual(payload.count(b"\x1dv0\x00"), 3)  # 128 + 128 + 44 linhas
        self.assertEqual(payload[9:11], bytes((72, 0)))  # 576 / 8 bytes por linha
        self.assertEqual(payload[13:13 + 72], b"\xff" * 72)  # primeira linha toda preta

    def test_imagem_larga_demais_reduz_ao_papel(self):
        from agent.printing import image_receipt
        payload = image_receipt(self._png(576, 100), 58)
        self.assertEqual(payload[9:11], bytes((48, 0)))  # 384 / 8

    def test_imagem_invalida(self):
        from agent.printing import image_receipt
        with self.assertRaises(RuntimeError):
            image_receipt("isto nao e imagem", 80)
