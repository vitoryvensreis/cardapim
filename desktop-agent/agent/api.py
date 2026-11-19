"""Autenticacao no PHP existente + token isolado do agente, sem salvar senha."""
import re
import socket
from urllib.parse import urljoin
import requests

from agent import __version__

BASE_URL = "https://deliveryzim.com.br"

USER_AGENT = "Deliveryzim-Agent/" + __version__ + " (Windows)"
CSRF_RE = re.compile(r'name=["\']_csrf["\']\s+value=["\']([a-f0-9]{64})["\']', re.I)


class AuthenticationError(Exception):
    pass


class NeedTwoFactor(Exception):
    pass


class ApiClient:
    def __init__(self, base=BASE_URL):
        if not base.startswith("https://"):
            raise ValueError("API deve usar HTTPS.")
        self.base = base.rstrip("/")
        self.session = requests.Session()
        self.session.headers.update({"User-Agent": USER_AGENT, "Accept": "application/json"})
        self.token = ""

    def url(self, path):
        return urljoin(self.base + "/", path.lstrip("/"))

    def csrf_from_form(self, path):
        response = self.session.get(self.url(path), timeout=15, headers={"Accept": "text/html"})
        response.raise_for_status()
        match = CSRF_RE.search(response.text)
        if not match:
            raise AuthenticationError("Nao foi possivel identificar a sessao do login.")
        return match.group(1)

    def login(self, username, password):
        csrf = self.csrf_from_form("/login")
        response = self.session.post(
            self.url("/login"),
            data={"_csrf": csrf, "identificacao": username, "senha": password},
            allow_redirects=False, timeout=20,
        )
        if response.status_code in (302, 303) and "/login/verificacao" in response.headers.get("Location", ""):
            raise NeedTwoFactor()
        if response.status_code not in (302, 303):
            raise AuthenticationError("Login recusado. Confira usuario, senha e acesso ao restaurante.")
        return self.link()

    def verify(self, code):
        csrf = self.csrf_from_form("/login/verificacao")
        response = self.session.post(
            self.url("/login/verificacao"), data={"_csrf": csrf, "codigo": code},
            allow_redirects=False, timeout=20,
        )
        if response.status_code not in (302, 303) or "/login" in response.headers.get("Location", ""):
            raise AuthenticationError("Codigo de verificacao incorreto ou expirado.")
        return self.link()

    def link(self):
        response = self.session.get(self.url("/api/v1/desktop/csrf"), timeout=15)
        if response.status_code in (401, 403, 404):
            raise AuthenticationError("Seu login nao pode vincular o agente ou a API ainda nao foi publicada.")
        response.raise_for_status()
        csrf = response.json().get("csrf", "")
        if len(csrf) != 64:
            raise AuthenticationError("Nao foi possivel autorizar este computador.")
        response = self.session.post(
            self.url("/api/v1/desktop/vincular"),
            json={"nome": socket.gethostname()[:90]},
            headers={"X-CSRF-Token": csrf}, timeout=15,
        )
        if response.status_code != 201:
            try:
                message = response.json().get("erro", "Nao foi possivel vincular.")
            except ValueError:
                message = "Falha ao vincular. Verifique a API."
            raise AuthenticationError(message)
        data = response.json()
        if not str(data.get("token", "")).startswith("dzag_"):
            raise AuthenticationError("Servidor nao retornou uma credencial valida.")
        return data

    def events(self, cursor):
        if not self.token:
            raise AuthenticationError("Agente sem credencial.")
        response = requests.get(
            self.url("/api/v1/desktop/eventos"),
            params={"desde": int(cursor)},
            headers={"Authorization": "Bearer " + self.token, "User-Agent": USER_AGENT},
            timeout=(7, 20),
        )
        if response.status_code in (401, 403):
            raise AuthenticationError("Credencial revogada, expirada ou sem permissao.")
        response.raise_for_status()
        return response.json()

    def publish_weight(self, kg=None, stable=False):
        """Atualiza leitura efêmera em HTTPS. O backend determina o restaurante."""
        if not self.token:
            raise AuthenticationError("Agente sem credencial.")
        if stable and kg is None:
            raise ValueError("Peso estavel ausente.")
        response = requests.post(
            self.url("/api/v1/desktop/pesagem"),
            json={"peso_kg": str(kg) if stable else None, "estavel": bool(stable)},
            headers={"Authorization": "Bearer " + self.token, "User-Agent": USER_AGENT},
            timeout=(4, 8),
        )
        if response.status_code in (401, 403):
            raise AuthenticationError("Dispositivo revogado ou sem acesso à balança.")
        response.raise_for_status()

    def browser_pairing(self, destino=""):
        """Gera URL temporaria de pareamento para o navegador deste Windows (destino=gerencia abre a gerencia)."""
        if not self.token:
            raise AuthenticationError("Agente sem credencial.")
        response = requests.post(
            self.url("/api/v1/desktop/pareamento"),
            headers={"Authorization": "Bearer " + self.token, "User-Agent": USER_AGENT},
            timeout=(4, 10),
        )
        if response.status_code in (401, 403):
            raise AuthenticationError("Dispositivo nao autorizado.")
        response.raise_for_status()
        path = response.json().get("url", "")
        if not isinstance(path, str) or not path.startswith("/api/v1/desktop/parear?ticket="):
            raise ValueError("Link de caixa invalido.")
        if destino == "gerencia":
            path += "&destino=gerencia"
        return self.url(path)

    def order_status(self, ids):
        """Situação atual dos pedidos avisados (até 50), só do restaurante deste computador."""
        if not self.token:
            raise AuthenticationError("Agente sem credencial.")
        ids = [int(i) for i in ids if int(i) > 0][:50]
        if not ids:
            return []
        response = requests.get(
            self.url("/api/v1/desktop/pedidos/situacao"),
            params={"ids": ",".join(str(i) for i in ids)},
            headers={"Authorization": "Bearer " + self.token, "User-Agent": USER_AGENT},
            timeout=(4, 10),
        )
        if response.status_code in (401, 403):
            raise AuthenticationError("Credencial revogada, expirada ou sem permissao.")
        response.raise_for_status()
        rows = response.json().get("pedidos")
        return rows if isinstance(rows, list) else []

    def receive_order(self, pedido_id):
        """"Receber" do aviso = Aceitar do caixa. Devolve (aceito_agora, situacao, mensagem).

        409 significa que alguém já mudou o pedido (aceitou no caixa ou cancelou): o aviso fecha igual.
        """
        if not self.token:
            raise AuthenticationError("Agente sem credencial.")
        response = requests.post(
            self.url("/api/v1/desktop/pedidos/" + str(int(pedido_id)) + "/receber"),
            headers={"Authorization": "Bearer " + self.token, "User-Agent": USER_AGENT},
            timeout=(5, 15),
        )
        if response.status_code in (401, 403):
            raise AuthenticationError("Credencial revogada, expirada ou sem permissao.")
        try:
            data = response.json()
        except ValueError:
            data = {}
        if response.status_code == 200:
            return True, str(data.get("status") or "em_preparo"), str(data.get("mensagem") or "")
        if response.status_code == 409:
            return False, str(data.get("status") or ""), str(data.get("erro") or "Este pedido já mudou de situação.")
        raise RuntimeError(str(data.get("erro") or "Não foi possível aceitar agora. Tente pelo caixa."))

    def order_url(self, order):
        """Endereço do pedido no sistema; só aceita caminho relativo do próprio servidor."""
        path = str(order.get("url") or "")
        if not path.startswith("/") or path.startswith("//") or "://" in path:
            return self.url("/login")
        return self.url(path)

    def revoke(self):
        if self.token:
            requests.post(self.url("/api/v1/desktop/desconectar"),
                          headers={"Authorization": "Bearer " + self.token}, timeout=10)
