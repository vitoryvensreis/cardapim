#!/usr/bin/env bash
# Teste HTTP da IMPORTACAO DO CARDAPIO POR PLANILHA (Gerencia > Integracoes > Importar cardapio):
# modelo para baixar, envio com erros (nada importado), conferencia, importacao com categoria e complementos,
# grupo igual reaproveitado, reenvio sem nada novo, CSRF, codigo da conferencia e permissao por cargo/restaurante.
# Uso: bash tests/http/importar-cardapio.sh <base> <slug>
set -Eeuo pipefail
base="$1"
slug="$2"
senha='Senha#CI2026'
g="$base/$slug/gerencia"
imp="$g/integracoes/importar-cardapio"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
falhas=0
r4="$(printf '%04d' $((RANDOM % 10000)))"

ok() { printf '  ok  %s\n' "$1"; }
falha() { printf '  FALHOU %s\n' "$1"; falhas=$((falhas + 1)); }
esperar() { if [[ "$2" == "$3" ]]; then ok "$1"; else falha "$1 (obtido: $2, esperado: $3)"; fi; }
contem() { if grep -q -- "$3" "$2"; then ok "$1"; else falha "$1 (texto ausente: $3)"; fi; }
nao_contem() { if grep -q -- "$3" "$2"; then falha "$1 (texto presente: $3)"; else ok "$1"; fi; }
token() { curl -s -c "$1" -b "$1" -o "$tmp/token.html" "$2"; grep -o -m1 'name="_csrf" value="[a-f0-9]*"' "$tmp/token.html" | sed 's/.*value="//; s/"$//' || true; }
login_equipe() {
  local t; rm -f "$1"; t="$(token "$1" "$base/login")"
  curl -s -o /dev/null -c "$1" -b "$1" -w '%{http_code}' --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$2" --data-urlencode "senha=$senha" "$base/login"
}
aviso() { curl -s -c "$1" -b "$1" "$2" > "$tmp/aviso.html"; php -r 'if (preg_match("~data-flash>.*?<p>(.*?)</p>~s", file_get_contents($argv[1]), $m)) echo html_entity_decode($m[1]);' "$tmp/aviso.html"; }
pagina() { curl -s -o "$tmp/pagina.html" -b "$1" -c "$1" -w '%{http_code}' "$2"; }
enviar_planilha() { # jar arquivo nome -> codigo http
  local t; t="$(token "$1" "$imp")"
  curl -s -o "$tmp/resp.html" -c "$1" -b "$1" -w '%{http_code}' --form-string "_csrf=$t" -F "planilha=@$2;filename=$3" "$imp/conferir"
}
codigo_conferencia() { grep -o -m1 'name="codigo" value="[a-f0-9]*"' "$tmp/pagina.html" | sed 's/.*value="//; s/"$//' || true; }
confirmar() { # jar codigo -> "http location"
  local t; t="$(token "$1" "$imp")"
  curl -s -o "$tmp/resp.html" -c "$1" -b "$1" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" --data-urlencode "codigo=$2" "$imp/importar"
}

gj="$tmp/gerente"; wj="$tmp/garcom"; oj="$tmp/outro"
esperar "gerente entra" "$(login_equipe "$gj" ci_gerente)" "303"
esperar "garcom entra" "$(login_equipe "$wj" ci_garcom)" "303"
esperar "gerente de outro restaurante entra" "$(login_equipe "$oj" ci_outro_gerente)" "303"

echo "Tela e modelo"
pagina "$gj" "$g/integracoes" >/dev/null
contem "cartao na aba Integracoes" "$tmp/pagina.html" 'integracoes/importar-cardapio'
esperar "tela abre" "$(pagina "$gj" "$imp")" "200"
contem "titulo" "$tmp/pagina.html" 'Importar cardápio'
contem "botao do modelo" "$tmp/pagina.html" 'importar-cardapio/modelo'
contem "envio em multipart" "$tmp/pagina.html" 'enctype="multipart/form-data"'
nao_contem "sem script inline" "$tmp/pagina.html" 'onclick='
curl -s -b "$gj" -D "$tmp/modelo.h" -o "$tmp/modelo.csv" "$imp/modelo"
contem "modelo e CSV para baixar" "$tmp/modelo.h" 'attachment; filename="modelo-cardapio-'
contem "modelo com os titulos" "$tmp/modelo.csv" 'Categoria;Produto;Descrição;Preço;Código;Complemento;Obrigatório;Máximo;Opção;Preço da opção'
esperar "garcom nao abre a importacao" "$(pagina "$wj" "$imp")" "403"
esperar "gerente de outro restaurante nao abre" "$(pagina "$oj" "$imp")" "403"
esperar "garcom nao envia planilha" "$(enviar_planilha "$wj" "$tmp/modelo.csv" modelo.csv)" "403"

echo "Envio sem arquivo e arquivo errado"
t="$(token "$gj" "$imp")"
esperar "sem arquivo volta" "$(curl -s -o /dev/null -c "$gj" -b "$gj" -w '%{http_code}' --form-string "_csrf=$t" "$imp/conferir")" "303"
esperar "avisa para escolher" "$(aviso "$gj" "$imp")" "Escolha a planilha antes de enviar."
printf 'nao sou planilha' > "$tmp/foto.png"
esperar "extensao errada volta" "$(enviar_planilha "$gj" "$tmp/foto.png" foto.png)" "303"
[[ "$(aviso "$gj" "$imp")" == *'.xlsx'* ]] && ok "orienta o formato" || falha "mensagem do formato"
esperar "sem CSRF recusado" "$(curl -s -o /dev/null -b "$gj" -c "$gj" -w '%{http_code}' -F "planilha=@$tmp/modelo.csv;filename=m.csv" "$imp/conferir")" "303"
[[ "$(aviso "$gj" "$imp")" == *'sessão expirou'* ]] && ok "CSRF explicado" || falha "mensagem de CSRF"

echo "Planilha com erros: nada importado"
cat > "$tmp/erros.csv" <<CSV
Categoria;Produto;Preço;Complemento;Obrigatório;Opção;Preço da opção
CI Bebidas $r4;CI Suco $r4;7,00;;;;
;CI Suco $r4;8,00;;;;
;CI Refri $r4;abc;;;;
;;;;;Grande;2,00
CSV
esperar "planilha com erro e conferida" "$(enviar_planilha "$gj" "$tmp/erros.csv" erros.csv)" "303"
pagina "$gj" "$imp" >/dev/null
contem "mostra a conferencia" "$tmp/pagina.html" 'Conferência: erros.csv'
contem "linha do nome repetido" "$tmp/pagina.html" 'Linha 3'
contem "explica o repetido" "$tmp/pagina.html" 'aparece de novo na linha 2'
contem "preco invalido" "$tmp/pagina.html" 'não é um valor válido'
contem "pede para corrigir" "$tmp/pagina.html" 'Corrija a planilha e envie de novo'
nao_contem "sem botao de importar" "$tmp/pagina.html" 'name="codigo"'

echo "Planilha certa: conferir e importar"
cat > "$tmp/certa.csv" <<CSV
Categoria;Produto;Descrição;Preço;Código;Complemento;Obrigatório;Máximo;Opção;Preço da opção
CI Porções $r4;CI Batata $r4;300 g;18,00;CIB$r4;CI Tamanho $r4;Sim;1;Pequena;0
;;;;;;;;Grande;10,00
;CI Macaxeira $r4;;19,00;;CI Tamanho $r4;Sim;1;Pequena;
;;;;;;;;Grande;10
;CI Bolinha $r4;12 unidades;19,00;;CI Sabor $r4;sim;;Queijo;
;;;;;;;;Frango;
;;;;;CI Extras $r4;Não;2;Cheddar;3,50
CSV
esperar "planilha certa conferida" "$(enviar_planilha "$gj" "$tmp/certa.csv" certa.csv)" "303"
pagina "$gj" "$imp" >/dev/null
contem "3 produtos novos" "$tmp/pagina.html" 'Importar 3 produtos'
contem "previa com categoria" "$tmp/pagina.html" "CI Porções $r4"
contem "previa com complemento" "$tmp/pagina.html" "CI Tamanho $r4"
contem "previa com preco da opcao" "$tmp/pagina.html" 'Grande +R$ 10,00'
contem "grupo igual conta uma vez (5 opcoes novas)" "$tmp/pagina.html" '5 opções'
codigo="$(codigo_conferencia)"
[[ -n "$codigo" ]] && ok "codigo da conferencia" || falha "codigo da conferencia ausente"
esperar "codigo errado recusado" "$(confirmar "$gj" 'abc' | cut -d' ' -f1)" "303"
[[ "$(aviso "$gj" "$imp")" == *'expirou'* ]] && ok "codigo errado explicado" || falha "mensagem do codigo errado"
pagina "$gj" "$g/estoque/produtos" >/dev/null
nao_contem "nada importado com codigo errado" "$tmp/pagina.html" "CI Batata $r4"
resp="$(confirmar "$gj" "$codigo")"
esperar "importa e vai para os produtos" "$(cut -d' ' -f1 <<<"$resp")" "303"
[[ "$resp" == *'/estoque/produtos'* ]] && ok "redireciona para Estoque > Produtos" || falha "destino: $resp"
msg="$(aviso "$gj" "$g/estoque/produtos")"
[[ "$msg" == '3 produtos importados.'* ]] && ok "mensagem de sucesso" || falha "mensagem: $msg"
pagina "$gj" "$g/estoque/produtos" >/dev/null
contem "produto importado na lista" "$tmp/pagina.html" "CI Batata $r4"
contem "categoria criada" "$tmp/pagina.html" "CI Porções $r4"
contem "codigo do produto" "$tmp/pagina.html" "Cód. CIB$r4"
contem "preco do produto" "$tmp/pagina.html" 'R$ 18,00'
pagina "$gj" "$g/estoque/complementos" >/dev/null
n="$( (grep -o "CI Tamanho $r4" "$tmp/pagina.html" || true) | wc -l | tr -d ' ')"
[[ "$n" -ge 1 ]] && ok "grupo Tamanho criado" || falha "grupo Tamanho ausente"
grupos="$( (grep -o "<h2 id=\"grupo-[0-9]*\">CI Tamanho $r4</h2>" "$tmp/pagina.html" || true) | wc -l | tr -d ' ')"
esperar "grupo igual reaproveitado (1 grupo para 2 produtos)" "$grupos" "1"
contem "opcao com preco" "$tmp/pagina.html" '+ R$ 10,00'
pagina "$gj" "$imp" >/dev/null
nao_contem "conferencia limpa depois de importar" "$tmp/pagina.html" 'Conferência:'
esperar "repetir a confirmacao nao importa de novo" "$(confirmar "$gj" "$codigo" | cut -d' ' -f1)" "303"

echo "Reenviar a mesma planilha: nada novo"
enviar_planilha "$gj" "$tmp/certa.csv" certa.csv >/dev/null
pagina "$gj" "$imp" >/dev/null
contem "avisa que ja existem" "$tmp/pagina.html" 'Já cadastrados (não mudam)'
contem "nada novo para importar" "$tmp/pagina.html" 'Não há nada novo para importar'
nao_contem "sem botao de importar" "$tmp/pagina.html" 'name="codigo"'
t="$(token "$gj" "$imp")"
esperar "descartar a conferencia" "$(curl -s -o /dev/null -c "$gj" -b "$gj" -w '%{http_code}' --data-urlencode "_csrf=$t" "$imp/descartar")" "303"
[[ "$(aviso "$gj" "$imp")" == *'Nada foi importado'* ]] && ok "descartar explicado" || falha "mensagem de descartar"

echo "Planilha .xlsx"
if php -r 'exit(class_exists("ZipArchive") ? 0 : 1);'; then
  php -r '
    $z = new ZipArchive(); $z->open($argv[1], ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString("[Content_Types].xml", "<?xml version=\"1.0\"?><Types xmlns=\"http://schemas.openxmlformats.org/package/2006/content-types\"/>");
    $z->addFromString("xl/workbook.xml", "<?xml version=\"1.0\"?><workbook xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\"><sheets><sheet name=\"A\" sheetId=\"1\" r:id=\"rId1\"/></sheets></workbook>");
    $z->addFromString("xl/_rels/workbook.xml.rels", "<?xml version=\"1.0\"?><Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\"><Relationship Id=\"rId1\" Type=\"x\" Target=\"worksheets/sheet1.xml\"/></Relationships>");
    $c = fn ($r, $v) => "<c r=\"$r\" t=\"inlineStr\"><is><t>" . htmlspecialchars($v) . "</t></is></c>";
    $z->addFromString("xl/worksheets/sheet1.xml", "<?xml version=\"1.0\"?><worksheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\"><sheetData>"
      . "<row r=\"1\">" . $c("A1", "Categoria") . $c("B1", "Produto") . $c("C1", "Preço") . "</row>"
      . "<row r=\"2\">" . $c("A2", "CI Sucos " . $argv[2]) . $c("B2", "CI Suco Cajá " . $argv[2]) . "<c r=\"C2\"><v>7.990000000000001</v></c></row>"
      . "</sheetData></worksheet>");
    $z->close();' "$tmp/cardapio.xlsx" "$r4"
  esperar "xlsx conferido" "$(enviar_planilha "$gj" "$tmp/cardapio.xlsx" cardapio.xlsx)" "303"
  pagina "$gj" "$imp" >/dev/null
  contem "xlsx lido com acento" "$tmp/pagina.html" "CI Suco Cajá $r4"
  contem "preco do excel arredondado" "$tmp/pagina.html" 'R$ 7,99'
  contem "xlsx pronto para importar" "$tmp/pagina.html" 'Importar 1 produto'
else
  ok "servidor sem ZipArchive: xlsx nao testado"
fi

if [[ "$falhas" -gt 0 ]]; then
  echo "importar-cardapio: $falhas falha(s)"
  exit 1
fi
echo "importar-cardapio: tudo certo"
