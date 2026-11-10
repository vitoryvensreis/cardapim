#!/usr/bin/env bash
# Atualizacao pontual: tabelas categorias, insumos, produtos e produto_insumos, com backup validado antes.
set -Eeuo pipefail
umask 077

root="/home/escotilhaprodutora-cardapim/apps/cardapim"
app="$root/current"
backups="$root/shared/backups"

[[ "$(id -un)" == "escotilhaprodutora-cardapim" ]] || { echo "Usuario do site inesperado"; exit 1; }
[[ -f "$app/database/migrate.php" && -f "$app/database/verify-cardapio-estoque.php" ]] || exit 1
mkdir -p "$backups"
command -v mysqldump >/dev/null
command -v gzip >/dev/null

status="$(php8.4 "$app/database/migrate.php" --status)"
pending="$(printf '%s\n' "$status" | sed -n 's/^PENDENTE //p')"
# Somente estas migrations podem estar pendentes (na ordem). Uma execucao interrompida pode
# deixar apenas as ultimas pendentes: isso tambem e aceito.
allowed="$(printf '%s\n' \
    '20261110040000_create_categorias.php' \
    '20261110040100_create_insumos.php' \
    '20261110040200_create_produtos.php' \
    '20261110040300_create_produto_insumos.php')"

if [[ -z "$pending" ]]; then
    php8.4 "$app/database/verify-cardapio-estoque.php" --verify
    echo "Tabelas do cardapio/estoque ja aplicadas: sem alteracoes."
    exit 0
fi
if [[ "$allowed" != *"$pending" ]]; then
    echo "Migrations pendentes nao autorizadas detectadas; abortando."
    exit 1
fi

cnf="$(mktemp "$backups/.mysql-cardapio-XXXXXXXX.cnf")"
temp=""
cleanup() {
    rm -f -- "$cnf"
    if [[ -n "$temp" && -e "$temp" ]]; then rm -f -- "$temp"; fi
}
trap cleanup EXIT

php8.4 "$app/database/write-backup-config.php" "$cnf"
backup="$backups/cardapim-before-cardapio-estoque-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
temp="$backup.tmp"
mysqldump --defaults-extra-file="$cnf" \
    --single-transaction --quick --no-tablespaces --skip-lock-tables \
    --set-charset --databases cardapim | gzip -c > "$temp"
gzip -t "$temp"
[[ -s "$temp" ]] || { echo "Backup vazio; abortando"; exit 1; }
chmod 600 "$temp"
mv -- "$temp" "$backup"
temp=""
echo "Backup MySQL validado: $backup"

php8.4 "$app/database/migrate.php" --apply
php8.4 "$app/database/verify-cardapio-estoque.php" --verify
echo "Cardapio e estoque verificados apos a migration."
