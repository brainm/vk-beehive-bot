## VK callback bot (PHP 8.2 + Composer)

Простой webhook для VK-бота с командами:

- `/whoami` — возвращает данные текущего callback-события
- `/help` — список доступных команд

Поддерживается проверка callback `secret`, обработка `confirmation`, nonce (антидублирование) и прокси для исходящих запросов к VK API (по умолчанию выключен).

### 1) Установка

```bash
composer install
cp .env.example .env
```

Заполните `.env`:

```dotenv
VK_BOT_TOKEN=vk1.a.your_bot_token_here
VK_API_VERSION=5.199
VK_SECRET_KEY=your_callback_secret
VK_CONFIRMATION_TOKEN=your_confirmation_token

VK_PROXY_ENABLED=false
VK_PROXY_LIST=["socks5h://user:password@127.0.0.1:1080"]
VK_TIMEOUT=10

VK_NONCE_TTL=300

BITRIX24_URL=https://your-bitrix-host
BITRIX24_TOKEN=
PEER_BITRIX=143581329:12,1085903959:7

WEBHOOK_LOG_ENABLED=false
# по умолчанию: /var/log/vk-beehive-bot/webhook.log (создаётся install.sh)
WEBHOOK_LOG_FILE=/var/log/vk-beehive-bot/webhook.log
```

### 2) Локальный запуск

```bash
php -S 0.0.0.0:8080
```

Webhook endpoint: `http://localhost:8080/hook.php`

### 3) Callback URL для VK

В настройках Callback API в VK:

- `URL`: `https://your-domain/vk-beehive-bot/hook.php`
- `Secret key`: значение `VK_SECRET_KEY`
- При подтверждении callback VK вызовет `confirmation`, и сервис вернет `VK_CONFIRMATION_TOKEN`

### 4) Команды

- `/help` — список команд
- `/whoami` — JSON-контекст события (peer, from, event, message)

### 4.1) Прокси `message_read` → all-notifications

Если в сообществе включено событие **«Прочтение сообщений»**, `hook.php` при `message_read` делает POST на all-notifications:

```dotenv
ALL_NOTIFICATIONS_ENABLED=true
ALL_NOTIFICATIONS_TOKEN=тот_же_что_VK_CALLBACK_TOKEN_в_all-notifications
ALL_NOTIFICATIONS_VK_CALLBACK_URL=https://panel.1beehive.ru/notifications/vk-callback.php
```

Заголовок: `X-Authorization: <ALL_NOTIFICATIONS_TOKEN>`. Тело — исходный JSON события VK.

### 5) Nonce (антидублирование)

Для защиты от повторной обработки события используется nonce:

- в первую очередь `event_id` из callback payload
- если `event_id` отсутствует — вычисляется fallback nonce
- nonce хранятся в `storage/nonces`
- время жизни задается `VK_NONCE_TTL` (секунды)

### 6) Прокси для VK API

Прокси выключен по умолчанию.

Чтобы включить:

```dotenv
VK_PROXY_ENABLED=true
VK_PROXY_LIST=["socks5h://user:password@127.0.0.1:1080"]
VK_TIMEOUT=15
```

Проверка доступности VK API через прокси:

`http://localhost:8080/proxy.php`

### 7) Непубличные функции

Ниже перечислены непубличные функции. Они доступны не всем пользователям и показываются в `/help` только при наличии доступа.

#### 7.1) Команды bitrix

- `bitrix start [H или HH:MM]`
- `bitrix pause [H или HH:MM]`
- `bitrix resume [H или HH:MM]`
- `bitrix finish [H или HH:MM]`
- `bitrix timeman [YYYY-MM-DD]`

Время необязательно: `9`, `9:00` и `09:00` означают 09:00 сегодняшнего дня по часовому поясу сервера Bitrix. Для `finish` явное время относится к дате начала последнего незавершённого периода: `bitrix finish 19` закроет период, начатый 2026-11-11, в 2026-11-11 19:00. Без времени `finish` закрывает этот период текущим моментом. Без аргумента используется текущее время. После успешной команды возвращается `bitrix timeman` за дату изменённого периода.

`start` проверяет только последний отчётный период по дате начала: если он открыт или на паузе, новый период не начинается, а в ответе указаны начало и длительность последнего периода. Если последний период завершён, более старые незавершённые записи не блокируют начало нового дня. Исключение: `start` с явным временем (например, `bitrix start 9`) исправляет начало последнего открытого сегодняшнего периода, сохраняя его статус и перерывы. Для незавершённого периода прошлой даты блокировка сохраняется.

Для этих возможностей нужно обновить также `extensions/bitrix/api.php` в проекте `admins`.

Связь пользователя VK (`from_id`) с Bitrix user id задается через `PEER_BITRIX`:

```dotenv
PEER_BITRIX=143581329:12,1085903959:7
```

Формат: `from_id:bitrix_id`, несколько пар через запятую.

#### 7.2) Команды telethon (my-telethon)

Управление профилем Telegram через HTTP API [my-telethon](https://github.com/your-org/my-telethon) (`bot_api.php`).

В `.env` vk-beehive-bot:

```dotenv
MY_TELETHON_URL=https://site.com/my-telethon
MY_TELETHON_TOKEN=тот_же_TOKEN_что_в_my-telethon_.env
PEER_MY_TELETHON=1085903959:1
```

`PEER_MY_TELETHON` — `vk_from_id:telethon_profile_id` (id строки в таблице `telethons`).

Команды (только для привязанных `from_id`):

- `telethon` — справка
- `telethon status` — статус авторизации Telegram
- `telethon relays` — контакты с включённой пересылкой; `telethon relays on|off` — глобальный переключатель профиля
- `telethon get 212 5` — последние сообщения контакта (5 по умолчанию)
- `telethon contacts имя` — поиск `contacts.id` (от 3 символов, до 20)
- `telethon send 212 текст сообщения` — ответ контакту `#212` в Telegram
- `telethon 212 relay on` / `off` / `true` / `false` — пересылка входящих от этого контакта в VK

`212` — `contacts.id` из my-telethon (число в префиксе пересланных сообщений `[212] Имя: …`).
