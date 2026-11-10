#!/usr/bin/env bash
# Operacao pontual: schema das abas do painel do gerente (2FA, equipe, horarios, assinaturas),
# com backup MySQL verificado antes. Executada pelo usuario do site.
set -Eeuo pipefail
umask 077

root="/home/escotilhaprodutora-cardapim/apps/cardapim"
app="$root/current"
backups="$root/shared/backups"
[[ "$(id -un)" == "escotilhaprodutora-cardapim" ]] || { echo "Usuario inesperado"; exit 1; }
[[ -f "$app/database/migrate.php" && -f "$app/database/verify-gerencia-abas.php" ]] || exit 1
mkdir -p "$backups"
command -v mysqldump >/dev/null
command -v gzip >/dev/null

status="$(php8.4 "$app/database/migrate.php" --status)"
pending="$(printf '%s\n' "$status" | sed -n 's/^PENDENTE //p')"

# A migracao ja foi feita em uma tentativa anterior? Apenas conferir, sem gravar.
if [[ -z "$pending" ]]; then
  php8.4 "$app/database/verify-gerencia-abas.php" --verify
  echo "Schema ja atualizado; nenhuma alteracao repetida."
  exit 0
fi

expected="$(printf '%s\n' \
  '20261110070000_add_exclusao_2fa_user.php' \
  '20261110070100_create_user_codigos_recuperacao.php' \
  '20261110070200_create_restaurante_horarios.php' \
  '20261110070300_create_assinaturas.php')"

if [[ "$pending" != "$expected" ]]; then
  echo "Migrations pendentes nao correspondem exatamente as quatro autorizadas. Abortando."
  exit 1
fi

cnf="$(mktemp "$backups/.mysql-gerencia-abas-XXXXXXXX.cnf")"
temp=""
cleanup() {
  rm -f -- "$cnf"
  if [[ -n "$temp" && -e "$temp" ]]; then rm -f -- "$temp"; fi
}
trap cleanup EXIT

php8.4 "$app/database/write-backup-config.php" "$cnf"
backup="$backups/cardapim-before-gerencia-abas-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
temp="$backup.tmp"

# pipefail interrompe o processo em caso de erro do mysqldump OU gzip.
mysqldump --defaults-extra-file="$cnf" \
  --single-transaction --quick --no-tablespaces --skip-lock-tables \
  --set-charset --databases cardapim | gzip -c > "$temp"
gzip -t "$temp"
[[ -s "$temp" ]] || { echo "Backup vazio; cancelando."; exit 1; }
chmod 600 "$temp"
mv -- "$temp" "$backup"
temp=""

echo "Backup MySQL verificado: $backup"
php8.4 "$app/database/migrate.php" --apply
php8.4 "$app/database/verify-gerencia-abas.php" --verify
php8.4 "$app/database/verify-user-schema.php" --verify
echo "Schema das abas da gerencia aplicado e verificado."
