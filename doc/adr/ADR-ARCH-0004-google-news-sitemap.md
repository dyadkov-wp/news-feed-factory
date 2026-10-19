# ADR-ARCH-0004: Рендерер Google News (sitemap)

**Статус:** Proposed
**Создано:** 2026-10-19
**Обновлено:** 2026-10-19

## Контекст

Google — второй рендер (ADR-PRODUCT-0001). С 2025 года Google News
не использует RSS из Publisher Center для обнаружения материалов;
основной канал — **Google News Sitemap**. RSS остаётся опциональным
и не является каналом включения в Google News.

Формат — XML Sitemap (`<urlset>` с `xmlns:news`), не RSS. Требования:

- Корень `<urlset>` с двумя namespace.
- Каждый `<url>` содержит `<loc>` и один `<news:news>`.
- Обязательные вложенные: `<news:publication>` (`<news:name>`,
  `<news:language>`), `<news:publication_date>`, `<news:title>`.
- Верхняя граница окна — 48 часов. Нижней границы нет.
- Лимит — 1000 `<news:news>` на файл.
- `news:name` точно совпадает с названием издания в Google News.

Рендер строится поверх ядра (ADR-ARCH-0001), нормализации
(ADR-ARCH-0002) и конфигурации рендеров (ADR-ARCH-0006).
`RendererInterface` (7 методов) достаточен — sitemap реализуется
без правок ядра (см. «Совместимость с `RendererInterface`»).

## Решение

### Структура файлов

```
src/
├── Render/
│   ├── RendererInterface.php
│   ├── YandexRenderer.php
│   ├── Yandex/
│   │   └── GenreResolver.php
│   └── Google/
│       └── NewsSitemapRenderer.php
```

`NewsSitemapRenderer` реализует `RendererInterface`. Отдельных
классов-хелперов не вводим: логика таксономий специфична для
каждого рендера, общий парсер — TD-14 (см. «Технический долг»).

### Совместимость с `RendererInterface`

Интерфейс не расширяется. Сигнатуры:

- `get_slug(): string` → `'google-news'`.
- `get_content_type(): string` → `'application/xml; charset=UTF-8'`.
- `get_normalization_config(): array` → см. ниже.
- `render( array $items, array $args ): string` → XML-строка.
- `get_settings_schema(): array` → см. ниже.
- `is_enabled( array $settings ): bool` → `enabled` из секции.
- `get_collector_args( array $settings ): array` → см. ниже.

`render()` возвращает строку — sitemap это тоже строка. `Endpoint`
отдаёт её с заголовком из `get_content_type()`. Кэш ключуется по
slug'у (`google-news`), коллизий с `yandex` нет. Ветки «если
формат = X» в пайплайне нет и не нужно.

### Slug и эндпоинт

`get_slug()` → `'google-news'`. URL — `/feed/google-news.xml`.
Консистентно с Yandex (`/feed/yandex.xml`), без сюрпризов для
редакторов.

Регистрация — через `nwff_feed_endpoints`. Если `enabled = false` —
404 (через `Endpoint::serve()`, см. ADR-ARCH-0006).

### Конфигурация нормализации

```php
public function get_normalization_config(): array {
    return [
        'max_content_length' => null,
        'truncate_mode'      => 'none',
        'truncate_limit'     => 0,
        'allowed_html'       => null,
        'image_min_width'    => null,
        'image_min_height'   => null,
        'include_terms'      => false,
    ];
}
```

`include_terms = false` — sitemap не содержит рубрик, `terms` в
нормализованном элементе не нужны. Экономит `wp_get_post_terms()`
в `Collector`'е на каждом посте.

`content` в sitemap не рендерится, но `Collector` всё равно
прогоняет `post_content` через `HtmlSanitizer::sanitize()` (по
контракту ADR-ARCH-0002, до нормализатора). На окне 4–12 часов
и 1000 постах это не критично; вынос в отдельный флаг
`include_content` — TD-17.

### `get_collector_args()`

```php
public function get_collector_args( array $settings ): array {
    $core     = $settings['core'] ?? [];
    $renderer = $settings['renderers'][ $this->get_slug() ] ?? [];

    return [
        'depth_hours'      => (int) ( $core['depth_hours'] ?? 4 ),
        'limit'            => 1000,
        'taxonomy_include' => $this->parse_taxonomies( $renderer['include_taxonomies'] ?? '' ),
        'taxonomy_exclude' => $this->parse_taxonomies( $renderer['exclude_taxonomies'] ?? '' ),
    ];
}
```

**`depth_hours` — из core** (default 4, max 12). Google допускает
окно ≤48 часов; core-значение укладывается в спеку с запасом.
Продуктовый аргумент: на 48-часовом окне статья либо уже
проиндексирована, либо неактуальна — news sitemap для discovery
свежего, не для архива.

**Риск слишком короткого окна.** Если `depth_hours` окажется
меньше интервала краулинга Google News, часть статей на момент
визита краулера уже выпадет из sitemap. Для региональной газеты
(десятки публикаций в день, 4h-окно — 2–8 статей) риск
теоретический. **Мониторить Search Console**; при проблемах
поднимать core `depth_hours`, а не вводить per-renderer override
(это сохраняет решение ADR-ARCH-0006 «override не вводим, пока
рендер не потребует»).

**`limit = 1000` — жёстко.** Не из настроек, не из core. Google
требует. `WP_Query` сортирует `date DESC` (дефолт), самые свежие
1000 попадают.

**`taxonomy_include` / `taxonomy_exclude` — из настроек рендера.**
Формат — как в ADR-ARCH-0006 (`category:12,15|post_tag:100`,
пустая строка → `[]`). Парсер дублируется с Yandex-рендером;
вынос в общий хелпер — TD-14.

### Настройки рендера

Регистрируются через `get_settings_schema()`, контейнер —
`nwff_settings['renderers']['google-news']`.

| Ключ | Тип | Дефолт | Назначение |
| --- | --- | --- | --- |
| `enabled` | checkbox | `false` | Включить sitemap (opt-in, как у Yandex) |
| `news_publication_name` | text | `''` | `news:name`; fallback в `render()` — `get_bloginfo('name')` |
| `news_language` | text | `''` | ISO 639-1; fallback — первые 2 символа `get_bloginfo('language')`, затем `'ru'` |
| `include_taxonomies` | text | `''` | Фильтр выборки (опционально) |
| `exclude_taxonomies` | text | `''` | Фильтр выборки (опционально) |

**`news_publication_name` — настройка, не только `get_bloginfo`.**
Спека Google требует **exact match** с названием издания в Google
News Publisher Center. `blogname` и название в Google News обычно
совпадают, но не всегда. Дефолт в схеме — пустая строка (unit-тесты
`get_settings_schema()` не требуют WP). Fallback в `render()`:

```php
$name = (string) ( $renderer['news_publication_name'] ?? '' );
if ( '' === $name ) {
    $name = (string) get_bloginfo( 'name' );
}
```

`Settings\Page` для UX показывает `get_bloginfo('name')` как
placeholder поля (это забота `Page`, не схемы). Сохранённое значение
имеет приоритет; пустое → fallback на `get_bloginfo`.

**Edge case:** если итоговое `$name` пусто (сайт без `blogname` —
сломанная конфигурация WP) — `WARNING` в лог + пустой `<urlset>`.
Без `news:name` валидный `<news:news>` невозможен, отдавать
частично-валидный sitemap хуже, чем пустой.

**`news_language` — аналогично.** Fallback:

```php
$lang = (string) ( $renderer['news_language'] ?? '' );
if ( '' === $lang ) {
    $lang = substr( (string) get_bloginfo( 'language' ), 0, 2 );
    if ( '' === $lang ) {
        $lang = 'ru';
    }
}
```

`get_bloginfo('language')` возвращает WPLANG-формат (`ru_RU`),
`substr(0, 2)` даёт `ru`. Для `en_US` → `en`, для пустого → `ru`.

**`include_taxonomies` / `exclude_taxonomies` — в MVP.** Имена
совпадают с Yandex-рендером (не `include_terms`, то — булев флаг
нормализации). Парсер — 15–20 строк, как в ADR-ARCH-0006.

### `is_enabled()`

```php
public function is_enabled( array $settings ): bool {
    $renderer = $settings['renderers'][ $this->get_slug() ] ?? [];
    return ! empty( $renderer['enabled'] );
}
```

Симметрично Yandex. Default `false` — opt-in. На чистой установке
sitemap не отдаётся, пока пользователь не включит.

### Структура XML

```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">
  <url>
    <loc>https://example.com/post/123</loc>
    <news:news>
      <news:publication>
        <news:name>Областная газета</news:name>
        <news:language>ru</news:language>
      </news:publication>
      <news:publication_date>2026-10-17T15:10:08+00:00</news:publication_date>
      <news:title>Заголовок</news:title>
    </news:news>
  </url>
</urlset>
```

**Пустой sitemap** (нет статей в окне) — валидный пустой `<urlset>`
с обоими namespace'ами:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"></urlset>
```

Google допускает пустой `<urlset>` (warning в Search Console, не
error). 404 — нет: Google решит, что sitemap удалён. Namespace'ы
обязательны и в пустом ответе — иначе warning'и.

### Правила вывода полей

| Элемент | Источник | Обязателен | Условие вывода |
| --- | --- | --- | --- |
| `<loc>` | нормализованный `link` | да | всегда |
| `<news:news>` | — | да | на каждый item |
| `<news:publication>` | — | да | внутри `<news:news>` |
| `<news:name>` | `news_publication_name` или `get_bloginfo('name')` | да | всегда |
| `<news:language>` | `news_language` или fallback | да | всегда |
| `<news:publication_date>` | `$item['published']->format('c')` | да | всегда |
| `<news:title>` | нормализованный `title` | да | всегда |

`<lastmod>` не выводим — опционально, а `post_modified` нет в
нормализованном элементе. Добавление `modified` в контракт
ADR-ARCH-0002 — TD-16.

`<news:keywords>`, `<news:genres>` — опциональны, не делаем.

**Дата.** `format('c')` = ISO 8601 = `Y-m-d\TH:i:sP`. Даёт
`2026-10-17T15:10:08+00:00` для UTC (нормализатор отдаёт UTC по
контракту ADR-ARCH-0002). Google в примерах показывает `+00:00`,
не `Z`; W3C принимает оба. `format('c')` — без ручной подстановки
`Z`, читается проще.

### Генерация XML

**Вручную, через `sprintf` и heredoc.** Без `DOMDocument` и
`SimpleXML` — симметрично Yandex-рендеру (ADR-ARCH-0003).

**Экранирование** — `htmlspecialchars( $text, ENT_QUOTES | ENT_XML1,
'UTF-8' )` для `title` и `loc`. `ENT_XML1` — XML-эскейпер, не HTML5;
`esc_html`/`esc_url` из WP требуют загруженного WordPress и ломают
юнит-тестируемость (ADR-PROC-0004, ADR-PROC-0005).

**CDATA не используем** — sitemap не требует. Отличие от Yandex
(там `yandex:full-text` в CDATA).

### Обработка ошибок

- **Пустой набор items** → валидный пустой `<urlset>` с namespace'ами.
  Не ошибка (см. выше).
- **Нет `title` / `link` / `published`** в нормализованном элементе
  → пропустить item, `WARNING` в лог. Нормализатор такие уже
  отсекает (ADR-ARCH-0002), защита в рендере — на случай изменения
  контракта.
- **`published` не `DateTimeImmutable`** → пропустить, `WARNING`.
  По контракту нормализатора тип гарантирован, защита — дешёвая.
- **Итоговый `news:name` пуст** → `WARNING` + пустой `<urlset>`
  (см. «Настройки рендера»).

Плагин не «исправляет» контент: не переименовывает издание, не
подставляет язык, если пользователь его не задал (fallback — да,
но это не «исправление», а дефолт).

### Кэширование

Общее, через `TransientCache` (ADR-ARCH-0001). Ключ включает
`feed_type = 'google-news'` (через slug), TTL — `cache_ttl`
(core, default 5 минут).

**Дрейф окна в кэше.** Статья на границе окна (`depth_hours - 1m`)
попадёт в кэш и останется там до истечения TTL, став `depth_hours +
4m`. Google краулер терпим к нескольким минутам дрейфа; при
`depth_hours ≥ 4` это не проблема. При `depth_hours = 1` — риск
(см. «Риск слишком короткого окна» в `get_collector_args()`).

**Инвалидация** — `save_post` / `deleted_post` / `set_object_terms`
через префикс `nwff_feed_*` (ADR-ARCH-0001). Google подхватит
автоматически.

## Технический долг

Записано в `BACKLOG.md` под номерами TD-14…TD-17.

- **TD-14:** вынос парсера таксономий в общий хелпер.
- **TD-15:** sitemap index для >1000 URL.
- **TD-16:** `<lastmod>` / `modified` в нормализованном элементе.
- **TD-17:** флаг `include_content` в `normalization_config`.

Условия активации каждого — в BACKLOG.

## Тестирование

- **Unit** (`tests/unit/Render/Google/NewsSitemapRendererTest.php`):
  - `get_slug()` → `'google-news'`.
  - `get_content_type()` → `'application/xml; charset=UTF-8'`.
  - `get_normalization_config()` — все 7 ключей, значения.
  - `is_enabled()` — default `false`, `true` после настройки.
  - `get_collector_args()` — `depth_hours` из core (дефолт 4, кастом
    8), `limit = 1000` жёстко, таксономии из настроек.
  - `render()` — фикстуры нормализованных элементов → XML →
    `simplexml_load_string` → XPath:
    - namespace `http://www.sitemaps.org/schemas/sitemap/0.9` и
      `http://www.google.com/schemas/sitemap-news/0.9`;
    - `news:publication/news:name` = из настроек;
    - `news:publication/news:language` = из настроек;
    - `news:publication_date` формат `c`
      (`2026-10-17T15:10:08+00:00`);
    - `news:title` экранирован (`&` → `&amp;`, `<` → `&lt;`).
  - `render()` — пустой набор → валидный `<urlset>` с namespace'ами,
    без `<url>`.
  - `render()` — `news_publication_name` пуст → fallback на
    `get_bloginfo('name')` (мок через фильтр или stubs).
  - `render()` — `news_language` пуст → fallback на первые 2
    символа `get_bloginfo('language')`, затем `'ru'`.
  - `render()` — item без `title`/`link`/`published` → пропуск,
    `WARNING` (проверка через мок логгера, если он инжектируется;
    иначе — только что item не в XML).
- **Integration** (`tests/integration/Feed/EndpointTest.php`):
  - `serve('google-news', $settings)` → 200, `application/xml`,
    валидный XML.
  - `serve('google-news', ['enabled' => false])` → `null` (404).
  - Полный путь Collector → Normalizer → Renderer на реальных
    постах: свежие попадают, старые >`depth_hours` — нет,
    >1000 — отсекаются.

## Последствия

**Плюсы:**

- Google News sitemap — единственный канал обнаружения (RSS
  Publisher Center с 2025 не работает). Рендер закрывает требование.
- `RendererInterface` не расширяется — 7 методов достаточно.
- `depth_hours` из core, не хардкод 48 — соответствует редакционной
  реальности и не вводит per-renderer override.
- Настройка `news_publication_name` — exact match, как требует
  Google; fallback на `get_bloginfo` для UX.
- Пустой sitemap с namespace'ами — валиден, не 404.

**Минусы:**

- Парсер таксономий дублируется с Yandex (TD-14).
- `content` санитизируется впустую (TD-17).
- `lastmod` не выводится (TD-16).

**Риски:**

- Слишком короткий `depth_hours` (<2h) — часть статей не попадёт
  в sitemap на момент краулинга. Митигация: мониторить Search
  Console, поднимать core `depth_hours`, не вводить override.
- >1000 статей за окно — sitemap index (TD-15). При текущих объёмах
  нереалистично; **фиксируем: риск >1000 нереалистичен при наших
  объёмах** (окно 4–12h × региональная газета = десятки постов,
  не тысячи).
- `news:name` не совпадает с Google News — редактор должен
  поправить в настройках. Дефолт `get_bloginfo('name')` —
  разумная отправная точка.
- Кэш «замораживает» окно на TTL — дрейф до 5 минут. При
  `depth_hours ≥ 4` не проблема.

## Альтернативы

- **Sitemap + RSS для Google.** Отклонено: Google не использует RSS
  с 2025. RSS — отдельный рендерер (`google-rss` или `news-rss`),
  если понадобится для старых читалок; к Google News отношения
  не имеет.
- **`get_format(): 'rss' | 'sitemap'` в интерфейсе.** Отклонено:
  `render()` уже возвращает строку, `get_content_type()` — заголовок,
  пайплайн формат-агностичен. `get_format()` некому потреблять.
- **`SitemapRendererInterface`.** Отклонено: sitemap-специфика
  инкапсулирована в `render()`, отдельный интерфейс даёт 0
  дифференциации на уровне ядра.
- **`depth_hours = 48` жёстко.** Отклонено: 48 — верхняя граница,
  не нижняя. Core-окно (4–12h) укладывается в спеку и соответствует
  продуктовой логике («свежее — для discovery, старое — уже в
  индексе или неактуально»).
- **`news_publication_name` из `get_bloginfo` без настройки.**
  Отклонено: Google требует exact match, `blogname` ≠ название в
  Google News в общем случае.
- **`DOMDocument` / `SimpleXML`.** Отклонено: namespace'ы и
  экранирование в шаблонной строке проще и юнит-тестируемы без
  зависимостей (симметрично Yandex, ADR-ARCH-0003).
- **CDATA для `news:title`.** Отклонено: sitemap не требует,
  экранирование достаточно.
- **`lastmod` сразу.** Отклонено: опционально, требует правки
  контракта нормализации (TD-16).
- **Sitemap index сразу.** Отклонено: при наших объёмах >1000
  нереалистично (TD-15 с условиями активации).
- **Per-renderer override `depth_hours`.** Отклонено:
  ADR-ARCH-0006 «override не вводим, пока рендер не потребует».
  Google не требует — требует обратного.

## Ссылки

- **Зависит от:** ADR-ARCH-0001 (ядро, интерфейс рендера),
  ADR-ARCH-0002 (нормализация), ADR-ARCH-0006 (настройки и
  конфигурация рендеров).
- **Уточняет:** ADR-ARCH-0001 (раздел «Интерфейс рендерера» —
  подтверждает, что 7 методов достаточно), ADR-ARCH-0006 (форма
  секции настроек Google-рендера).
- **Связано с:** ADR-PROC-0001 (рабочий цикл), ADR-PROC-0003
  (git-workflow), ADR-ARCH-0003 (Yandex — источник решений по
  XML-генерации и настройкам).
- **Источник требований:**
  [Google News Sitemap docs](https://developers.google.com/search/docs/crawling-indexing/sitemaps/news-sitemap)
  (обновлено 2026-02-20).

## Открытые вопросы

- **`<lastmod>`.** TD-16, условия активации в BACKLOG.
- **Sitemap index для >1000.** TD-15, условия активации в BACKLOG.
- **`include_content` флаг.** TD-17, условия активации в BACKLOG.
- **Вынос парсера таксономий.** TD-14, условия активации в BACKLOG.
- **`news:keywords` / `news:genres`.** Опциональны, не делаем.
  Если Google Search Console начнёт рекомендовать — вернуться.
