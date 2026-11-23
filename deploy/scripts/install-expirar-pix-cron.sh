#!/usr/bin/env bash
# Registra ou atualiza uma unica entrada no crontab do usuario do site, sem sudo.
set -euo pipefail
root="${1:?Informe a pasta raiz de releases}"
[[ "$root" =~ ^/home/[A-Za-z0-9._-]+/apps/cardapim$ ]] || { echo "Diretorio inseguro"; exit 1; }
[[ "$EUID" -ne 0 ]] || { echo "Execute com o usuario nao-root do site"; exit 1; }
command -v crontab >/dev/null || { echo "crontab indisponivel"; exit 1; }
php_bin="$(command -v php8.4 || true)"
[[ -n "$php_bin" && -f "$root/current/bin/expirar-pix.php" ]] || { echo "PHP 8.4 ou rotina ausente"; exit 1; }
mkdir -p "$root/shared/storage/logs"

marker="# cardapim-expirar-pix"
linha="* * * * * $php_bin $root/current/bin/expirar-pix.php >> $root/shared/storage/logs/expirar-pix.log 2>&1 $marker"
atual="$(crontab -l 2>/dev/null || true)"
# Remove somente a entrada identificada; preserva todas as outras tarefas do site.
sem_antiga="$(printf '%s\n' "$atual" | grep -vF "$marker" || true)"
{ printf '%s\n' "$sem_antiga"; printf '%s\n' "$linha"; } | sed '/^[[:space:]]*$/d' | crontab -
crontab -l | grep -Fq "$linha" || { echo "Nao foi possivel confirmar a tarefa agendada"; exit 1; }
echo "Cron Pix online ativo (a cada minuto)."
