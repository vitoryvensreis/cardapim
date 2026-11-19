"""Composicao de cupom termico em ESC/POS e envio RAW pelo spooler do Windows."""
from decimal import Decimal, InvalidOperation
import win32print


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


def receipt(order, paper=80):
    """Cupom em CP850 + ESC/POS, compativel com drivers que deixam RAW passar."""
    width = 32 if int(paper) == 58 else 48
    lines = ["DELIVERYZIM", "-" * width, "PEDIDO #" + str(order.get("numero", "")), "-" * width]
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
    return b"\x1b@" + ("\n".join(lines) + "\n").encode("cp850", errors="replace") + b"\x1dV\x00"


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
