#!/usr/bin/env bash
# Seed pontual de contas de teste, com backup privado antes de inserir usuarios.
set -Eeuo pipefail
umask 077

root="/home/escotilhaprodutora-cardapim/apps/cardapim"
app="$root/current"
backups="$root/shared/backups"
[[ "$(id -un)" == "escotilhaprodutora-cardapim" ]] || { echo "Usuario inesperado"; exit 1; }
[[ -f "$app/database/seed-test-users.php" && -f "$root/shared/.env" ]] || exit 1
[[ -d "$backups" && "$(stat -c '%a' "$backups")" == "700" ]] || { echo "Diretorio privado de backup invalido"; exit 1; }
command -v mysqldump >/dev/null
command -v gzip >/dev/null

# Evitar rodar quando houver mudancas de banco nao aplicadas.
pending="$(php8.4 "$app/database/migrate.php" --status | sed -n 's/^PENDENTE //p')"
[[ -z "$pending" ]] || { echo "Migrations pendentes: abortando seed"; exit 1; }

# A reaplicacao e segura e nao muda credenciais ja geradas.
if php8.4 "$app/database/seed-test-users.php" --verify >/dev/null 2>&1; then
    echo "Contas de teste ja existentes e validadas."
    exit 0
fi

cnf="$(mktemp "$backups/.mysql-demo-users-XXXXXXXX.cnf")"
temp=""
cleanup() {
    rm -f -- "$cnf"
    if [[ -n "$temp" && -e "$temp" ]]; then rm -f -- "$temp"; fi
}
trap cleanup EXIT
php8.4 "$app/database/write-backup-config.php" "$cnf"

backup="$backups/cardapim-before-test-users-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
temp="$backup.tmp"
mysqldump --defaults-extra-file="$cnf" --single-transaction --quick \
    --no-tablespaces --skip-lock-tables --set-charset --databases cardapim | gzip -c > "$temp"
gzip -t "$temp"
[[ -s "$temp" ]] || { echo "Backup vazio, abortando."; exit 1; }
chmod 600 "$temp"
mv -- "$temp" "$backup"
temp=""
echo "Backup validado antes das contas de teste."

php8.4 "$app/database/seed-test-users.php" --apply
php8.4 "$app/database/seed-test-users.php" --verify
echo "Seed de contas de teste confirmado; senhas nao exibidas."
