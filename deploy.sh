#!/bin/bash

# Сборка и деплой dist/ на удалённый сервер через rsync

set -e

source "$(dirname "$0")/base.sh"

FORCE_DEPLOY=false
SKIP_BUILD=false

for arg in "$@"; do
  case "$arg" in
    -f) FORCE_DEPLOY=true ;;
    --no-build) SKIP_BUILD=true ;;
    -h|--help)
      echo "Использование: ./deploy.sh [-f] [--no-build]"
      echo "  -f          деплой без подтверждения"
      echo "  --no-build  не запускать сборку, использовать существующий dist/"
      exit 0
      ;;
  esac
done

print_header "Деплой на удалённый сервер"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR" || exit 1

if ! load_dotenv; then
  echo -e "${RED}ОШИБКА: Файл .env не найден!${NC}"
  echo -e "${YELLOW}Создайте .env с переменными REMOTE_SERVER, REMOTE_USERNAME, REMOTE_DIR${NC}"
  exit 1
fi

MISSING_VARS=()
[ -z "$REMOTE_SERVER" ] && MISSING_VARS+=("REMOTE_SERVER")
[ -z "$REMOTE_USERNAME" ] && MISSING_VARS+=("REMOTE_USERNAME")
[ -z "$REMOTE_DIR" ] && MISSING_VARS+=("REMOTE_DIR")

if [ ${#MISSING_VARS[@]} -gt 0 ]; then
  echo -e "${RED}ОШИБКА: В .env отсутствуют обязательные переменные для деплоя:${NC}"
  for var in "${MISSING_VARS[@]}"; do
    echo -e "${RED}  - $var${NC}"
  done
  echo ""
  echo -e "${YELLOW}Пример:${NC}"
  echo -e "${YELLOW}  REMOTE_SERVER=example.com${NC}"
  echo -e "${YELLOW}  REMOTE_USERNAME=deploy${NC}"
  echo -e "${YELLOW}  REMOTE_DIR=/var/www/vk-beehive-bot/${NC}"
  exit 1
fi

REMOTE_DIR="${REMOTE_DIR%/}/"

if [ "$SKIP_BUILD" = false ]; then
  echo -e "${YELLOW}Запуск сборки...${NC}"
  echo ""
  "$SCRIPT_DIR/build.sh"
  echo ""
else
  echo -e "${YELLOW}Сборка пропущена (--no-build)${NC}"
  echo ""
fi

if [ ! -d dist ]; then
  echo -e "${RED}ОШИБКА: Директория dist не найдена!${NC}"
  echo -e "${YELLOW}Выполните ./build.sh или запустите ./deploy.sh без --no-build${NC}"
  exit 1
fi

echo -e "${YELLOW}Параметры деплоя:${NC}"
echo -e "  Сервер: ${GREEN}$REMOTE_USERNAME@$REMOTE_SERVER${NC}"
echo -e "  Директория: ${GREEN}$REMOTE_DIR${NC}"
echo -e "  Локальная директория: ${GREEN}$(pwd)/dist${NC}"
echo ""

LOCAL_SIZE=$(du -sh dist | cut -f1)
echo -e "${YELLOW}Размер каталога:${NC}"
echo -e "  Локальный: ${GREEN}$LOCAL_SIZE${NC}"

REMOTE_SIZE="не найден"
if ssh -o ConnectTimeout=10 -o BatchMode=yes "$REMOTE_USERNAME@$REMOTE_SERVER" "test -d '$REMOTE_DIR'" 2>/dev/null; then
  REMOTE_SIZE=$(ssh -o ConnectTimeout=10 "$REMOTE_USERNAME@$REMOTE_SERVER" "du -sh '$REMOTE_DIR' 2>/dev/null" 2>/dev/null | cut -f1 | head -n1)
  [ -z "$REMOTE_SIZE" ] && REMOTE_SIZE="не удалось получить"
fi
echo -e "  Удалённый: ${GREEN}$REMOTE_SIZE${NC}"
echo ""

LOCAL_HOOK_DATE=$(file_mtime "dist/hook.php")
REMOTE_HOOK_PATH="${REMOTE_DIR}hook.php"
REMOTE_HOOK_DATE="файл не существует"
if ssh -o ConnectTimeout=10 -o BatchMode=yes "$REMOTE_USERNAME@$REMOTE_SERVER" "test -f '$REMOTE_HOOK_PATH'" 2>/dev/null; then
  REMOTE_HOOK_DATE=$(remote_file_mtime "$REMOTE_HOOK_PATH")
fi

echo -e "${YELLOW}Дата hook.php:${NC}"
echo -e "  Удалённый: ${GREEN}$REMOTE_HOOK_DATE${NC}"
echo -e "  Локальный: ${GREEN}$LOCAL_HOOK_DATE${NC}"

if [ -f dist/.env ]; then
  LOCAL_ENV_DATE=$(file_mtime "dist/.env")
  REMOTE_ENV_PATH="${REMOTE_DIR}.env"
  REMOTE_ENV_DATE="файл не существует"
  if ssh -o ConnectTimeout=10 -o BatchMode=yes "$REMOTE_USERNAME@$REMOTE_SERVER" "test -f '$REMOTE_ENV_PATH'" 2>/dev/null; then
    REMOTE_ENV_DATE=$(remote_file_mtime "$REMOTE_ENV_PATH")
  fi
  echo ""
  echo -e "${YELLOW}Дата .env:${NC}"
  echo -e "  Удалённый: ${GREEN}$REMOTE_ENV_DATE${NC}"
  echo -e "  Локальный: ${GREEN}$LOCAL_ENV_DATE${NC}"
fi
echo ""

if [ "$FORCE_DEPLOY" = false ]; then
  read -p "Продолжить деплой? (y/N): " -n 1 -r
  echo ""
  if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo -e "${YELLOW}Деплой отменён${NC}"
    exit 0
  fi
else
  echo -e "${YELLOW}Деплой выполняется автоматически (флаг -f)${NC}"
  echo ""
fi

echo -e "${YELLOW}Синхронизация dist/ → сервер...${NC}"
rsync -rtvuz --partial --progress --stats --timeout=30 \
  --exclude='.env' \
  --exclude='.env.*' \
  --exclude='storage/' \
  dist/ "$REMOTE_USERNAME@$REMOTE_SERVER:$REMOTE_DIR"

if [ -f dist/.env ]; then
  echo ""
  echo -e "${YELLOW}Синхронизация dist/.env...${NC}"
  rsync -tvuz --progress --timeout=30 \
    dist/.env "$REMOTE_USERNAME@$REMOTE_SERVER:$REMOTE_DIR.env"
else
  echo ""
  echo -e "${YELLOW}⚠ dist/.env не найден — конфиг на сервере не изменён${NC}"
fi

echo ""
echo -e "${GREEN}✓ Деплой успешно завершён!${NC}"
echo -e "${YELLOW}Примечание: storage/ на сервере не синхронизируется; логи — в /var/log/vk-beehive-bot/${NC}"
echo -e "${YELLOW}Первичная настройка на сервере: sudo ./install.sh${NC}"
