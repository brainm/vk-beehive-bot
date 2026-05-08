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
# опционально, по умолчанию: ./logs/webhook.log
WEBHOOK_LOG_FILE=/absolute/path/to/webhook.log
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

- `bitrix start`
- `bitrix pause`
- `bitrix resume`
- `bitrix finish`
- `bitrix timeman [YYYY-MM-DD]`

Связь пользователя VK (`from_id`) с Bitrix user id задается через `PEER_BITRIX`:

```dotenv
PEER_BITRIX=143581329:12,1085903959:7
```

Формат: `from_id:bitrix_id`, несколько пар через запятую.
