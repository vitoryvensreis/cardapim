"""Lista de impressoras do Agent (sem limite): apelido ("Balcão", "Cozinha"), impressora do Windows e papel.

A primeira da lista é a principal: imprime o que não tem impressora escolhida (como nas versões antigas).
O sistema só recebe a chave e o apelido; o nome da impressora no Windows fica neste computador.
"""
import secrets

MAX_PRINTERS = 50
MAX_ALIAS = 40


def new_printer(alias="", windows="", paper=80):
    return {"chave": secrets.token_hex(8), "apelido": str(alias)[:MAX_ALIAS], "windows": str(windows),
            "papel": 58 if int(paper or 80) == 58 else 80}


def from_preferences(data):
    """Lê a lista salva. Quem vem da 0.7 (uma impressora só) ganha a "Principal" com a impressora de antes."""
    saved = data.get("impressoras")
    if isinstance(saved, list) and saved:
        return normalize(saved)
    return [new_printer("Principal", data.get("impressora") or "", data.get("papel") or 80)]


def normalize(items):
    seen = set()
    result = []
    for item in items if isinstance(items, list) else []:
        if not isinstance(item, dict):
            continue
        key = str(item.get("chave") or "")
        if not (8 <= len(key) <= 40 and key.isalnum() and key.lower() == key) or key in seen:
            key = secrets.token_hex(8)
        seen.add(key)
        result.append({
            "chave": key,
            "apelido": " ".join(str(item.get("apelido") or "").split())[:MAX_ALIAS],
            "windows": str(item.get("windows") or ""),
            "papel": 58 if str(item.get("papel")) == "58" else 80,
        })
        if len(result) >= MAX_PRINTERS:
            break
    return result


def alias_of(printer, index):
    return printer.get("apelido") or ("Principal" if index == 0 else "Impressora " + str(index + 1))


def server_payload(items):
    """O que vai para o sistema: só chave e apelido (o gerente escolhe os pontos de impressão por ele)."""
    return [{"chave": p["chave"], "apelido": alias_of(p, i)} for i, p in enumerate(items)]


def principal(items):
    for item in items:
        if item.get("windows"):
            return item
    return None


def resolve(items, keys):
    """Impressoras para imprimir. keys=None: a principal. Lista: só as desta lista (vazia = nenhuma aqui)."""
    if keys is None:
        main = principal(items)
        return [main] if main else []
    wanted = {str(k) for k in keys if k}
    return [p for p in items if p["chave"] in wanted and p.get("windows")]


def by_key(items, key):
    """Cupom do sistema: chave da impressora escolhida; "" = a principal."""
    if not key:
        main = principal(items)
        return main
    for item in items:
        if item["chave"] == key:
            return item
    return None


def problems(items):
    """Avisos para a tela (apelido vazio ou repetido, impressora do Windows não escolhida)."""
    found = []
    aliases = {}
    for i, item in enumerate(items):
        name = alias_of(item, i)
        if not item.get("apelido"):
            found.append("Dê um apelido para a impressora " + str(i + 1) + ".")
        if not item.get("windows"):
            found.append("Escolha a impressora do Windows de \"" + name + "\".")
        aliases.setdefault(name.casefold(), []).append(i)
    for indexes in aliases.values():
        if len(indexes) > 1:
            found.append("Apelido repetido: \"" + alias_of(items[indexes[0]], indexes[0]) + "\". Use nomes diferentes.")
    return found
