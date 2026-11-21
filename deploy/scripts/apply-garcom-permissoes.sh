#!/usr/bin/env bash
# Executar apenas no job de deploy dedicado a criar as permissoes do garcom.
# Backup completo antes de DDL.
set -Eeuo pipefail
umask 077
root="/home/escotilhaprodutora-cardapim/apps/cardapim"
app="$root/current"
backups="$root/shared/backups"
[[ "$(id -un)" == "escotilhaprodutora-cardapim" ]] || { echo "Usuario inesperado"; exit 1; }
test -f "$app/database/verify-garcom-permissoes.php"
mkdir -p "$backups"
status="$(php8.4 "$app/database/migrate.php" --status)"
pending="$(printf '%s\n' "$status" | sed -n 's/^PENDENTE //p')"
migration="20261121400000_create_restaurante_garcom_permissoes.php"
if [[ -z "$pending" ]]; then
  php8.4 "$app/database/verify-garcom-permissoes.php" --verify
  exit 0
fi
[[ "$pending" == "$migration" ]] || { echo "Migration inesperada; abortando."; exit 1; }
cnf="$(mktemp "$backups/.mysql-garcom-permissoes-XXXXXXXX.cnf")"
temp=""
cleanup() { rm -f -- "$cnf"; [[ -z "$temp" || ! -e "$temp" ]] || rm -f -- "$temp"; }
trap cleanup EXIT
php8.4 "$app/database/write-backup-config.php" "$cnf"
backup="$backups/cardapim-before-garcom-permissoes-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
temp="$backup.tmp"
mysqldump --defaults-extra-file="$cnf" --single-transaction --quick --no-tablespaces --skip-lock-tables --set-charset --databases cardapim | gzip -c > "$temp"
gzip -t "$temp"
test -s "$temp"
chmod 600 "$temp"
mv -- "$temp" "$backup"
temp=""
echo "Backup validado para as permissoes do garcom."
php8.4 "$app/database/migrate.php" --apply
php8.4 "$app/database/verify-garcom-permissoes.php" --verify
echo "Permissoes do garcom aplicadas."
