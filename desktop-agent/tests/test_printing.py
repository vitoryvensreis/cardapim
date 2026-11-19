import unittest
from agent.printing import receipt, money

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
    def test_moeda(self):
        self.assertEqual(money("1234.56"), "R$ 1.234,56")

if __name__ == "__main__":
    unittest.main()
