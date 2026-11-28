#!/usr/bin/env bash
# Ativacao de release executada pelo usuario nao-root do CloudPanel.
# Args: diretorio, identificador, URL HTTPS, migrations(true|false).
set -Eeuo pipefail
umask 027

root="$1"
release_id="$2"
site_url="$3"
migrations="$4"
desktop_agent_schema="${5:-false}"

[[ "$root" =~ ^/home/[A-Za-z0-9._-]+/apps/cardapim$ ]] || { echo "Diretorio inseguro"; exit 1; }
[[ "$release_id" =~ ^[0-9a-f]{40}-[0-9]+-[0-9]+$ ]] || { echo "Release invalida"; exit 1; }
[[ "$site_url" =~ ^https://[A-Za-z0-9.-]+/?$ ]] || { echo "URL invalida"; exit 1; }
[[ "$migrations" == "false" || "$migrations" == "true" ]] || exit 1
[[ "$desktop_agent_schema" == "false" || "$desktop_agent_schema" == "true" ]] || exit 1
if [[ "$desktop_agent_schema" == "true" && "$migrations" != "true" ]]; then
  echo "Schema do desktop requer migracoes explicitamente habilitadas."
  exit 1
fi
[[ "$EUID" -ne 0 ]] || { echo "Nao execute o deploy como root"; exit 1; }

release="$root/releases/$release_id"
shared="$root/shared"
[[ -f "$release/public/index.php" ]] || { echo "Release sem front controller"; exit 1; }
[[ -f "$shared/.env" ]] || { echo "Configure $shared/.env com permissoes 600"; exit 1; }

mkdir -p "$shared"/storage/{cache,logs,uploads,exports,fiscal} "$shared/backups"
chmod 700 "$shared/backups"

# Remove somente pastas auxiliares da nova release, nunca dados compartilhados.
if [[ -e "$release/storage" && ! -L "$release/storage" ]]; then
  rm -rf -- "$release/storage"
fi
ln -sfn "$shared/storage" "$release/storage"
ln -sfn "$shared/.env" "$release/.env"

# Mapa proprio (deploy/scripts/mapa-instalar.sh): link fisico na pasta public da nova release, se ja existir.
if [[ -f "$shared/mapas/ceara.pmtiles" ]]; then
  mkdir -p "$release/public/mapas"
  chmod 755 "$release/public/mapas"
  ln -f "$shared/mapas/ceara.pmtiles" "$release/public/mapas/ceara.pmtiles"
fi

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
  if [[ "$desktop_agent_schema" == "true" ]]; then
    # A tag de entrega nao pode aplicar migrations de outras funcionalidades.
    status="$("$php_bin" "$release/database/migrate.php" --status)"
    pending="$(printf '%s\n' "$status" | sed -n 's/^PENDENTE //p')"
    expected="$(printf '%s\n' \
      20261119010000_create_desktop_agent_devices.php \
      20261119020000_create_desktop_agent_pesagens.php)"
    if [[ -n "$pending" && "$pending" != "$expected" ]]; then
      echo "Existem migrations pendentes fora do escopo do Desktop Agent. Deploy abortado antes de mudar current."
      exit 1
    fi
  fi
  cnf="$shared/mysql-backup.cnf"
  command -v mysqldump >/dev/null || { echo "mysqldump nao instalado"; exit 1; }
  command -v gzip >/dev/null || { echo "gzip nao instalado"; exit 1; }
  if [[ ! -f "$cnf" ]]; then
    # Caso a VPS ainda nao tenha conta de backup dedicada, usa a configuracao
    # privada da propria aplicacao. Nunca imprime usuario/senha no log nem no ps.
    cnf="$(mktemp "$shared/backups/.mysql-client.XXXXXXXX.cnf")"
    chmod 600 "$cnf"
    trap 'rm -f -- "$cnf"' EXIT
    "$php_bin" "$release/deploy/scripts/mysql-backup-config.php" > "$cnf"
    echo "Credenciais temporarias de backup geradas com acesso restrito."
  fi
  [[ "$(stat -c '%a' "$cnf")" == "600" ]] || { echo "Permissao 600 obrigatoria no arquivo de backup"; exit 1; }
  backup="$shared/backups/cardapim-$(date -u +%Y%m%dT%H%M%SZ)-$release_id.sql.gz"
  if ! mysqldump --defaults-extra-file="$cnf" --single-transaction --quick --no-tablespaces --set-charset --databases cardapim | gzip > "$backup"; then
    rm -f -- "$backup"
    echo "Backup MySQL falhou; migrations NAO foram executadas."
    exit 1
  fi
  if [[ ! -s "$backup" ]] || ! gzip -t "$backup"; then
    rm -f -- "$backup"
    echo "Backup MySQL vazio ou corrompido; migrations NAO foram executadas."
    exit 1
  fi
  chmod 600 "$backup"
  echo "Backup criado e validado: $backup"
  "$php_bin" "$release/database/migrate.php" --apply
  if [[ "$desktop_agent_schema" == "true" ]]; then
    "$php_bin" "$release/database/verify-desktop-balanca.php" --verify
  fi
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
  curl -fsS --max-time 20 "$site_url/assets/vendor/protomaps-leaflet/protomaps-leaflet.js" >/dev/null || return 1
  curl -fsS --max-time 20 "$site_url/assets/vendor/qrcode-generator/qrcode.js" >/dev/null || return 1
  # /status so retorna HTTP 200 quando o PHP acessa o MySQL.
  curl -fsS --max-time 20 -H 'Accept: application/json' "$site_url/status" >/dev/null || return 1
  if [[ "$desktop_agent_schema" == "true" ]]; then
    # Rota protegida deve existir; sem login, acesso deve ser negado.
    code="$(curl -sS --max-time 20 -o /dev/null -w '%{http_code}' "$site_url/api/v1/desktop/balancas" || true)"
    [[ "$code" == "403" ]] || return 1
  fi
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
# Ativa o cancelamento de Pix vencido sem depender de navegador. O cron e instalado
# na conta do site, sem sudo; se a VPS bloquear crontab, registrar pendencia operacional.
if ! bash "$root/current/deploy/scripts/install-expirar-pix-cron.sh" "$root"; then
  echo "ATENCAO: Cron Pix online nao confirmado. Configure a execucao a cada minuto no CloudPanel."
fi
# Fila de emissão de NFC-e: sem restaurante com módulo fiscal ativo, a rodada termina sem fazer nada.
if [[ -f "$root/current/deploy/scripts/install-fiscal-fila-cron.sh" ]] && ! bash "$root/current/deploy/scripts/install-fiscal-fila-cron.sh" "$root"; then
  echo "ATENCAO: Cron da fila fiscal nao confirmado. Configure a execucao a cada minuto no CloudPanel."
fi
# Vigia do WhatsApp: reinicia sessao que caiu e tenta de novo as boas-vindas (sem WAHA configurado, nao faz nada).
if [[ -f "$root/current/deploy/scripts/install-whatsapp-vigia-cron.sh" ]] && ! bash "$root/current/deploy/scripts/install-whatsapp-vigia-cron.sh" "$root"; then
  echo "ATENCAO: Cron do vigia do WhatsApp nao confirmado. Configure a execucao a cada minuto no CloudPanel."
fi
# Conciliação seletiva da plataforma, independente do cron de Pix dos restaurantes.
if ! bash "$root/current/deploy/scripts/install-conciliar-assinaturas-cron.sh" "$root"; then
  echo "ATENCAO: Cron de conciliacao nao confirmado. Configure a execucao a cada 15 minutos no CloudPanel."
fi
# Google e redes sociais (so informa, nao reverte): robots.txt, sitemap.xml e a imagem automatica do link.
for caminho in /robots.txt /sitemap.xml /midia/cartao; do
  echo "SEO $caminho: $(curl -sL -o /dev/null -w '%{http_code} %{content_type}' --max-time 20 "$site_url$caminho" || true)"
done
