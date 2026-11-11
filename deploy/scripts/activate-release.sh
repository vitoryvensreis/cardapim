#!/usr/bin/env bash
# Ativacao de release executada pelo usuario nao-root do CloudPanel.
# Args: diretorio, identificador, URL HTTPS, migrations(true|false).
set -Eeuo pipefail
umask 027

root="$1"
release_id="$2"
site_url="$3"
migrations="$4"

[[ "$root" =~ ^/home/[A-Za-z0-9._-]+/apps/cardapim$ ]] || { echo "Diretorio inseguro"; exit 1; }
[[ "$release_id" =~ ^[0-9a-f]{40}-[0-9]+-[0-9]+$ ]] || { echo "Release invalida"; exit 1; }
[[ "$site_url" =~ ^https://[A-Za-z0-9.-]+/?$ ]] || { echo "URL invalida"; exit 1; }
[[ "$migrations" == "false" || "$migrations" == "true" ]] || exit 1
[[ "$EUID" -ne 0 ]] || { echo "Nao execute o deploy como root"; exit 1; }

release="$root/releases/$release_id"
shared="$root/shared"
[[ -f "$release/public/index.php" ]] || { echo "Release sem front controller"; exit 1; }
[[ -f "$shared/.env" ]] || { echo "Configure $shared/.env com permissoes 600"; exit 1; }

mkdir -p "$shared"/storage/{cache,logs,uploads,exports} "$shared/backups"
chmod 700 "$shared/backups"

# Remove somente pastas auxiliares da nova release, nunca dados compartilhados.
if [[ -e "$release/storage" && ! -L "$release/storage" ]]; then
  rm -rf -- "$release/storage"
fi
ln -sfn "$shared/storage" "$release/storage"
ln -sfn "$shared/.env" "$release/.env"

php_bin="$(command -v php8.4 || command -v php || true)"
[[ -n "$php_bin" ]] || { echo "PHP CLI nao encontrado"; exit 1; }
"$php_bin" -v | head -n 1
"$php_bin" -r 'exit(PHP_VERSION_ID >= 80400 && PHP_VERSION_ID < 80500 ? 0 : 1);' || {
  echo "O CLI deve executar PHP 8.4"; exit 1;
}

if [[ -f "$release/composer.lock" ]]; then
  composer_bin="$(command -v composer || true)"
  [[ -n "$composer_bin" ]] || { echo "Composer nao instalado"; exit 1; }
  (cd "$release" && "$php_bin" "$composer_bin" install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader)
fi

find "$release/app" "$release/bootstrap" "$release/database" "$release/public" "$release/routes" \
  -type f -name '*.php' -print0 | xargs -0 -r -n1 "$php_bin" -l >/dev/null
echo "Validacao PHP concluida."

if [[ "$migrations" == "true" ]]; then
  cnf="$shared/mysql-backup.cnf"
  [[ -f "$cnf" ]] || { echo "Backup MySQL nao configurado em $cnf"; exit 1; }
  [[ "$(stat -c '%a' "$cnf")" == "600" ]] || { echo "Permissao 600 obrigatoria em $cnf"; exit 1; }
  command -v mysqldump >/dev/null || { echo "mysqldump nao instalado"; exit 1; }
  command -v gzip >/dev/null || { echo "gzip nao instalado"; exit 1; }
  backup="$shared/backups/cardapim-$(date -u +%Y%m%dT%H%M%SZ)-$release_id.sql.gz"
  mysqldump --defaults-extra-file="$cnf" --single-transaction --quick --no-tablespaces --set-charset --databases cardapim | gzip > "$backup"
  chmod 600 "$backup"
  echo "Backup criado: $backup"
  "$php_bin" "$release/database/migrate.php" --apply
fi

old_target=""
if [[ -L "$root/current" ]]; then
  old_target="$(readlink "$root/current")"
elif [[ -e "$root/current" ]]; then
  echo "O caminho current nao e um symlink. Abortando para nao sobrescrever."
  exit 1
fi

tmp_link="$root/.current-$release_id"
ln -s "$release" "$tmp_link"
mv -Tf "$tmp_link" "$root/current"

check_health() {
  page="$(curl -fsSL --max-time 20 "$site_url/" || true)"
  [[ "$page" == *"Seu restaurante cresce"* ]] || return 1
  curl -fsS --max-time 20 "$site_url/login" >/dev/null || return 1
  curl -fsS --max-time 20 "$site_url/assets/css/site.css" >/dev/null || return 1
  # Bibliotecas de terceiros em public/assets/vendor (mapa do cliente e QR Code do 2FA) precisam estar publicadas.
  curl -fsS --max-time 20 "$site_url/assets/vendor/leaflet/leaflet.js" >/dev/null || return 1
  curl -fsS --max-time 20 "$site_url/assets/vendor/qrcode-generator/qrcode.js" >/dev/null || return 1
  # /status so retorna HTTP 200 quando o PHP acessa o MySQL.
  curl -fsS --max-time 20 -H 'Accept: application/json' "$site_url/status" >/dev/null || return 1
}
if ! check_health; then
  echo "Falha de healthcheck HTTP: revertendo symlink."
  if [[ -n "$old_target" ]]; then
    rollback_link="$root/.rollback-$release_id"
    ln -s "$old_target" "$rollback_link"
    mv -Tf "$rollback_link" "$root/current"
  else
    rm -- "$root/current"
  fi
  exit 1
fi
echo "Deploy validado: $site_url (release $release_id)"
