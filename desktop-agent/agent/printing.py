"""Composicao de cupom termico em ESC/POS e envio RAW pelo spooler do Windows."""
from decimal import Decimal, InvalidOperation
import os
import sys
import win32print

# Pontos por linha das térmicas comuns (203 dpi): 58 mm = 384, 80 mm = 576.
DOTS = {58: 384, 80: 576}
LOGO_FILE = "logo-branca.png"
# Espaco em branco antes do corte (linhas). A lamina fica alguns milimetros acima da cabeca de impressao: sem esse
# avanco, a ultima informacao do cupom era cortada em algumas termicas. Escolhido no Agent ("Espaco antes do corte").
MARGENS_CORTE = {"Normal": 4, "Mais espaço": 7, "Bem mais espaço": 10}
MARGEM_PADRAO = 4


def fim_do_cupom(margem=MARGEM_PADRAO):
    """Avanca o papel `margem` linhas (ESC d n) e corta (GS V 0). Valor fora do intervalo vira o padrao."""
    try:
        n = int(margem)
    except (TypeError, ValueError):
        n = MARGEM_PADRAO
    n = n if 0 <= n <= 20 else MARGEM_PADRAO
    return b"\x1bd" + bytes((n,)) + b"\x1dV\x00"


def printers():
    """Lista impressoras locais e conectadas usando o formato real do pywin32.

    EnumPrinters nivel 4 retorna dicts e e rapido (nao acessa cada driver).
    O nivel 1 usa tuplas legadas; o 2 tambem retorna dicts.
    """
    flags = win32print.PRINTER_ENUM_LOCAL | win32print.PRINTER_ENUM_CONNECTIONS
    found = set()
    last_error = None
    succeeded = False
    for level in (4, 2, 1):
        try:
            rows = win32print.EnumPrinters(flags, None, level)
            succeeded = True
            for row in rows:
                if isinstance(row, dict):
                    name = row.get("pPrinterName")
                elif isinstance(row, (tuple, list)) and len(row) >= 3:
                    name = row[2]
                else:
                    name = None
                if isinstance(name, str) and name.strip():
                    found.add(name.strip())
            if found:
                break
        except Exception as error:
            last_error = error
    if not found and not succeeded and last_error is not None:
        raise RuntimeError("Windows nao conseguiu listar as impressoras: " + str(last_error))
    return sorted(found, key=str.casefold)


def money(value):
    try:
        number = Decimal(str(value or "0"))
    except (InvalidOperation, ValueError):
        number = Decimal("0")
    return "R$ " + f"{number:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")


def cut_text(value, width):
    text = " ".join(str(value or "").splitlines()).strip()
    if not text:
        return []
    return [text[i:i + width] for i in range(0, len(text), width)]


def _asset(name):
    base = getattr(sys, "_MEIPASS", None)
    if getattr(sys, "frozen", False) and base:
        return os.path.join(base, "agent", "assets", name)
    return os.path.join(os.path.dirname(__file__), "assets", name)


def logo_raster(paper=80, path=None):
    """Logo oficial do Deliveryzim (arquivo da marca, sem redesenhar) como imagem ESC/POS centralizada.

    Usa a logo branca com fundo transparente: o que é logo vira ponto preto no papel. Comando GS v 0
    (imagem raster), aceito pela maioria das térmicas ESC/POS. Devolve b"" se a imagem não puder ser lida.
    """
    try:
        from PIL import Image
        with Image.open(path or _asset(LOGO_FILE)) as source:
            image = source.convert("RGBA")
    except Exception:
        return b""
    max_width = int(DOTS.get(int(paper), 576) * 0.72)
    if image.width > max_width:
        height = max(1, round(image.height * max_width / image.width))
        image = image.resize((max_width, height), Image.Resampling.LANCZOS)
    alpha = image.getchannel("A")
    width, height = image.size
    row_bytes = (width + 7) // 8
    data = bytearray()
    pixels = alpha.load()
    for y in range(height):
        for xb in range(row_bytes):
            byte = 0
            for bit in range(8):
                x = xb * 8 + bit
                if x < width and pixels[x, y] >= 128:
                    byte |= 0x80 >> bit
            data.append(byte)
    header = b"\x1d\x76\x30\x00" + bytes((row_bytes & 0xFF, row_bytes >> 8, height & 0xFF, height >> 8))
    # ESC a 1 centraliza; depois volta para a esquerda e pula uma linha.
    return b"\x1ba\x01" + header + bytes(data) + b"\n\x1ba\x00"


def receipt(order, paper=80, logo=True, margem=MARGEM_PADRAO):
    """Cupom em CP850 + ESC/POS, compativel com drivers que deixam RAW passar.

    Com logo=True o topo leva a logo do Deliveryzim; sem a imagem (ou desligado) sai o nome em texto.
    """
    width = 32 if int(paper) == 58 else 48
    image = logo_raster(paper) if logo else b""
    lines = ([] if image else ["DELIVERYZIM"]) + ["-" * width, "PEDIDO #" + str(order.get("numero", "")), "-" * width]
    lines.append("ENTREGA" if order.get("tipo") == "entrega" else "RETIRADA")
    if order.get("cliente"):
        lines.extend(cut_text("Cliente: " + str(order["cliente"]), width))
    if order.get("criado_em"):
        lines.extend(cut_text("Data: " + str(order["criado_em"]), width))
    for part in order.get("endereco") or []:
        lines.extend(cut_text(str(part), width))
    lines.append("-" * width)
    for item in order.get("itens", []):
        lines.extend(cut_text(f'{item.get("quantidade", "1")}x {item.get("nome", "")}', width))
        for name in ("complementos", "observacao"):
            if item.get(name):
                lines.extend(cut_text("  " + str(item[name]), width))
        if item.get("total") is not None:
            lines.append(money(item["total"]).rjust(width))
    lines.append("-" * width)
    if order.get("observacao"):
        lines.append("OBSERVACOES")
        lines.extend(cut_text(str(order["observacao"]), width))
    lines.append("PAGAMENTO: " + str(order.get("pagamento", ""))[:width - 11])
    lines.append("TOTAL: " + money(order.get("total", "0")))
    lines.append("")
    lines.append("")
    # ESC @ inicializa, LF avanca, GS V 0 corta (quando suportado pela impressora).
    return b"\x1b@" + image + ("\n".join(lines) + "\n").encode("cp850", errors="replace") + fim_do_cupom(margem)


def _wrap(text, width):
    """Quebra por palavras (sem cortar no meio quando possível)."""
    words = " ".join(str(text or "").split()).split(" ")
    lines, current = [], ""
    for word in words:
        if not word:
            continue
        while len(word) > width:
            if current:
                lines.append(current)
                current = ""
            lines.append(word[:width])
            word = word[width:]
        if not current:
            current = word
        elif len(current) + 1 + len(word) <= width:
            current += " " + word
        else:
            lines.append(current)
            current = word
    if current:
        lines.append(current)
    return lines


def _pair(label, value, width):
    """Rótulo à esquerda e valor à direita na mesma linha (o rótulo quebra se não couber)."""
    value = str(value or "")
    label = " ".join(str(label or "").split())
    room = width - len(value) - 1
    if room < 6:
        return _wrap(label, width) + [value.rjust(width)]
    parts = _wrap(label, room) or [""]
    return parts[:-1] + [parts[-1].ljust(room) + " " + value]


def job_receipt(job, paper=80, logo=True, margem=MARGEM_PADRAO):
    """Cupom pedido pelo caixa/garçom (fila de impressão do sistema), montado a partir de blocos.

    O servidor manda blocos simples (titulo, centro, destaque, texto, linha, valor, item); aqui só
    ajustamos à largura do papel. Bloco desconhecido vira texto comum, para nunca perder conteúdo.
    """
    width = 32 if int(paper) == 58 else 48
    image = logo_raster(paper) if logo else b""
    out = bytearray(b"\x1b@")
    out += image

    def enc(text):
        return str(text).encode("cp850", errors="replace")

    def line(text=""):
        out.extend(enc(text) + b"\n")

    for block in job.get("blocos") or []:
        if not isinstance(block, dict):
            continue
        kind = block.get("t")
        text = str(block.get("texto") or "")
        if kind == "titulo":
            # ESC a 1 centraliza; ESC ! 0x30 = altura e largura dupla (metade dos caracteres por linha).
            out.extend(b"\x1ba\x01\x1b!\x30")
            for part in _wrap(text, width // 2):
                line(part)
            out.extend(b"\x1b!\x00\x1ba\x00")
        elif kind == "centro":
            out.extend(b"\x1ba\x01")
            for part in _wrap(text, width):
                line(part)
            out.extend(b"\x1ba\x00")
        elif kind == "destaque":
            out.extend(b"\x1bE\x01")
            for part in _wrap(text, width):
                line(part)
            out.extend(b"\x1bE\x00")
        elif kind == "linha":
            line("-" * width)
        elif kind == "valor":
            strong = bool(block.get("forte"))
            if strong:
                out.extend(b"\x1bE\x01")
            for part in _pair(block.get("rotulo"), block.get("valor"), width):
                line(part)
            if strong:
                out.extend(b"\x1bE\x00")
        elif kind == "item":
            name = (str(block.get("qtd") or "").strip() + " " + str(block.get("nome") or "")).strip()
            out.extend(b"\x1bE\x01")
            for part in _pair(name, block.get("valor") or "", width):
                line(part)
            out.extend(b"\x1bE\x00")
            for detail in block.get("detalhes") or []:
                for part in _wrap(detail, width - 2):
                    line("  " + part)
        else:
            for part in _wrap(text, width):
                line(part)
    line()
    line()
    out.extend(fim_do_cupom(margem))
    return bytes(out)


def spool(printer, payload, label):
    if not printer:
        raise RuntimeError("Selecione uma impressora nas configuracoes.")
    handle = win32print.OpenPrinter(printer)
    started = False
    try:
        win32print.StartDocPrinter(handle, 1, (label[:100], None, "RAW"))
        started = True
        win32print.StartPagePrinter(handle)
        count = win32print.WritePrinter(handle, payload)
        win32print.EndPagePrinter(handle)
        if count != len(payload):
            raise IOError("Envio parcial ao spooler do Windows.")
    finally:
        if started:
            win32print.EndDocPrinter(handle)
        win32print.ClosePrinter(handle)
    # Sucesso significa que o Windows recebeu os bytes, NAO que o papel saiu.
