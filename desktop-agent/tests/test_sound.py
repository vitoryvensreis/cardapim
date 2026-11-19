"""Regressões do alerta ao receber pedidos (manual, automático e teste local)."""
import os
import tempfile
import unittest
import wave
from pathlib import Path
from unittest.mock import MagicMock, patch

from agent import sound
from agent.app import DeliveryzimApp


class SoundTests(unittest.TestCase):
    def test_wav_original_is_valid_and_audible_length(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "alerta.wav"
            sound.generate(path)
            with wave.open(str(path), "rb") as f:
                self.assertEqual(f.getnchannels(), 1)
                self.assertEqual(f.getframerate(), sound.SAMPLE_RATE)
                self.assertGreater(f.getnframes(), sound.SAMPLE_RATE // 2)

    def test_uses_own_wav_even_without_windows_theme_sound(self):
        with tempfile.TemporaryDirectory() as directory:
            p = Path(directory) / "alerta.wav"
            with patch.object(sound, "sound_path", return_value=p), patch.object(sound.winsound, "PlaySound") as play:
                self.assertTrue(sound.play())
                self.assertTrue(p.exists())
                args = play.call_args.args
                self.assertEqual(args[0], str(p))
                self.assertTrue(args[1] & sound.winsound.SND_ASYNC)

    def test_fallback_for_audio_failure(self):
        with patch.object(sound, "sound_path", side_effect=OSError), patch.object(sound.winsound, "MessageBeep") as fallback:
            self.assertTrue(sound.play())
            fallback.assert_called_once()

    def test_every_new_order_rings_in_both_modes(self):
        app = DeliveryzimApp.__new__(DeliveryzimApp)
        app._ring = MagicMock()
        app._print = MagicMock()
        app._prompt = MagicMock()
        for mode in ("auto", "manual"):
            app.mode_var = MagicMock()
            app.mode_var.get.return_value = mode
            app._new_order({"evento_id": 4, "numero": 7})
        self.assertEqual(app._ring.call_count, 2)
        app._print.assert_called_once()
        app._prompt.assert_called_once()

    def test_sound_can_be_disabled_without_muting_test_button(self):
        app = DeliveryzimApp.__new__(DeliveryzimApp)
        app.sound_var = MagicMock()
        with patch("agent.app.play_order_sound") as play:
            app.sound_var.get.return_value = False
            app._ring()
            play.assert_not_called()
            app._test_sound()
            play.assert_called_once()


if __name__ == "__main__":
    unittest.main()
