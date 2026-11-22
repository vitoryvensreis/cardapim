"""Atualização automática do Deliveryzim Agent (0.9.0+).

Procura a versão mais nova nos Releases do GitHub (tag agent-vX.Y.Z), baixa o instalador, confere o SHA-256
publicado pelo GitHub e instala em modo silencioso quando o Agent está parado (sem pedido tocando nem
impressão saindo). O instalador mantém impressoras, vínculo e preferências (pasta de dados separada) e
abre o Agent de novo no fim. Sem SHA-256 conferido, nada é instalado.
"""
import hashlib
import os
import re
import subprocess
import tempfile

import requests

from agent import __version__

RELEASES = "https://api.github.com/repos/vitoryvensreis/cardapim/releases?per_page=20"
ASSET = "Deliveryzim-Agent-Setup.exe"
TAG = re.compile(r"^agent-v(\d+)\.(\d+)\.(\d+)$")
MAX_BYTES = 200 * 1024 * 1024


def parse(version):
    match = re.match(r"^(\d+)\.(\d+)\.(\d+)$", str(version or "").strip())
    return tuple(int(x) for x in match.groups()) if match else None


def newest(releases, current=__version__):
    """Release mais nova que a atual, com o instalador e o SHA-256: {"versao", "url", "sha256", "tamanho"} ou None."""
    atual = parse(current) or (0, 0, 0)
    best = None
    for release in releases if isinstance(releases, list) else []:
        if not isinstance(release, dict) or release.get("draft") or release.get("prerelease"):
            continue
        match = TAG.match(str(release.get("tag_name") or ""))
        if not match:
            continue
        version = tuple(int(x) for x in match.groups())
        if version <= atual or (best and version <= best[0]):
            continue
        for asset in release.get("assets") or []:
            digest = str(asset.get("digest") or "")
            url = str(asset.get("browser_download_url") or "")
            if asset.get("name") == ASSET and re.match(r"^sha256:[a-f0-9]{64}$", digest) \
                    and url.startswith("https://github.com/"):
                best = (version, {"versao": ".".join(map(str, version)), "url": url,
                                  "sha256": digest.split(":", 1)[1], "tamanho": int(asset.get("size") or 0)})
    return best[1] if best else None


def check(user_agent):
    response = requests.get(RELEASES, headers={"Accept": "application/vnd.github+json", "User-Agent": user_agent},
                            timeout=(5, 15))
    response.raise_for_status()
    return newest(response.json())


def download(update, user_agent):
    """Baixa para a pasta temporária e confere o SHA-256. Devolve o caminho do instalador conferido."""
    folder = os.path.join(tempfile.gettempdir(), "DeliveryzimAgentUpdate")
    os.makedirs(folder, exist_ok=True)
    path = os.path.join(folder, "Deliveryzim-Agent-Setup-" + update["versao"] + ".exe")
    digest = hashlib.sha256()
    total = 0
    with requests.get(update["url"], headers={"User-Agent": user_agent}, stream=True, timeout=(10, 60)) as response:
        response.raise_for_status()
        with open(path + ".part", "wb") as out:
            for chunk in response.iter_content(256 * 1024):
                total += len(chunk)
                if total > MAX_BYTES:
                    raise RuntimeError("Instalador maior que o esperado.")
                digest.update(chunk)
                out.write(chunk)
    if digest.hexdigest() != update["sha256"]:
        os.remove(path + ".part")
        raise RuntimeError("Instalador não confere com o publicado (SHA-256).")
    os.replace(path + ".part", path)
    return path


def install(path):
    """Instala em silêncio depois que o Agent fechar (o instalador recusa rodar com o Agent aberto)."""
    if '"' in path:
        raise RuntimeError("Caminho do instalador inválido.")
    flags = getattr(subprocess, "CREATE_NO_WINDOW", 0)
    # ping espera ~4 s sem precisar de teclado (o "timeout" do Windows falha sem console).
    command = 'cmd.exe /d /s /c "ping -n 5 127.0.0.1 >nul & "' + path + '" /VERYSILENT /SUPPRESSMSGBOXES /NORESTART"'
    subprocess.Popen(command, creationflags=flags, close_fds=True,
                     stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
