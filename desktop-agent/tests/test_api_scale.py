"""Contrato HTTPS de pesagem para o backend, sem acesso à rede real."""
import unittest
from unittest.mock import patch
from agent.api import ApiClient, AuthenticationError


class PublishWeightTests(unittest.TestCase):
    def test_payload_and_scope(self):
        c = ApiClient()
        c.token = "dzag_" + "f" * 64
        with patch("agent.api.requests.post") as post:
            post.return_value.status_code = 200
            c.publish_weight("0.350", True)
            _, kwargs = post.call_args
            self.assertEqual(kwargs["json"], {"peso_kg": "0.350", "estavel": True})
            self.assertEqual(kwargs["headers"]["Authorization"], "Bearer " + c.token)
            self.assertEqual(post.call_args.args[0], "https://deliveryzim.com.br/api/v1/desktop/pesagem")
            self.assertNotIn("id_restaurante", kwargs["json"])
            c.publish_weight(None, False)
            self.assertEqual(post.call_args.kwargs["json"], {"peso_kg": None, "estavel": False})

    def test_auth_required(self):
        with self.assertRaises(AuthenticationError):
            ApiClient().publish_weight("0.350", True)

    def test_cannot_send_stable_null(self):
        c = ApiClient()
        c.token = "dzag_" + "f" * 64
        with self.assertRaises(ValueError):
            c.publish_weight(None, True)

    def test_browser_pairing_uses_device_token_only(self):
        c = ApiClient()
        c.token = "dzag_" + "f" * 64
        with patch("agent.api.requests.post") as post:
            post.return_value.status_code = 200
            post.return_value.json.return_value = {
                "url": "/api/v1/desktop/parear?ticket=teste-assinado",
            }
            url = c.browser_pairing()
            self.assertEqual(url, "https://deliveryzim.com.br/api/v1/desktop/parear?ticket=teste-assinado")
            self.assertEqual(post.call_args.args[0], "https://deliveryzim.com.br/api/v1/desktop/pareamento")
            self.assertEqual(post.call_args.kwargs["headers"]["Authorization"], "Bearer " + c.token)
            self.assertNotIn("json", post.call_args.kwargs)

    def test_browser_pairing_rejects_untrusted_path(self):
        c = ApiClient()
        c.token = "dzag_" + "f" * 64
        with patch("agent.api.requests.post") as post:
            post.return_value.status_code = 200
            post.return_value.json.return_value = {"url": "https://evil.example/path"}
            with self.assertRaises(ValueError):
                c.browser_pairing()

    def test_revoked(self):
        c = ApiClient()
        c.token = "dzag_" + "f" * 64
        with patch("agent.api.requests.post") as post:
            post.return_value.status_code = 401
            with self.assertRaises(AuthenticationError):
                c.publish_weight("0.350", True)


if __name__ == "__main__":
    unittest.main()
