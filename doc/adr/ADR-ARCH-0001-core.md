# ADR-ARCH-0001: Ядро News Feed Factory

**Статус:** Proposed
**Создано:** 2026-10-02
**Обновлено:** 2026-10-13

## Контекст

Продукт определён в ADR-PRODUCT-0001: три рендера, поэтапная
реализация (Яндекс → Google → Дзен), кастомная таблица для метаданных,
единый сборщик и N рендереров.

Нужно зафиксировать архитектурные решения ядра до начала кодирования: схему
таблицы, поток данных, кэширование, точки расширения. Иначе каждый рендерер
потянет за собой свою логику выборки и кэша, и ядро расползётся.

Этот ADR покрывает **ядро**. Нормализация контента — `ADR-ARCH-0002`.
Специфика форматов — отдельные ADR: `ADR-ARCH-0003` (Яндекс),
`ADR-ARCH-0004` (Google, TBD), `ADR-ARCH-0005` (Дзен, TBD).

## Решение

### Структура файлов

```
news-feed-factory/
├── news-feed-factory.php       # bootstrap: константы, autoload, хуки
├── uninstall.php               # удаление таблицы и опций при удалении
├── composer.json               # PSR-4 автозагрузка, dev-зависимости
├── phpcs.xml.dist              # конфиг PHP_CodeSniffer (шаблон)
├── phpstan.neon.dist           # конфиг PHPStan (шаблон)
├── phpunit.xml.dist            # конфиг PHPUnit (шаблон)
├── readme.txt                  # WordPress.org readme
├── README.md                   # расширенная документация
├── LICENSE                     # MIT
├── languages/                  # .pot и .po/.mo
├── src/
│   ├── Plugin.php              # главный класс, регистрация хуков
│   ├── Install/
│   │   ├── Schema.php          # dbDelta, схема таблицы
│   │   └── Cron.php            # регистрация и обработчик очистки
│   ├── Settings/
│   │   └── Page.php            # Settings API
│   ├── Data/
│   │   ├── MetaRepository.php  # работа с nwff_feed_meta
│   │   └── Collector.php       # единый сборщик
│   ├── Cache/
│   │   └── TransientCache.php  # обёртка над транзиентами
│   ├── Normalize/
│   │   ├── ContentNormalizer.php   # фасад слоя нормализации
│   │   ├── HtmlSanitizer.php       # sanitize + to_plain
│   │   ├── Truncator.php           # ресурсный и форматный обрез
│   │   └── ImageExtractor.php      # featured image
│   ├── Render/
│   │   ├── RendererInterface.php
│   │   ├── YandexRenderer.php      # на этапе 1
│   │   └── Yandex/
│   │       └── GenreResolver.php   # жанр Яндекса
│   ├── Feed/
│   │   └── Endpoint.php        # регистрация /feed/{slug}
│   └── Admin/
│       └── PostFlags.php       # метабокс на записи
├── tests/
│   ├── bootstrap-unit.php         # только Composer autoload
│   ├── bootstrap-integration.php  # WP test suite + plugin
│   ├── unit/
│   ├── integration/
│   └── phpstan-stubs/             # WP_UnitTestCase и фабрики
└── doc/
    └── adr/
```

Пространство имён — `NWFF\`, PSR-4 из `src/`. Префикс кода — `nwff_`.
Конфиги линтеров и тестов — в формате `.dist`: шаблон коммитится, локальные
переопределения (`phpcs.xml`, `phpstan.neon`, `phpunit.xml`) игнорируются git'ом.

Два bootstrap-файла — по ADR-PROC-0004. Unit-прогон идёт без WP, integration
поднимает WP test suite. `phpunit.xml.dist` дефолтится на integration как
безопасный вариант для WP-плагина; `composer test:unit` переопределяет
bootstrap через CLI.

### Кастомная таблица `{prefix}nwff_feed_meta`

Схема:

| Поле | Тип | Назначение |
| --- | --- | --- |
| `post_id` | BIGINT UNSIGNED NOT NULL | PK, уникальный |
| `exclude_from_feeds` | TINYINT(1) NOT NULL DEFAULT 0 | 1 — запись исключена из лент; 0 — отдаётся |
| `updated_at` | DATETIME NOT NULL | Для очистки |

Первичный ключ — `post_id`. Индекс по `updated_at` для cron-очистки.

**Семантика `exclude_from_feeds`.** Колонка хранит инвертированный флаг:
значение `0` (default) — отдавать, `1` — исключить. UI-чекбокс в метабоксе
подписан положительно («Отдавать в ленты», default on) и инвертирует
значение при сохранении. Выбор имени колонки — под будущие сценарии, где
исключение встречается редко; при инвертировании «по умолчанию отдаём» —
default `0`, и SQL-условие выборки в `Collector` остаётся читаемым
(`WHERE exclude_from_feeds = 0`).

**Точки расширения:** рендерер может добавить свои поля через отдельный
`ALTER TABLE` в своём ADR. Ядро не знает о специфичных полях.

Создание — через `dbDelta()` на хуке активации. Обновление схемы — через
сравнение версии в опции `nwff_db_version`.

### Поток данных

```
1. Запрос к /{feed_slug}/{renderer_slug}.xml
   (query var nwff_feed_slug, значение = renderer slug)
2. Проверка транзиента по ключу
   (nwff_feed_ + md5 от нормализованных args)
3. Если промах — Collector::collect( $args )
   3.1. WP_Query (таксономии, глубина, флаги) → ID записей
   3.2. MetaRepository::get_by_post_ids( $ids ) → метаданные
        одним запросом
   3.3. ImageExtractor::extract( $post_id, $config ) → image_*
   3.4. wp_get_post_terms( $post_id ) → terms
        (если include_terms = true)
   3.5. HtmlSanitizer::sanitize( $post_content, $allowed_html )
        для content — на границе с WP
   3.6. Слияние: posts + meta + image + terms → массив $raw
4. ContentNormalizer::normalize( $raw, $config ) для каждого
  элемента → массив нормализованных items (см. ADR-ARCH-0002:
  форма $raw и нормализованного элемента, включая author)
5. Renderer::render( $items, $args ) → XML
6. Сохранение в транзиент
7. Отдача
```

`Collector` — единственный класс, который знает, **как получить данные**.
`ContentNormalizer` — единственный, кто знает, **как привести их к общему
виду** (ADR-ARCH-0002). `Renderer` — единственный, кто знает,
**как их отформатировать**.

### Единый сборщик

`Collector::collect( array $args ): array`

Аргументы:

| Ключ | Тип | Назначение |
| --- | --- | --- |
| `depth_hours` | int | Глубина по `date_query` |
| `limit` | int | Верхняя граница выборки; `0` — без лимита |
| `taxonomy_include` | array | `tax_query` с `operator = IN` |
| `taxonomy_exclude` | array | `tax_query` с `operator = NOT IN` |
| `normalization_config` | array | Из `RendererInterface::get_normalization_config()`; используется для `allowed_html`, `include_terms`, `image_min_*` |

`taxonomy_include` / `taxonomy_exclude` — карта `taxonomy => term_ids`,
например `[ 'category' => [ 12, 15 ], 'post_tag' => [ 100 ] ]`.
`Collector` сортирует карту по ключу и ID перед запросом — чтобы
одинаковая семантика давала одинаковый cache-ключ.

`normalization_config` — единый блок. `Collector` извлекает из него
`allowed_html` (для `HtmlSanitizer::sanitize`), `include_terms` (тянуть
ли terms), `image_min_width` / `image_min_height` (для
`ImageExtractor`). Значения по умолчанию берёт `ContentNormalizer`,
не `Collector`.

Шаги:

1. Сортировка аргументов таксономий (`taxonomy_include`, `taxonomy_exclude`)
   перед передачей в `WP_Query`. Иначе порядок создаёт разные cache-ключи
   при идентичной семантике.
2. `WP_Query` с параметрами: `post_type=post`, `post_status=publish`,
   `date_query` (по `depth_hours`), `tax_query` (если задано),
   `fields=ids`, `no_found_rows=true`, `update_post_meta_cache=false`,
   `update_post_term_cache=false`.
3. Если ID пусто — возврат пустого массива.
4. `MetaRepository::get_by_post_ids( $ids )` — один SQL-запрос через
   `$wpdb->prepare` с `IN (...)`. При необходимости — chunking по 500 ID.
5. Для каждой записи: `ImageExtractor::extract()`; если
   `include_terms = true` — `wp_get_post_terms()` для нужных таксономий.
6. `HtmlSanitizer::sanitize( $post_content, $allowed_html )` — контент
   сразу sanitized, чтобы `ContentNormalizer` оставался без WP-зависимостей.
7. Слияние и возврат массива `$raw`.

**Обоснование:** один запрос к кастомной таблице вместо N+1; отключение
`update_post_meta_cache` и `update_post_term_cache` — не тянем лишнее.

### Кэширование

`TransientCache` — обёртка над `get_transient` / `set_transient`.

Ключ: `nwff_feed_` + `md5( wp_json_encode( $normalized_args ) )`.
Нормализация: сортировка массивов таксономий, приведение типов.

TTL — из настроек (1–5 минут, по умолчанию 5).

Инвалидация — на хуках `save_post`, `deleted_post`, `set_object_terms`:
удаление всех транзиентов с префиксом `nwff_feed_` через
`TransientCache::delete_by_prefix()` (см. реализацию — прямой SQL по
`wp_options`).

**Обоснование:** сброс по префиксу дешевле, чем вычисление затронутых ключей,
а частота изменений на новостном сайте — выше, чем частота запросов лент.

### Интерфейс рендерера

```php
interface RendererInterface {
    public function get_slug(): string;
    public function get_content_type(): string;
    public function get_normalization_config(): array;  // см. ADR-ARCH-0002
    public function render( array $items, array $args ): string;
}
```

Каждый рендерер — класс, реализующий интерфейс. Регистрация — через массив
в `Plugin::renderers()`. На этапе 1 — только `YandexRenderer`.

Специфика рендереров — в отдельных ADR.

### Настройки

Страница — `Настройки → News Feed Factory`, один экран, Settings API.
Опция-контейнер: `nwff_settings` (один массив, а не набор опций — меньше
запросов).

**Общие поля (ядро):**

| Поле | Тип | Назначение |
| --- | --- | --- |
| `depth_hours` | select 1–12, default 4 | Глубина хранения |
| `cache_ttl` | select 1–5, default 5 | TTL кэша |
| `feed_slug` | text | Базовый slug для эндпоинтов |

**Точки расширения:** рендерер добавляет свои поля (например, «Включить
ленту Яндекса», ID тегов жанра) через фильтр `nwff_settings_fields`.
Ядро знает только об общих настройках.

Сохранение — через `register_setting` с колбэком санитизации.

### Эндпоинты

Механизм регистрации — в ядре: `add_rewrite_rule` плюс хук
`template_redirect`. Конкретные URL регистрируют рендереры через фильтр
`nwff_feed_endpoints`. Примеры того, что регистрирует рендерер:
`/feed/yandex.xml`, `/feed/google.xml`, `/feed/dzen.xml`.

Отдача — на хуке `template_redirect` (не через `WP_Query`, чтобы не тянуть
тему). `Endpoint::serve()` возвращает `['body' => string, 'content_type'
=> string]` или `null`; `Plugin::handle_feed_request()` ставит заголовок,
печатает тело и завершает запрос. Логика не эхоит и не вызывает `exit`
сама — HTTP-обвязка в `Plugin`.

Рендерер-слаг берётся из query var `nwff_feed_slug`. Базовый slug — из
`nwff_settings['feed_slug']`, дефолт `feed`.

Если слаг неизвестен — 404. Проверка «лента выключена» появится вместе
с настройками рендерера (этап Settings).

Кэш-ключ — `nwff_feed_` + `md5( wp_json_encode( $normalized_args ) )`,
где `$normalized_args` содержит слаг рендерера, `depth_hours`, `limit`
и таксономии, отсортированные по ключу и ID.

Правила перезаписи сбрасываются при активации (`flush_rewrite_rules`).

### Cron-очистка

Хук `nwff_cleanup_meta`, событие `daily`.

Обработчик: удаление из `nwff_feed_meta` записей, где
`updated_at < NOW() - ( depth_hours + 30 дней )`. Запас 30 дней — чтобы
не удалить данные по записям, которые ещё могут понадобиться при расширении
глубины.

Плюс — синхронизация с `deleted_post`: при удалении записи удаляется строка
из `nwff_feed_meta`.

### Метабокс на записи

Один метабокс `News Feed Factory` в сайдбаре редактора.

**Общий чекбокс:**

- «Отдавать в ленты» (default: on). Сохраняется в колонку
  `exclude_from_feeds` инвертированно: checked → `0`, unchecked → `1`.

**Точки расширения:** рендерер может добавить свои чекбоксы через фильтр
`nwff_post_meta_fields`. Например, Дзен может добавить «Публиковать в канал».

При сохранении — запись в `nwff_feed_meta` через `MetaRepository`. Nonce,
проверка прав (`current_user_can( 'edit_post', $post_id )`).

### i18n

Текстовый домен — `news-feed-factory`.
Загрузка — `load_plugin_textdomain` на `init`.
`.pot` — в `/languages/`.
Строки в коде — английские, интерфейс переводится.

### Тестирование

Соответствие ADR-PROC-0001: тест до фикса, тест остаётся.
Bootstrap-файлы — по ADR-PROC-0004.

- **Unit (`tests/bootstrap-unit.php`, без WP):**
  - `Collector` — нормализация аргументов.
  - `TransientCache` — построение ключа.
  - `MetaRepository` — генерация SQL (через моки `$wpdb`).
  - `Normalize/` — `Truncator`, `HtmlSanitizer::to_plain`,
    `ContentNormalizer` (см. ADR-ARCH-0002).
  - `Render/` — `GenreResolver`, `RendererInterface` (рефлексия).
- **Integration (`tests/bootstrap-integration.php`, WP test suite):**
  - `WP_Query` + реальная БД.
  - `HtmlSanitizer::sanitize`, `ImageExtractor` (нужны `wp_kses`
    и attachment).
  - `MetaRepository` с реальной таблицей.
  - Помечаются `@group integration`, исключены из локального
    `composer check`.
- **Framework:** PHPUnit. Конфиг — `phpunit.xml.dist`.

## Последствия

**Плюсы:**

- Ядро покрыто одним ADR — новые рендереры добавляются без переделок.
- Один сборщик — один источник правды для данных.
- Явный слой нормализации (ADR-ARCH-0002) — рендеры тонкие.
- Кастомная таблица — контроль над схемой и очисткой.
- Интерфейс рендерера — точки расширения для Google и Дзена без правки ядра.
- Точки расширения (фильтры) позволяют рендерерам добавлять поля, не трогая
  ядро.

**Минусы:**

- Много файлов и классов для плагина «на старте».
- Сброс кэша по префиксу — «грубая» инвалидация, но проще и дешевле.
- Метабокс и таблица — дополнительная нагрузка на редактора (один флаг).

**Риски:**

- Ревьюеры WordPress.org придерутся к кастомной таблице — нужен убедительный
  readme.
- `IN (...)` с большим числом ID может упереться в лимиты MySQL — при 12
  часах глубины и высокой частоте публикаций маловероятно, но chunking
  заложен.
- Cron не срабатывает на сайтах с низким трафиком — при необходимости
  добавить очистку при сохранении записи (проверка раз в сутки).

## Альтернативы

- **Хранить метаданные в `wp_postmeta`.** Отклонено: не масштабируется,
  нет безопасной очистки.
- **Отдельный `WP_Query` для каждой ленты.** Отклонено: N+1, избыточная
  нагрузка.
- **Кэш на уровне `WP_Query` (встроенный).** Отклонено: `WP_Query` кэш
  зависит от порядка аргументов таксономий, плюс нам нужен кэш уже
  собранного массива с метаданными, а не только SQL.
- **Рендерер-как-функция вместо класса.** Отклонено: интерфейс даёт единый
  контракт, проще тестировать и регистрировать.
- **Отдельная страница настроек на каждый формат.** Отклонено: один
  экран проще для контентщиков, меньше опций — меньше ошибок.
- **Логика жанра в ядре.** Отклонено: жанр — специфика Яндекса, вынесена в
  `ADR-ARCH-0003`.
- **Хранить настройки как отдельные опции (одна опция = одно поле).**
  Отклонено: один контейнер `nwff_settings` — меньше запросов к БД.
- **Поле `feed_type` в ядре.** Отклонено: ядро не знает о конкретных
  форматах; специфичные поля добавляет рендерер.
- **Колонка `include_in_feeds`.** Отклонено: default `1` в схеме
  неудобен при `ALTER TABLE` и при миграциях; инверсия — в UI.

## Ссылки

- **Зависит от:** ADR-PRODUCT-0001 (продукт News Feed Factory),
  ADR-PROC-0001 (рабочий цикл), ADR-PROC-0002 (документация и ADR).
- **Уточняется в:** ADR-ARCH-0002 (нормализация контента),
  ADR-ARCH-0003 (рендерер Яндекса), ADR-ARCH-0004 (рендерер Google, TBD),
  ADR-ARCH-0005 (рендерер Дзена, TBD).
- **Связано с:** ADR-PROC-0003 (git-workflow),
  ADR-PROC-0004 (раздельные bootstrap'ы),
  ADR-PROC-0005 (запрет ABSPATH-guard в `src/`).

## Открытые вопросы

- **Chunking в `MetaRepository`:** нужен ли сразу, или заложить и ждать
  первого кейса на реальных данных? Пока в решении — заложено, но без
  явного порога (500 ID).
- **Кэш-ключ для нескольких форматов:** один и тот же набор данных
  рендерится в разные форматы. Кэшировать собранный массив (до рендера)
  или готовый XML? Решение в ADR: кэшируем XML (отдаём как есть), но это
  значит, что при добавлении нового рендерера кэш пересобирается с нуля
  для него. Можно пересмотреть при появлении третьего рендерера.
- **`updated_at` vs `created_at`:** в схеме только `updated_at`. Если
  понадобится аналитика (когда запись впервые попала в фид) — добавить
  `created_at`. Пока не нужно.
- **Telemetry/логирование:** куда писать ошибки сборщика? Пока — в
  `error_log` через `wp_trigger_error`. Отдельная настройка логов —
  когда появится боль.
- **`include_terms` для generic RSS:** нужен ли рендер без terms вообще,
  или terms всегда есть в фиде? Пока `Collector` уважает флаг из
  `get_normalization_config()` — рендер сам решает.
