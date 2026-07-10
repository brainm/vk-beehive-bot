#!/bin/bash

# Первичная настройка vk-beehive-bot на сервере (запускать один раз под root).
#
# Что делает скрипт:
#   1. Проверяет права root
#   2. Создаёт каталог системных логов /var/log/vk-beehive-bot/
#   3. Выставляет владельца/группу/права по образцу index.php приложения
#   4. Устанавливает конфиг logrotate
#   5. Разворачивает .htaccess для защиты storage/ и служебных файлов
#
# Использование:
#   sudo ./install.sh                          # APP_DIR = каталог скрипта
#   sudo ./install.sh /path/to/vk-beehive-bot  # явный путь к деплою
#
# Переменные окружения (опционально):
#   VK_LOG_DIR=/var/log/vk-beehive-bot         # каталог логов
#   VK_LOG_FILE=webhook.log                    # имя файла лога

set -euo pipefail

INSTALLER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$INSTALLER_DIR/base.sh"

APP_DIR="${1:-$INSTALLER_DIR}"
LOG_DIR="${VK_LOG_DIR:-/var/log/vk-beehive-bot}"
LOG_BASENAME="${VK_LOG_FILE:-webhook.log}"
LOG_FILE="${LOG_DIR%/}/${LOG_BASENAME}"
LOGROTATE_CONF="/etc/logrotate.d/vk-beehive-bot"

print_header "Установка на сервере"

# ---------------------------------------------------------------------------
# 1. Проверка root
# ---------------------------------------------------------------------------
echo -e "${YELLOW}[1/6] Проверка прав root...${NC}"
if [ "$(id -u)" -ne 0 ]; then
  echo -e "${RED}ОШИБКА: install.sh нужно запускать от root (sudo).${NC}"
  echo -e "${YELLOW}Пример: sudo $0 ${APP_DIR}${NC}"
  exit 1
fi
echo -e "${GREEN}  ✓ Запущено от root (uid=0)${NC}"
echo ""

# ---------------------------------------------------------------------------
# 2. Проверка каталога приложения и эталонного index.php
# ---------------------------------------------------------------------------
echo -e "${YELLOW}[2/6] Проверка каталога приложения...${NC}"
APP_DIR="$(cd "$APP_DIR" && pwd)"
REF_FILE="${APP_DIR}/index.php"

if [ ! -f "$REF_FILE" ]; then
  echo -e "${RED}ОШИБКА: Не найден эталонный файл ${REF_FILE}${NC}"
  echo -e "${YELLOW}Укажите путь к деплою: sudo $0 /path/to/vk-beehive-bot${NC}"
  exit 1
fi

echo -e "  Каталог приложения: ${GREEN}${APP_DIR}${NC}"
echo -e "  Эталон прав:        ${GREEN}${REF_FILE}${NC}"
echo ""

# Читаем владельца, группу и режим index.php (эталон для файла лога).
if stat -c '%U' "$REF_FILE" &>/dev/null; then
  REF_OWNER=$(stat -c '%U' "$REF_FILE")
  REF_GROUP=$(stat -c '%G' "$REF_FILE")
  REF_MODE=$(stat -c '%a' "$REF_FILE")
else
  REF_OWNER=$(stat -f '%Su' "$REF_FILE")
  REF_GROUP=$(stat -f '%Sg' "$REF_FILE")
  REF_MODE=$(stat -f '%OLp' "$REF_FILE")
fi

# Для каталогов нужен бит исполнения; если index.php 644 → каталог 775.
REF_DIR_MODE="$REF_MODE"
if [[ "$REF_DIR_MODE" =~ ^[0-9]{3}$ ]] && [ "${REF_DIR_MODE: -1}" -lt 5 ]; then
  REF_DIR_MODE="${REF_DIR_MODE:0:2}5"
fi

echo -e "${YELLOW}  Права эталона index.php:${NC}"
echo -e "    владелец: ${GREEN}${REF_OWNER}${NC}"
echo -e "    группа:   ${GREEN}${REF_GROUP}${NC}"
echo -e "    файл:     ${GREEN}${REF_MODE}${NC}"
echo -e "    каталог:  ${GREEN}${REF_DIR_MODE}${NC} (с битом x для доступа PHP-FPM)"
echo ""

# ---------------------------------------------------------------------------
# 3. Каталог системных логов
# ---------------------------------------------------------------------------
echo -e "${YELLOW}[3/6] Создание каталога логов...${NC}"
echo -e "  Путь: ${GREEN}${LOG_DIR}${NC}"

mkdir -p "$LOG_DIR"
chown "${REF_OWNER}:${REF_GROUP}" "$LOG_DIR"
chmod "$REF_DIR_MODE" "$LOG_DIR"

if [ ! -f "$LOG_FILE" ]; then
  echo -e "  Создание файла лога: ${GREEN}${LOG_FILE}${NC}"
  install -o "$REF_OWNER" -g "$REF_GROUP" -m "$REF_MODE" /dev/null "$LOG_FILE"
else
  echo -e "  Файл лога уже существует, обновляем права: ${GREEN}${LOG_FILE}${NC}"
  chown "${REF_OWNER}:${REF_GROUP}" "$LOG_FILE"
  chmod "$REF_MODE" "$LOG_FILE"
fi

echo -e "${GREEN}  ✓ Каталог и файл лога готовы${NC}"
echo ""

# ---------------------------------------------------------------------------
# 4. logrotate
# ---------------------------------------------------------------------------
echo -e "${YELLOW}[4/6] Настройка logrotate...${NC}"

if ! command -v logrotate &>/dev/null; then
  echo -e "${RED}ОШИБКА: logrotate не установлен.${NC}"
  echo -e "${YELLOW}Установите пакет logrotate и запустите install.sh снова.${NC}"
  exit 1
fi

cat > "$LOGROTATE_CONF" <<EOF
# vk-beehive-bot — ротация webhook-логов (создано install.sh)
${LOG_DIR}/*.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create ${REF_MODE} ${REF_OWNER} ${REF_GROUP}
}
EOF

chmod 644 "$LOGROTATE_CONF"
echo -e "  Конфиг: ${GREEN}${LOGROTATE_CONF}${NC}"

if logrotate -d "$LOGROTATE_CONF" &>/dev/null; then
  echo -e "${GREEN}  ✓ Конфиг logrotate прошёл проверку (dry-run)${NC}"
else
  echo -e "${YELLOW}  ⚠ dry-run logrotate вернул предупреждение — проверьте вручную:${NC}"
  echo -e "${YELLOW}    logrotate -d ${LOGROTATE_CONF}${NC}"
fi
echo ""

# ---------------------------------------------------------------------------
# 5. .htaccess — защита storage/ и служебных файлов
# ---------------------------------------------------------------------------
echo -e "${YELLOW}[5/6] Развёртывание .htaccess...${NC}"

deploy_htaccess() {
  local src="$1"
  local dest="$2"
  if [ ! -f "$src" ]; then
    echo -e "${RED}  ОШИБКА: Не найден шаблон ${src}${NC}"
    exit 1
  fi
  cp -f "$src" "$dest"
  chown "${REF_OWNER}:${REF_GROUP}" "$dest"
  chmod 644 "$dest"
  echo -e "  ${GREEN}✓${NC} ${dest}"
}

mkdir -p "${APP_DIR}/storage"
chown "${REF_OWNER}:${REF_GROUP}" "${APP_DIR}/storage"
chmod "$REF_DIR_MODE" "${APP_DIR}/storage"

deploy_htaccess "${INSTALLER_DIR}/.htaccess" "${APP_DIR}/.htaccess"
deploy_htaccess "${INSTALLER_DIR}/storage/.htaccess" "${APP_DIR}/storage/.htaccess"

echo -e "${GREEN}  ✓ Веб-доступ к storage/ закрыт (Apache .htaccess)${NC}"
echo -e "${GREEN}  ✓ Логи пишутся вне web-root: ${LOG_FILE}${NC}"
echo -e "${YELLOW}  Примечание: при чистом nginx без Apache добавьте deny в конфиг vhost.${NC}"
echo ""

# ---------------------------------------------------------------------------
# 6. Итог
# ---------------------------------------------------------------------------
echo -e "${YELLOW}[6/6] Готово${NC}"
echo ""
echo -e "${GREEN}✓ Установка завершена${NC}"
echo ""
echo -e "${YELLOW}Добавьте в .env на сервере:${NC}"
echo -e "  WEBHOOK_LOG_ENABLED=true"
echo -e "  WEBHOOK_LOG_FILE=${LOG_FILE}"
echo ""
echo -e "${YELLOW}Полезные команды:${NC}"
echo -e "  tail -f ${LOG_FILE} | jq ."
echo -e "  logrotate -d ${LOGROTATE_CONF}    # проверка ротации"
echo -e "  logrotate -f ${LOGROTATE_CONF}    # принудительная ротация"
