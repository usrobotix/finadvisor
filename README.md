# finadvisor

## Структура проекта

| Путь | Описание |
|---|---|
| `www/index.php` | Главная страница (конвертирована из `index.html`) |
| `www/send.php` | Обработчик форм (принимает только POST) |
| `www/config.php` | **Временный** файл с секретным ключом SmartCaptcha |
| `www/css/site.css` | Кастомные стили (модальное окно, honeypot) |
| `www/js/site.js` | Интеграция Yandex SmartCaptcha + UI обратной связи |

---

## Настройка Yandex SmartCaptcha

### 1. Sitekey (клиентский ключ)

Откройте `www/js/site.js` и замените плейсхолдер в строке:

```js
var SMARTCAPTCHA_SITEKEY = 'ysc1_REPLACE_WITH_YOUR_SMARTCAPTCHA_SITEKEY';
```

на реальный sitekey из [консоли Yandex Cloud](https://console.yandex.cloud/).

### 2. Secret key (серверный ключ)

**Рекомендуемый способ (production):** задайте переменную окружения на сервере:

```bash
export SMARTCAPTCHA_SECRET="ваш_секретный_ключ"
```

Или в конфигурации PHP-FPM / Apache / nginx. Код `send.php` читает её через `getenv('SMARTCAPTCHA_SECRET')`.

**Временный способ (workaround):** файл `www/config.php` содержит константу `SMARTCAPTCHA_SECRET_KEY`. Замените плейсхолдер на реальный ключ **только на сервере** (не коммитьте реальный ключ в репозиторий).

### 3. Удаление config.php (после настройки env var)

После того как `SMARTCAPTCHA_SECRET` задан как переменная окружения:

1. Убедитесь, что `send.php` возвращает корректный ответ (нет ошибки «Сервис временно недоступен»).
2. Удалите `www/config.php` с сервера и из репозитория.

---

## Защита от спама

`send.php` включает:

- **Honeypot-поле** (`name="website"`) — невидимо для пользователей, боты его заполняют и блокируются.
- **Rate-limiting по IP** — не более 5 запросов за 60 секунд; состояние хранится в `/tmp/sc_rate_*`.
- **Yandex SmartCaptcha** — проверка токена через серверный API.

---

## Обновление `index.html` → `index.php`

Файл `www/index.php` создан из `www/index.html` со следующими изменениями:

- Подключён скрипт SmartCaptcha: `https://smartcaptcha.yandexcloud.net/captcha.js`
- Подключены `/css/site.css` и `/js/site.js`
- В каждую форму добавлены: honeypot-поле, скрытое поле `captcha_token`, контейнер для виджета SmartCaptcha
- Атрибуты `data-success-url` убраны из форм (используется модальное окно вместо редиректа)
- В конце `<body>` добавлено модальное окно для отображения результата отправки

Оригинальный `index.html` сохранён без изменений.

