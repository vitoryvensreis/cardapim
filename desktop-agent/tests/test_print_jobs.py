"""Fila de impressão: cupons pedidos no caixa/garçom ("Imprimir") e o resultado devolvido ao sistema."""
import unittest
from unittest.mock import MagicMock, patch

from agent.api import ApiClient, AuthenticationError
from agent.printing import job_receipt, _pair, _wrap

JOB = {
    "id": 7, "titulo": "PRÉ-CONTA · Mesa 5",
    "blocos": [
        {"t": "centro", "texto": "Restaurante do Zé"},
        {"t": "linha"},
        {"t": "titulo", "texto": "MESA 5"},
        {"t": "centro", "texto": "Comanda: João"},
        {"t": "destaque", "texto": "PRÉ-CONTA · Pedido #42"},
        {"t": "item", "qtd": "2x", "nome": "X-Bacon", "valor": "R$ 40,00",
         "detalhes": ["Bacon extra", "Obs.: sem cebola"]},
        {"t": "destaque", "texto": "OBSERVAÇÃO"},
        {"t": "texto", "texto": "Para viagem"},
        {"t": "valor", "rotulo": "TOTAL", "valor": "R$ 40,00", "forte": True},
        {"t": "desconhecido", "texto": "Nunca some"},
    ],
}


class JobReceiptTests(unittest.TestCase):
    def test_blocos_viram_escpos(self):
        for paper in (58, 80):
            data = job_receipt(JOB, paper, logo=False)
            self.assertTrue(data.startswith(b"\x1b@"))
            self.assertTrue(data.endswith(b"\x1dV\x00"))
            self.assertIn(b"\x1b!\x30" + "MESA 5".encode("cp850") + b"\n\x1b!\x00", data)
            for texto in ("Comanda: João", "X-Bacon", "Bacon extra", "Obs.: sem cebola",
                          "OBSERVAÇÃO", "Para viagem", "Nunca some", "R$ 40,00"):
                self.assertIn(texto.encode("cp850"), data, (paper, texto))
            largura = 32 if paper == 58 else 48
            texto = data.replace(b"\x1bE\x01", b"").replace(b"\x1bE\x00", b"")
            for linha in texto.split(b"\n"):
                if b"\x1b" not in linha and b"\x1d" not in linha:
                    self.assertLessEqual(len(linha), largura, linha)

    def test_logo_opcional(self):
        self.assertIn(b"\x1dv0", job_receipt(JOB, 80, logo=True))
        self.assertNotIn(b"\x1dv0", job_receipt(JOB, 80, logo=False))

    def test_quebra_e_par(self):
        self.assertEqual(_wrap("um dois tres", 7), ["um dois", "tres"])
        self.assertEqual(_wrap("abcdefghij", 4), ["abcd", "efgh", "ij"])
        linhas = _pair("Total da comanda até agora", "R$ 1.234,56", 32)
        self.assertTrue(linhas[-1].endswith("R$ 1.234,56"))
        self.assertTrue(all(len(l) <= 32 for l in linhas))


class PrintResultApiTests(unittest.TestCase):
    def test_eventos_pedem_impressoes(self):
        client = ApiClient()
        client.token = "dzag_" + "a" * 64
        with patch("agent.api.requests.get") as get:
            get.return_value = MagicMock(status_code=200, json=lambda: {"cursor": 1, "pedidos": []})
            client.events(5)
        self.assertEqual(get.call_args.kwargs["params"], {"desde": 5, "impressoes": 1, "impressoras": 1})

    def test_resultado_ok_e_erro(self):
        client = ApiClient()
        client.token = "dzag_" + "b" * 64
        with patch("agent.api.requests.post") as post:
            post.return_value = MagicMock(status_code=200)
            self.assertTrue(client.print_result(7, True))
            self.assertTrue(post.call_args.args[0].endswith("/api/v1/desktop/impressoes/7/resultado"))
            self.assertEqual(post.call_args.kwargs["json"], {"ok": True})
            self.assertEqual(post.call_args.kwargs["headers"]["Authorization"], "Bearer " + client.token)
            client.print_result(8, False, "Impressora offline")
            self.assertEqual(post.call_args.kwargs["json"], {"ok": False, "erro": "Impressora offline"})

    def test_registra_impressoras(self):
        client = ApiClient()
        client.token = "dzag_" + "d" * 64
        with patch("agent.api.requests.post") as post:
            post.return_value = MagicMock(status_code=200)
            client.register_printers([{"chave": "abcd1234ef", "apelido": "Cozinha", "windows": "EPSON"}])
        self.assertTrue(post.call_args.args[0].endswith("/api/v1/desktop/impressoras"))
        self.assertEqual(post.call_args.kwargs["json"], {"impressoras": [{"chave": "abcd1234ef", "apelido": "Cozinha"}]})
        with patch("agent.api.requests.post") as post:
            post.return_value = MagicMock(status_code=422, json=lambda: {"erro": "Apelido grande"})
            with self.assertRaises(RuntimeError):
                client.register_printers([{"chave": "abcd1234ef", "apelido": "x"}])

    def test_resultado_exige_credencial(self):
        with self.assertRaises(AuthenticationError):
            ApiClient().print_result(1, True)
        client = ApiClient()
        client.token = "dzag_" + "c" * 64
        with patch("agent.api.requests.post") as post:
            post.return_value = MagicMock(status_code=401)
            with self.assertRaises(AuthenticationError):
                client.print_result(1, True)


if __name__ == "__main__":
    unittest.main()
