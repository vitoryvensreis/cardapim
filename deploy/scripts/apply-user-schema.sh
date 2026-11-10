#!/usr/bin/env bash
# Atualizacao pontual com backup MySQL, sem criar nenhuma conta real.
set -Eeuo pipefail
umask 077

root="/home/escotilhaprodutora-cardapim/apps/cardapim"
app="$root/current"
backups="$root/shared/backups"

[[ "$(id -un)" == "escotilhaprodutora-cardapim" ]] || { echo "Usuario do site inesperado"; exit 1; }
[[ -f "$app/database/migrate.php" && -f "$app/database/verify-user-schema.php" ]] || exit 1
mkdir -p "$backups"
command -v mysqldump >/dev/null
command -v gzip >/dev/null

status="$(php8.4 "$app/database/migrate.php" --status)"
pending="$(printf '%s\n' "$status" | sed -n 's/^PENDENTE //p')"
expected_both="$(printf '%s\n' \
    '20261110004500_create_user.php' \
    '20261110004600_vincular_log_user.php')"
expected_final="20261110004600_vincular_log_user.php"

if [[ -z "$pending" ]]; then
    php8.4 "$app/database/verify-user-schema.php" --verify
    echo "Schema ja aplicado: sem alteracoes."
    exit 0
fi
if [[ "$pending" != "$expected_both" && "$pending" != "$expected_final" ]]; then
    echo "Migrations pendentes nao autorizadas detectadas; abortando."
    exit 1
fi

cnf="$(mktemp "$backups/.mysql-user-schema-XXXXXXXX.cnf")"
temp=""
cleanup() {
    rm -f -- "$cnf"
    if [[ -n "$temp" && -e "$temp" ]]; then rm -f -- "$temp"; fi
}
trap cleanup EXIT

php8.4 "$app/database/write-backup-config.php" "$cnf"
backup="$backups/cardapim-before-user-schema-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
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
php8.4 "$app/database/verify-user-schema.php" --verify
echo "Tabela user e chave estrangeira log_user.id_user verificadas."
