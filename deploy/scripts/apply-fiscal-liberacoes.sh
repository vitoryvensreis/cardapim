#!/usr/bin/env bash
# Backup completo antes de aplicar exclusivamente as migrations fiscais previstas.
set -Eeuo pipefail
umask 077
root="/home/escotilhaprodutora-cardapim/apps/cardapim"
app="$root/current"
backups="$root/shared/backups"
[[ "$(id -un)" == "escotilhaprodutora-cardapim" ]] || { echo "Usuario inesperado"; exit 1; }
test -f "$app/database/verify-fiscal-liberacoes.php"
mkdir -p "$backups"
status="$(php8.4 "$app/database/migrate.php" --status)"
pending="$(printf '%s\n' "$status" | sed -n 's/^PENDENTE //p')"
migration="20261125110000_fiscal_liberacoes.php"
duas="$(printf '%s\n%s' '20261125100000_fiscal_produtos.php' "$migration")"
if [[ -z "$pending" ]]; then
  php8.4 "$app/database/verify-fiscal-liberacoes.php" --verify
  php8.4 "$app/database/verify-fiscal-produtos.php" --verify
  exit 0
fi
[[ "$pending" == "$migration" || "$pending" == "$duas" ]] || { echo "Migration inesperada; abortando."; exit 1; }
cnf="$(mktemp "$backups/.mysql-fiscal-liberacoes-XXXXXXXX.cnf")"
temp=""
cleanup() { rm -f -- "$cnf"; [[ -z "$temp" || ! -e "$temp" ]] || rm -f -- "$temp"; }
trap cleanup EXIT
php8.4 "$app/database/write-backup-config.php" "$cnf"
backup="$backups/cardapim-before-fiscal-liberacoes-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
temp="$backup.tmp"
mysqldump --defaults-extra-file="$cnf" --single-transaction --quick --no-tablespaces --skip-lock-tables --set-charset --databases cardapim | gzip -c > "$temp"
gzip -t "$temp"
test -s "$temp"
chmod 600 "$temp"
mv -- "$temp" "$backup"
temp=""
echo "Backup validado para a liberação fiscal."
php8.4 "$app/database/migrate.php" --apply
php8.4 "$app/database/verify-fiscal-liberacoes.php" --verify
php8.4 "$app/database/verify-fiscal-produtos.php" --verify
echo "Liberação fiscal aplicada."
