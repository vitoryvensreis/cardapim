#!/usr/bin/env bash
# Backup completo antes de aplicar somente a sequência fiscal previamente aprovada.
set -Eeuo pipefail
umask 077
root="/home/escotilhaprodutora-cardapim/apps/cardapim"
app="$root/current"
backups="$root/shared/backups"
[[ "$(id -un)" == "escotilhaprodutora-cardapim" ]] || { echo "Usuario inesperado"; exit 1; }
test -f "$app/database/verify-fiscal-catalogos.php"
mkdir -p "$backups"
status="$(php8.4 "$app/database/migrate.php" --status)"
pending="$(printf '%s\n' "$status" | sed -n 's/^PENDENTE //p')"
migration="20261125120000_fiscal_catalogos.php"
duas="$(printf '%s\n%s' '20261125110000_fiscal_liberacoes.php' "$migration")"
tres="$(printf '%s\n%s\n%s' '20261125100000_fiscal_produtos.php' '20261125110000_fiscal_liberacoes.php' "$migration")"
verificar() {
  php8.4 "$app/database/verify-fiscal-produtos.php" --verify
  php8.4 "$app/database/verify-fiscal-liberacoes.php" --verify
  php8.4 "$app/database/verify-fiscal-catalogos.php" --verify
}
if [[ -z "$pending" ]]; then verificar; exit 0; fi
[[ "$pending" == "$migration" || "$pending" == "$duas" || "$pending" == "$tres" ]] || { echo "Migration inesperada; abortando."; exit 1; }
cnf="$(mktemp "$backups/.mysql-fiscal-catalogos-XXXXXXXX.cnf")"
temp=""
cleanup() { rm -f -- "$cnf"; [[ -z "$temp" || ! -e "$temp" ]] || rm -f -- "$temp"; }
trap cleanup EXIT
php8.4 "$app/database/write-backup-config.php" "$cnf"
backup="$backups/cardapim-before-fiscal-catalogos-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
temp="$backup.tmp"
mysqldump --defaults-extra-file="$cnf" --single-transaction --quick --no-tablespaces --skip-lock-tables --set-charset --databases cardapim | gzip -c > "$temp"
gzip -t "$temp"
test -s "$temp"
chmod 600 "$temp"
mv -- "$temp" "$backup"
temp=""
echo "Backup validado para os catálogos fiscais."
php8.4 "$app/database/migrate.php" --apply
verificar
echo "Catálogos e composições fiscais aplicados."
