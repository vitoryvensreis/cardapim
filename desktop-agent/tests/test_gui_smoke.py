"""Abre as telas de verdade no Windows do GitHub Actions: pega erro de componente/argumento antes do EXE.

Roda no runner Windows (que tem área de trabalho). Fora do Windows, sem Tk real, o teste é pulado.
"""
import os
import sys
import tempfile
import unittest
from unittest.mock import patch

_PERFIL = tempfile.mkdtemp(prefix="deliveryzim-agent-teste-")
os.environ["LOCALAPPDATA"] = _PERFIL  # preferências e fila em pasta temporária, nunca no perfil real


def _tk_real():
    if sys.platform != "win32":
        return False
    try:
        import tkinter
        return isinstance(tkinter.Tk, type)
    except Exception:
        return False


@unittest.skipUnless(_tk_real(), "Somente no Windows com Tk real (runner do GitHub Actions)")
class GuiSmokeTests(unittest.TestCase):
    def setUp(self):
        from agent.app import DeliveryzimApp
        with patch("agent.app.load_credential", return_value=""):
            self.app = DeliveryzimApp()
        self.app.root.update()

    def tearDown(self):
        self.app.quit_flag.set()
        self.app.scale_monitor.stop()
        try:
            self.app.root.destroy()
        except Exception:
            pass

    def pump(self, times=5):
        for _ in range(times):
            self.app.root.update()

    def test_login_settings_modes_and_scale(self):
        app = self.app
        self.assertTrue(app.login_page.winfo_ismapped())
        # Tela de configuração (como se estivesse vinculado), sem iniciar polling.
        app.token = "dzag_" + "0" * 64
        app._show_link_state()
        self.pump()
        app._select_mode("auto")
        app._select_mode("manual")
        app.logo_var.set(False)
        app._save_preferences()
        self.pump()
        panel = app.scale_panel
        panel.source.set("Wi-Fi (rede local)")
        panel._toggle_source()
        self.pump()
        self.assertEqual(panel._endereco(), "tcp://" + panel.tcp_host.get().strip() + ":" + panel.tcp_port.get().strip())
        panel.source.set("Cabo (porta COM)")
        panel._toggle_source()
        self.pump()

    def test_order_notice_receive_and_silence(self):
        app = self.app
        with patch("agent.app.play_order_sound") as play, patch.object(app, "_print") as printer:
            app.mode_var.set("manual")
            app._test()
            self.pump()
            self.assertTrue(play.called)
            printer.assert_not_called()
            test_ids = app.alerts.ids()
            self.assertEqual(len(test_ids), 1)
            pedido = test_ids[0]
            self.assertIn(pedido, app.toasts.cards)
            # Pedido já aceito em outro lugar: cartão só com Ver no sistema.
            app._new_order({"evento_id": 99, "id": 5, "numero": 5, "status": "em_preparo",
                            "origem": "ifood", "itens": [], "total": "10.00"})
            self.pump()
            self.assertIn(5, app.toasts.cards)
            app.toasts.set_message(pedido, "Falha na impressão: teste", error=True)
            app._silence({"id": pedido})
            self.assertFalse(app.alerts.should_ring(True))
            order = app.toasts.cards[pedido]
            self.assertTrue(order["window"].winfo_exists())
            app._receive({"id": pedido, "evento_id": pedido, "numero": "TESTE", "teste": True,
                          "itens": [], "total": "0"})
            self.pump()
            self.assertNotIn(pedido, app.toasts.cards)
            printer.assert_called_once()  # "Ao receber": imprime no clique
            app.toasts.close_all()
            self.pump()


if __name__ == "__main__":
    unittest.main()
