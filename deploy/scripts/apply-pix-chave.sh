#!/usr/bin/env bash
# Backup completo antes do Pix pela chave (colunas novas em restaurante_entrega_pagamento e pedidos).
set -Eeuo pipefail
umask 077
root="/home/escotilhaprodutora-cardapim/apps/cardapim"
app="$root/current"
backups="$root/shared/backups"
[[ "$(id -un)" == "escotilhaprodutora-cardapim" ]] || { echo "Usuario inesperado"; exit 1; }
test -f "$app/database/verify-pix-chave.php"
mkdir -p "$backups"
status="$(php8.4 "$app/database/migrate.php" --status)"
pending="$(printf '%s\n' "$status" | sed -n 's/^PENDENTE //p')"
migration="20261205100000_pix_chave.php"
if [[ -z "$pending" ]]; then php8.4 "$app/database/verify-pix-chave.php" --verify; exit 0; fi
[[ "$pending" == "$migration" ]] || { echo "Migration inesperada; abortando."; exit 1; }
cnf="$(mktemp "$backups/.mysql-pix-chave-XXXXXXXX.cnf")"
temp=""
cleanup() { rm -f -- "$cnf"; [[ -z "$temp" || ! -e "$temp" ]] || rm -f -- "$temp"; }
trap cleanup EXIT
php8.4 "$app/database/write-backup-config.php" "$cnf"
backup="$backups/cardapim-before-pix-chave-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
temp="$backup.tmp"
mysqldump --defaults-extra-file="$cnf" --single-transaction --quick --no-tablespaces --skip-lock-tables --set-charset --databases cardapim | gzip -c > "$temp"
gzip -t "$temp"
test -s "$temp"
chmod 600 "$temp"
mv -- "$temp" "$backup"
temp=""
echo "Backup validado para o Pix pela chave."
php8.4 "$app/database/migrate.php" --apply
php8.4 "$app/database/verify-pix-chave.php" --verify
echo "Pix pela chave aplicado."
