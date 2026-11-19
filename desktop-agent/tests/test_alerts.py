"""Aviso de pedido: toca até alguém aceitar (no Agent, no caixa ou em outro computador)."""
import unittest
from unittest.mock import patch

from agent.alerts import AlertBoard
from agent.api import ApiClient, AuthenticationError


class AlertBoardTests(unittest.TestCase):
    def test_only_new_orders_wait_for_receive(self):
        board = AlertBoard()
        self.assertTrue(board.add({"id": 10, "status": "recebido"}))
        self.assertFalse(board.add({"id": 11, "status": "em_preparo"}))  # iFood aceito sozinho
        self.assertFalse(board.add({"id": 0, "status": "recebido"}))
        self.assertTrue(board.add({"id": -5, "status": "recebido", "teste": True}))
        self.assertEqual(board.ids(), [-5, 10])

    def test_rings_until_resolved_and_respects_silence_and_sound_off(self):
        board = AlertBoard()
        board.add({"id": 10, "status": "recebido"})
        board.add({"id": 12, "status": "recebido"})
        self.assertTrue(board.should_ring(True))
        self.assertFalse(board.should_ring(False))
        board.silence(10)
        self.assertTrue(board.should_ring(True))  # o 12 ainda toca
        board.silence(12)
        self.assertFalse(board.should_ring(True))
        self.assertTrue(board.waiting(12))  # silenciado continua esperando "Receber"
        board.resolve(10)
        board.resolve(12)
        self.assertEqual(board.ids(), [])

    def test_accepted_or_cancelled_elsewhere_stops(self):
        board = AlertBoard()
        for i in (1, 2, 3):
            board.add({"id": i, "status": "recebido"})
        resolved = board.apply_status([{"id": 1, "status": "em_preparo"}, {"id": 2, "status": "recebido"},
                                       {"id": 3, "status": "cancelado"}, "lixo", {"id": "x"}])
        self.assertEqual(resolved, [1, 3])
        self.assertEqual(board.ids(), [2])


class ReceiveApiTests(unittest.TestCase):
    def setUp(self):
        self.client = ApiClient()
        self.client.token = "dzag_" + "a" * 64

    def test_receive_posts_with_device_token(self):
        with patch("agent.api.requests.post") as post:
            post.return_value.status_code = 200
            post.return_value.json.return_value = {"ok": True, "status": "em_preparo", "mensagem": "ok"}
            self.assertEqual(self.client.receive_order(42), (True, "em_preparo", "ok"))
            self.assertEqual(post.call_args.args[0],
                             "https://deliveryzim.com.br/api/v1/desktop/pedidos/42/receber")
            self.assertEqual(post.call_args.kwargs["headers"]["Authorization"], "Bearer " + self.client.token)

    def test_already_changed_closes_without_error(self):
        with patch("agent.api.requests.post") as post:
            post.return_value.status_code = 409
            post.return_value.json.return_value = {"erro": "Já mudou.", "status": "cancelado"}
            self.assertEqual(self.client.receive_order(42), (False, "cancelado", "Já mudou."))

    def test_revoked_and_server_errors(self):
        with patch("agent.api.requests.post") as post:
            post.return_value.status_code = 401
            with self.assertRaises(AuthenticationError):
                self.client.receive_order(42)
            post.return_value.status_code = 503
            post.return_value.json.return_value = {"erro": "Tente pelo caixa."}
            with self.assertRaises(RuntimeError):
                self.client.receive_order(42)

    def test_status_query_limits_and_parses(self):
        with patch("agent.api.requests.get") as get:
            get.return_value.status_code = 200
            get.return_value.json.return_value = {"pedidos": [{"id": 1, "status": "recebido"}]}
            self.assertEqual(self.client.order_status(range(1, 80)), [{"id": 1, "status": "recebido"}])
            self.assertEqual(len(get.call_args.kwargs["params"]["ids"].split(",")), 50)
            self.assertEqual(self.client.order_status([]), [])

    def test_order_url_only_accepts_own_server_paths(self):
        self.assertEqual(self.client.order_url({"url": "/loja/caixa/delivery/9"}),
                         "https://deliveryzim.com.br/loja/caixa/delivery/9")
        for bad in ("https://evil.example/x", "//evil.example/x", "javascript:alert(1)", ""):
            self.assertEqual(self.client.order_url({"url": bad}), "https://deliveryzim.com.br/login")


if __name__ == "__main__":
    unittest.main()
