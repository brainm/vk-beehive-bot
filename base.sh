#!/bin/bash

# Общие настройки для скриптов build.sh и deploy.sh

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

PROJECT_NAME=$(basename "$(pwd)" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9]//g')

get_project_title() {
  if [ -f composer.json ]; then
    local title
    title=$(php -r "echo json_decode(file_get_contents('composer.json'))->description ?? '';" 2>/dev/null || true)
    if [ -n "$title" ]; then
      echo "$title"
      return
    fi
  fi
  echo "VK Beehive Bot"
}

PROJECT_TITLE=$(get_project_title)

print_header() {
  local mode="$1"
  echo -e "${GREEN}${PROJECT_TITLE} - ${mode}${NC}"
  echo ""
}

load_dotenv() {
  if [ ! -f .env ]; then
    return 1
  fi
  set -a
  # shellcheck disable=SC1091
  source .env
  set +a
  return 0
}

file_mtime() {
  local file="$1"
  if [ ! -f "$file" ]; then
    echo "не найден"
    return
  fi
  if [[ "$OSTYPE" == "darwin"* ]]; then
    stat -f "%Sm" -t "%Y-%m-%d %H:%M:%S" "$file" 2>/dev/null || echo "не удалось получить"
  else
    stat -c "%y" "$file" 2>/dev/null | cut -d'.' -f1 || echo "не удалось получить"
  fi
}

remote_file_mtime() {
  local remote_path="$1"
  local result
  result=$(ssh -o ConnectTimeout=10 "$REMOTE_USERNAME@$REMOTE_SERVER" "stat -c '%y' '$remote_path' 2>/dev/null" 2>/dev/null | cut -d'.' -f1 | head -n1)
  if [ -z "$result" ]; then
    result=$(ssh -o ConnectTimeout=10 "$REMOTE_USERNAME@$REMOTE_SERVER" "stat -f '%Sm' -t '%Y-%m-%d %H:%M:%S' '$remote_path' 2>/dev/null" 2>/dev/null | head -n1)
  fi
  if [ -z "$result" ]; then
    result=$(ssh -o ConnectTimeout=10 "$REMOTE_USERNAME@$REMOTE_SERVER" "ls -l --time-style='+%Y-%m-%d %H:%M:%S' '$remote_path' 2>/dev/null | awk '{print \$6, \$7}'" 2>/dev/null | head -n1)
  fi
  if [ -z "$result" ]; then
    echo "не удалось получить"
  else
    echo "$result"
  fi
}
