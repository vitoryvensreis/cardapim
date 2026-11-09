#!/usr/bin/env bash
# Operacao controlada, unica e segura, executada pelo usuario do site apos o deploy.
set -Eeuo pipefail
umask 077

root="/home/escotilhaprodutora-cardapim/apps/cardapim"
shared="$root/shared"
app="$root/current"
[[ "$(id -un)" == "escotilhaprodutora-cardapim" ]] || { echo "Usuario de deploy incorreto"; exit 1; }
[[ -f "$app/database/seed-demo-planos.php" && -f "$shared/.env" ]] || exit 1
[[ -d "$shared/backups" ]] || mkdir -p "$shared/backups"
command -v mysqldump >/dev/null
command -v gzip >/dev/null

# Reaplicacoes inocentes: se todos os planos ja estao presentes, apenas verificar.
if php8.4 "$app/database/seed-demo-planos.php" --verify >/dev/null 2>&1; then
  echo "Schema e planos ficticios ja aplicados; nenhuma gravacao necessaria."
  exit 0
fi

cnf="$(mktemp "$shared/backups/.mysql-demo-XXXXXXXX.cnf")"
backup_tmp=""
cleanup() {
  rm -f -- "$cnf"
  if [[ -n "$backup_tmp" && -e "$backup_tmp" ]]; then rm -f -- "$backup_tmp"; fi
}
trap cleanup EXIT

php8.4 "$app/database/write-backup-config.php" "$cnf"

backup="$shared/backups/cardapim-before-demo-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
backup_tmp="$backup.tmp"

# O mysqldump e o gzip DEVEM terminar sem erro antes de aplicar DDL.
mysqldump --defaults-extra-file="$cnf" \
  --single-transaction --quick --no-tablespaces --skip-lock-tables \
  --set-charset --databases cardapim | gzip -c > "$backup_tmp"
gzip -t "$backup_tmp"
[[ -s "$backup_tmp" ]] || { echo "Arquivo de backup vazio"; exit 1; }
chmod 600 "$backup_tmp"
mv -- "$backup_tmp" "$backup"
backup_tmp=""
echo "Backup criado antes das alteracoes: $backup"

php8.4 "$app/database/migrate.php" --apply
php8.4 "$app/database/seed-demo-planos.php" --apply
php8.4 "$app/database/seed-demo-planos.php" --verify
echo "Atualizacao concluida com backup e tres planos de demonstracao."
