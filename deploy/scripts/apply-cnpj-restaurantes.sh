#!/usr/bin/env bash
# Atualizacao pontual: CHECK de formato em restaurantes.cnpj, com backup MySQL validado antes.
set -Eeuo pipefail
umask 077

root="/home/escotilhaprodutora-cardapim/apps/cardapim"
app="$root/current"
backups="$root/shared/backups"

[[ "$(id -un)" == "escotilhaprodutora-cardapim" ]] || { echo "Usuario do site inesperado"; exit 1; }
[[ -f "$app/database/migrate.php" && -f "$app/database/verify-cnpj-restaurantes.php" ]] || exit 1
mkdir -p "$backups"
command -v mysqldump >/dev/null
command -v gzip >/dev/null

status="$(php8.4 "$app/database/migrate.php" --status)"
pending="$(printf '%s\n' "$status" | sed -n 's/^PENDENTE //p')"
expected="20261110030000_check_cnpj_restaurantes.php"

if [[ -z "$pending" ]]; then
    php8.4 "$app/database/verify-cnpj-restaurantes.php" --verify
    echo "CHECK de CNPJ ja aplicado: sem alteracoes."
    exit 0
fi
if [[ "$pending" != "$expected" ]]; then
    echo "Migrations pendentes nao autorizadas detectadas; abortando."
    exit 1
fi

cnf="$(mktemp "$backups/.mysql-cnpj-XXXXXXXX.cnf")"
temp=""
cleanup() {
    rm -f -- "$cnf"
    if [[ -n "$temp" && -e "$temp" ]]; then rm -f -- "$temp"; fi
}
trap cleanup EXIT

php8.4 "$app/database/write-backup-config.php" "$cnf"
backup="$backups/cardapim-before-cnpj-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
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
php8.4 "$app/database/verify-cnpj-restaurantes.php" --verify
echo "restaurantes.cnpj: CHAR(14), unico e CHECK de 14 digitos verificados."
