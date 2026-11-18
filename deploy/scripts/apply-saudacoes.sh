#!/usr/bin/env bash
# Uso unico: schema aditivo, com backup validado. Executar como usuario do site apos ativar release.
set -Eeuo pipefail
umask 077
root="/home/escotilhaprodutora-cardapim/apps/cardapim"
app="$root/current"
backups="$root/shared/backups"
[[ "$(id -un)" == "escotilhaprodutora-cardapim" ]] || { echo "Usuario inesperado"; exit 1; }
[[ -f "$app/database/verify-saudacoes.php" ]] || exit 1
mkdir -p "$backups"
status="$(php8.4 "$app/database/migrate.php" --status)"
pending="$(printf '%s\n' "$status" | sed -n 's/^PENDENTE //p')"
if [[ -z "$pending" ]]; then
  php8.4 "$app/database/verify-saudacoes.php" --verify
  exit 0
fi
[[ "$pending" == '20261118190000_create_whatsapp_saudacoes.php' ]] || { echo "Migration inesperada; abortando."; exit 1; }
cnf="$(mktemp "$backups/.mysql-saudacao-XXXXXXXX.cnf")"
temp=""
cleanup() { rm -f -- "$cnf"; [[ -z "$temp" || ! -e "$temp" ]] || rm -f -- "$temp"; }
trap cleanup EXIT
php8.4 "$app/database/write-backup-config.php" "$cnf"
backup="$backups/cardapim-before-saudacoes-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
temp="$backup.tmp"
mysqldump --defaults-extra-file="$cnf" --single-transaction --quick --no-tablespaces --skip-lock-tables --set-charset --databases cardapim | gzip -c > "$temp"
gzip -t "$temp"
[[ -s "$temp" ]] || { echo "Backup vazio"; exit 1; }
chmod 600 "$temp"
mv -- "$temp" "$backup"
temp=""
echo "Backup validado: $backup"
php8.4 "$app/database/migrate.php" --apply
php8.4 "$app/database/verify-saudacoes.php" --verify
echo "Schema de saudacoes aplicado."
