"""Lista de impressoras do Agent: migração da 0.7, apelidos, principal e escolha da impressora do cupom."""
import unittest

from agent.printer_list import (by_key, from_preferences, new_printer, normalize, principal, problems,
                                resolve, server_payload)


class PrinterListTests(unittest.TestCase):
    def test_migra_impressora_da_versao_antiga(self):
        items = from_preferences({"impressora": "EPSON TM-T20", "papel": 58, "impressoras": []})
        self.assertEqual(len(items), 1)
        self.assertEqual(items[0]["apelido"], "Principal")
        self.assertEqual(items[0]["windows"], "EPSON TM-T20")
        self.assertEqual(items[0]["papel"], 58)
        self.assertRegex(items[0]["chave"], r"^[a-f0-9]{16}$")

    def test_lista_salva_e_normalizada(self):
        saved = [{"chave": "abcd1234ef", "apelido": "  Balcão  ", "windows": "A", "papel": "58"},
                 {"chave": "abcd1234ef", "apelido": "Cozinha", "windows": "B", "papel": 80},
                 {"chave": "INVALIDA!", "apelido": "Bar", "windows": "", "papel": 99}, "lixo"]
        items = normalize(saved)
        self.assertEqual([i["apelido"] for i in items], ["Balcão", "Cozinha", "Bar"])
        self.assertEqual(len({i["chave"] for i in items}), 3)  # chave repetida/invalida ganha nova
        self.assertEqual(items[2]["papel"], 80)
        self.assertEqual(from_preferences({"impressoras": saved})[0]["chave"], "abcd1234ef")

    def test_principal_e_resolucao(self):
        balcao = new_printer("Balcão", "", 80)
        cozinha = new_printer("Cozinha", "EPSON COZ", 80)
        caixa = new_printer("Caixa", "EPSON CX", 58)
        items = [balcao, cozinha, caixa]
        self.assertIs(principal(items), cozinha)  # primeira com impressora do Windows
        self.assertEqual(resolve(items, None), [cozinha])
        self.assertEqual(resolve(items, [caixa["chave"], cozinha["chave"]]), [cozinha, caixa])
        self.assertEqual(resolve(items, []), [])
        self.assertEqual(resolve(items, [balcao["chave"]]), [])  # sem impressora do Windows
        self.assertIs(by_key(items, caixa["chave"]), caixa)
        self.assertIs(by_key(items, ""), cozinha)
        self.assertIsNone(by_key(items, "naoexiste123"))

    def test_envio_ao_sistema_sem_nome_do_windows(self):
        items = [new_printer("Balcão", "EPSON", 80), new_printer("", "OUTRA", 80)]
        payload = server_payload(items)
        self.assertEqual([p["apelido"] for p in payload], ["Balcão", "Impressora 2"])
        self.assertTrue(all(set(p) == {"chave", "apelido"} for p in payload))

    def test_avisos(self):
        items = [new_printer("Balcão", "A"), new_printer("balcão", ""), new_printer("", "C")]
        texto = " ".join(problems(items))
        self.assertIn("Apelido repetido", texto)
        self.assertIn("Escolha a impressora do Windows", texto)
        self.assertIn("Dê um apelido", texto)
        self.assertEqual(problems([new_printer("Cozinha", "A")]), [])


if __name__ == "__main__":
    unittest.main()
