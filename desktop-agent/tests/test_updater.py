import hashlib
import unittest
from unittest.mock import MagicMock, patch

from agent import updater

DIGEST = "sha256:" + "a" * 64


def release(tag, digest=DIGEST, name=updater.ASSET, draft=False, url=None):
    return {"tag_name": tag, "draft": draft, "prerelease": False, "assets": [
        {"name": name, "digest": digest, "size": 10,
         "browser_download_url": url or "https://github.com/vitoryvensreis/cardapim/releases/download/" + tag + "/" + name}]}


class UpdaterTests(unittest.TestCase):
    def test_escolhe_a_mais_nova_do_agent(self):
        found = updater.newest([release("agent-v0.9.1"), release("agent-v0.10.0"), release("android-v9.0.0"),
                                release("agent-v0.8.1")], "0.9.0")
        self.assertEqual(found["versao"], "0.10.0")
        self.assertEqual(found["sha256"], "a" * 64)

    def test_ignora_sem_sha256_rascunho_e_versao_igual(self):
        self.assertIsNone(updater.newest([release("agent-v0.9.0")], "0.9.0"))
        self.assertIsNone(updater.newest([release("agent-v1.0.0", digest="")], "0.9.0"))
        self.assertIsNone(updater.newest([release("agent-v1.0.0", draft=True)], "0.9.0"))
        self.assertIsNone(updater.newest([release("agent-v1.0.0", name="outro.exe")], "0.9.0"))
        self.assertIsNone(updater.newest([release("agent-v1.0.0", url="https://exemplo.com/x.exe")], "0.9.0"))
        self.assertIsNone(updater.newest("lixo", "0.9.0"))

    def test_download_confere_sha256(self):
        conteudo = b"instalador"
        update = {"versao": "9.9.9", "url": "https://github.com/x", "sha256": hashlib.sha256(conteudo).hexdigest(), "tamanho": 10}
        resposta = MagicMock()
        resposta.__enter__.return_value = resposta
        resposta.iter_content.return_value = [conteudo]
        with patch("agent.updater.requests.get", return_value=resposta):
            caminho = updater.download(update, "teste")
        with open(caminho, "rb") as f:
            self.assertEqual(f.read(), conteudo)
        update["sha256"] = "b" * 64
        with patch("agent.updater.requests.get", return_value=resposta):
            with self.assertRaises(RuntimeError):
                updater.download(update, "teste")


if __name__ == "__main__":
    unittest.main()
