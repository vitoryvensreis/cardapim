#!/usr/bin/env bash
# Backup e bootstrap antes de apontar "current" para a nova release.
set -Eeuo pipefail
umask 077

root="$1"
release_id="$2"
[[ "$root" == "/home/escotilhaprodutora-cardapim/apps/cardapim" ]] || exit 1
[[ "$release_id" =~ ^[0-9a-f]{40}-[0-9]+-[0-9]+$ ]] || exit 1
[[ "$(id -un)" == "escotilhaprodutora-cardapim" ]] || exit 1

app="$root/releases/$release_id"
shared="$root/shared"
backups="$shared/backups"
[[ -f "$app/database/seed-configuracoes-restaurante.php" && -f "$shared/.env" ]] || exit 1
mkdir -p "$backups"
ln -sfn "$shared/.env" "$app/.env"

status="$(php8.4 "$app/database/migrate.php" --status)"
pending="$(printf '%s\n' "$status" | sed -n 's/^PENDENTE //p')"
if [[ -n "$pending" && "$pending" != "20261110001000_create_configuracoes.php" ]]; then
    echo "Migrations pendentes inesperadas; cancelando bootstrap."
    exit 1
fi

cnf="$(mktemp "$backups/.config-backup-XXXXXXXX.cnf")"
temp=""
cleanup() {
    rm -f -- "$cnf"
    if [[ -n "$temp" && -e "$temp" ]]; then rm -f -- "$temp"; fi
}
trap cleanup EXIT
php8.4 "$app/database/write-backup-config.php" "$cnf"

backup="$backups/cardapim-before-configuracoes-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
temp="$backup.tmp"
mysqldump --defaults-extra-file="$cnf" --single-transaction --quick \
    --no-tablespaces --skip-lock-tables --set-charset --databases cardapim | gzip -c > "$temp"
gzip -t "$temp"
[[ -s "$temp" ]] || { echo "Backup vazio; cancelando"; exit 1; }
chmod 600 "$temp"
mv -- "$temp" "$backup"
temp=""
echo "Backup MySQL confirmado: $backup"

php8.4 "$app/database/migrate.php" --apply
php8.4 "$app/database/seed-configuracoes-restaurante.php" --apply
php8.4 "$app/database/seed-configuracoes-restaurante.php" --verify
echo "Tabela configuracoes e restaurante modelo validados antes da ativacao."
