#!/usr/bin/env bash
# Atualizacao pontual: colunas de endereco em restaurantes + cadastro completo do Restaurante Modelo.
# Backup MySQL validado antes de qualquer alteracao. Idempotente.
set -Eeuo pipefail
umask 077

root="/home/escotilhaprodutora-cardapim/apps/cardapim"
app="$root/current"
backups="$root/shared/backups"

[[ "$(id -un)" == "escotilhaprodutora-cardapim" ]] || { echo "Usuario do site inesperado"; exit 1; }
[[ -f "$app/database/migrate.php" && -f "$app/database/seed-restaurante-modelo.php" ]] || exit 1
mkdir -p "$backups"
command -v mysqldump >/dev/null
command -v gzip >/dev/null

pending="$(php8.4 "$app/database/migrate.php" --status | sed -n 's/^PENDENTE //p')"
if [[ -n "$pending" && "$pending" != "20261110050000_add_endereco_restaurantes.php" ]]; then
    echo "Migrations pendentes nao autorizadas detectadas; abortando."
    exit 1
fi

cnf="$(mktemp "$backups/.mysql-endereco-XXXXXXXX.cnf")"
temp=""
cleanup() {
    rm -f -- "$cnf"
    if [[ -n "$temp" && -e "$temp" ]]; then rm -f -- "$temp"; fi
}
trap cleanup EXIT

php8.4 "$app/database/write-backup-config.php" "$cnf"
backup="$backups/cardapim-before-endereco-modelo-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
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

if [[ -n "$pending" ]]; then
    php8.4 "$app/database/migrate.php" --apply
fi
php8.4 "$app/database/verify-endereco-restaurantes.php" --verify
php8.4 "$app/database/seed-restaurante-modelo.php" --apply
echo "Endereco aplicado e Restaurante Modelo completo."
