#!/bin/bash

set -e

source "$(dirname "$0")/base.sh"

print_header "Сборка"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR" || exit 1

COMPOSER_IMAGE="${COMPOSER_IMAGE:-composer:2}"

if ! command -v docker &> /dev/null; then
  echo -e "${RED}ОШИБКА: docker не установлен!${NC}"
  echo -e "${YELLOW}Установите Docker Desktop или Docker Engine${NC}"
  exit 1
fi

if ! docker info &> /dev/null; then
  echo -e "${RED}ОШИБКА: Docker daemon не запущен!${NC}"
  exit 1
fi

echo -e "${YELLOW}Установка зависимостей через Docker ($COMPOSER_IMAGE)...${NC}"
docker run --rm \
  -u "$(id -u):$(id -g)" \
  -v "$SCRIPT_DIR:/app" \
  -w /app \
  "$COMPOSER_IMAGE" \
  install --no-dev --optimize-autoloader

echo -e "${YELLOW}Подготовка dist/...${NC}"
rm -rf dist
mkdir -p dist

cp index.php hook.php proxy.php dist/
cp -r modules vendor dist/
cp .htaccess dist/
cp install.sh base.sh dist/
chmod +x dist/install.sh
mkdir -p dist/storage
cp storage/.htaccess dist/storage/

if [ -f .env ]; then
  echo -e "${YELLOW}Копирование .env → dist/.env...${NC}"
  cp -f .env dist/.env
else
  echo -e "${YELLOW}⚠ .env не найден — dist/.env не обновлён${NC}"
fi

if [ ! -f dist/hook.php ]; then
  echo -e "${RED}ОШИБКА: dist/hook.php не найден после сборки${NC}"
  exit 1
fi

DIST_SIZE=$(du -sh dist | cut -f1)
echo ""
echo -e "${GREEN}✓ Сборка завершена${NC}"
echo -e "  Каталог: ${GREEN}$(pwd)/dist${NC}"
echo -e "  Размер: ${GREEN}$DIST_SIZE${NC}"
