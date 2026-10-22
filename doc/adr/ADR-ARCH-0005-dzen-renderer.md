# ADR-ARCH-0005: Рендерер Дзена (RSS 2.0)

**Статус:** Proposed
**Создано:** 2026-10-20
**Обновлено:** 2026-10-22

## Контекст

Дзен — третий рендер (ADR-PRODUCT-0001). Цель — трафик и дистрибуция:
формально Дзен не приветствует уход пользователей на сторонние сайты,
но для СМИ это фактически основной сценарий использования.

С осени 2026 ландшафт изменился:

- **Турбо-страницы Яндекса закрыты** с 02.06.2026, сервис недоступен.
  Турбо больше не альтернатива и не конкурент.
- **Дзен перешёл на единый RSS-формат** для сервиса Новости и канала
  (правила действуют с 2026-07-13). Старые раздельные ленты отключаются
  автоматически.
- Одна и та же лента может использоваться для **кросспостинга в MAX**
  (сентябрь 2026), но это побочный эффект, не цель.

Формат — **RSS 2.0** (`<rss version="2.0">`), не sitemap. Требования
Дзена:

- Корень `<rss>`, внутри `<channel>`: `title`, `link`, `description`,
  `language` — обязательны.
- `<item>`: обязательны `title`, `link`, `pubDate`, `guid`,
  `content:encoded`.
- `content:encoded` — полный HTML с ограниченным набором тегов.
  Изображения внутри — через `<figure><img>`, рекомендательно
  ≥ 480 × 320 px.
- `enclosure` — обложка (превью) и картинка для медиаблока,
  рекомендательно ≥ 480 × 320 px, вес ≤ 25 Мб.
- `category` — одна рубрика с сайта, оригинальное название.
- `contentType` — `news`, `news_only` или `blogs_only`. Если не указан,
  Дзен считает `news_only`.
- `pubDate` — RFC-822. Актуальны материалы **за последние 7 дней**;
  старше — не индексируются. Это **разрешение**, не требование:
  core-окно `depth_hours` (1–12) укладывается с запасом.
- `guid` — уникальный идентификатор, желательно полная ссылка.
- `genre` в текущей спеке Дзена **не требуется** (в отличие от Яндекса).

Рендер строится поверх ядра (ADR-ARCH-0001), нормализации
(ADR-ARCH-0002) и конфигурации рендеров (ADR-ARCH-0006).
`RendererInterface` (7 методов) достаточен — новых методов не требуется.

**Согласовано с реальным кодом (см. замечания к черновику):**
- `include_content` в `get_normalization_config()` **не существует**
  (это TD-17, отложен). `content` в нормализованном элементе уже
  sanitized HTML после `Collector::collect()` → `ContentNormalizer`
  переносит as-is. `DzenRenderer` берёт `$item['content']` напрямую.
- Core-окно `depth_hours` — 1–12; `min(12, 168) = 12` — вырождается,
  `min()` не нужен (TD на расширение core до >168h, если появится).
- `enclosure_fallback` (первое изображение из контента) — это TD-05,
  отложен. В v1 — только featured.
- `limit` в core нет: Yandex — `0` (все), Google — `1000` (жёстко).
  «Общий» — не сущность; в Dzen задаётся константой.

## Решение

### Структура файлов

```
src/
├── Render/
│   ├── RendererInterface.php
│   ├── YandexRenderer.php
│   ├── DzenRenderer.php              ← новый (Yandex-паттерн: корень + подпапка)
│   ├── Dzen/
│   │   ├── ContentProcessor.php      ← санитайзер HTML под Дзен
│   │   └── EnclosureResolver.php     ← выбор обложки
│   └── Google/
│       └── NewsSitemapRenderer.php   ← как в реальности (не в корне Render/)
```

`DzenRenderer` реализует `RendererInterface`:

- `get_slug(): 'dzen'`
- `get_content_type(): 'application/rss+xml; charset=UTF-8'`
- `get_normalization_config():` см. «Совместимость с `RendererInterface`»
- `render( array $items, array $args ): string` — собирает RSS 2.0
- `get_settings_schema():` см. «Настройки»
- `is_enabled( array $settings ): bool` — default `false` (opt-in,
  симметрия с Yandex/Google, ADR-ARCH-0006)
- `get_collector_args( array $settings ): array` — `depth_hours` из core
  + `taxonomy_include` / `taxonomy_exclude` (симметрия с Yandex/Google)

### URL и slug

- slug фида — `dzen` (бренд, консистентно с `YandexRenderer`).
- URL по умолчанию — `/feed/dzen.xml` (slug = имя файла; базовый `/feed/`
  может быть изменён в настройках плагина, как у остальных рендеров).
- Регистрация в `Plugin::make_renderers()`:
  `'dzen' => new DzenRenderer( new Dzen\ContentProcessor(), new Dzen\EnclosureResolver() )`.

### Схема фида

**Namespace обязателен** (иначе `<content:encoded>` не парсится):

```xml
<rss version="2.0"
     xmlns:content="http://purl.org/rss/1.0/modules/content/">
```

`<channel>`:
- `title` — название сайта (или «Сайт: раздел», если фид раздела).
- `link` — домен сайта.
- `description` — одно предложение, без HTML.
- `language` — `ru`.

`<item>`:
- `title` — заголовок (≤ 200 символов; рекомендательно ≤ 80).
  Через `Truncator::truncate( $title, 'chars', 200 )` (как Yandex),
  не `mb_substr`.
- `link` — канонический URL статьи.
- `pubDate` — RFC-822 (`D, d M Y H:i:s O`).
- `<guid isPermaLink="true">` — полная ссылка (совпадает с `link`).
  Известное ограничение: при смене постоянной ссылки `guid` меняется,
  Дзен считает это новым постом. Приемлемо (Yandex живёт так же),
  TD не заводим.
- `content:encoded` — полный HTML, пропущенный через `ContentProcessor`,
  **в CDATA** (см. «ContentProcessor»).
- `category` — одна рубрика: первая из `terms` (порядок как в массиве;
  TD-08 — Yandex-specific, не предрешаем в Dzen).
- `<enclosure url="..." type="..." length="..."/>` — обложка
  (см. «EnclosureResolver»). Один элемент.
- `author` — `display_name` автора, если не пуст (без настройки).
- `<contentType>news_only</contentType>` — camelCase в XML (спека Дзена),
  snake_case в настройках (`content_type`). Соответствие фиксируем явно.

**Не реализуем в v1** (YAGNI, точки расширения):
`dzenTitle`, `comment`, `theme_tags`, `media:group` для видео.
Видео в `content:encoded` — только для `blogs_only` (отдельная задача).

### `ContentProcessor`

Задачи (один проход, без N+1):

1. **Whitelist, не «информационное описание».** В финальном фрагменте
   допустимы **только** теги Дзена: `p`, `a`, `b`, `i`, `u`, `s`,
   `h1–h4`, `blockquote`, `ul`/`ol`/`li`, `img`, `figure`, `figcaption`,
   `br`. Всё, что не перечислено ниже как cut или rename и не входит
   в этот список (`div`, `span`, `h5`, `h6`, `table`, `section`,
   `article`, `aside`, `header`, `footer`, `nav`, `main`, `address`,
   `dl`/`dt`/`dd`, …), — **unwrap**: тег убрать, содержимое сохранить.
   `wp_kses_post` upstream пропускает многие из этих тегов; полагаться
   на него как на gatekeeper нельзя — контракт держит `ContentProcessor`.
2. Нормализовать `em → i`, `strong → b` (в `ContentProcessor`, **не**
   в `allowed_html`: `allowed_html = null` → `wp_kses_post`, который
   `em`/`strong` не режет, их заменит процессор).
3. **Cut with content:** `script`, `style`, `iframe`, `form`, `input`.
   Для `script`/`style` это defense in depth поверх `wp_kses_post`;
   для `iframe`/`form`/`input` — страховка на случай отключённого или
   пропущенного upstream-санитайза.
4. Обернуть одиночный `<img>` без родителя `<figure>` в
   `<figure>…</figure>`. Если родитель уже `<figure>` — не трогать.
5. **Размеры `<img>` внутри content не проверять**: это N+1 запросов
   (`attachment_url_to_postid` + `wp_get_attachment_image_src` на каждую
   картинку). По спеке — рекомендация, не требование. Размеры
   проверяются только для featured (в `ImageExtractor`, для `enclosure`).
   Кандидат в TD-20: «если Дзен пожалуется на картинки в content».
6. **CDATA escape:** `str_replace(']]>', ']]]]><![CDATA[>', $html)`
   (как Yandex).

#### Политика атрибутов

Фильтрация атрибутов — **blacklist, не whitelist**. Сохраняются все
атрибуты исходного HTML на разрешённых тегах, кроме:

- `style` — инлайн-стили отбрасываются всегда;
- `on[a-z]+$` (case-insensitive, имя целиком) — event handlers:
  `onclick`, `onload`, `onerror`, `onmouseover`, … Паттерн с якорем
  `$` и `[a-z]+` намеренно не матчит кастомные атрибуты с дефисом
  (`only-x`) и цифрами. Цена: `once`/`only`/`one` без дефиса стрипаются
  как «похожие на handler» — компромисс в пользу defense in depth,
  в стандартном HTML таких атрибутов нет, а новый `on*`-handler
  в браузерах мы всё равно поймаем без обновления списка.

Перечисленные выше `href`, `title`, `src`, `alt`, `width`, `height` —
атрибуты, которые **обязаны пережить** обработку, а не единственные
разрешённые. Blacklist выбран осознанно:

- Dzen RSS 2.0 принимает произвольные атрибуты на разрешённых тегах;
- whitelist пришлось бы держать синхронно между кодом, тестами и ADR —
  лишний канал drift'а без выигрыша в безопасности (`style` и `on*` —
  единственные классы атрибутов с реальным риском в этом фиде);
- «лишние» безопасные атрибуты (`class`, `data-*`, `id`) на выходе не
  ломают схему и не утекают в вёрстку Дзена.

Регрессионные тесты:
`ContentProcessorTest::test_allowed_attributes_are_preserved`,
`ContentProcessorTest::test_event_and_style_attributes_are_removed`,
`ContentProcessorTest::test_rename_keeps_attributes_and_children`,
`ContentProcessorTest::test_attribute_starting_with_on_but_not_handler_is_preserved`.

`content:encoded` **обязателен**: если `$item['content']` пуст или после
`ContentProcessor` пуст — **пропускаем item с WARNING** (прецедент —
Yandex с пустым title). Пустой фид — валидный `<rss><channel>…</channel></rss>`
без `<item>` (как Yandex), не 404.

### `EnclosureResolver`

- Берёт `_thumbnail_id` записи → `ImageExtractor` уже проверяет
  `image_min_width` / `image_min_height` (480 × 320). Если featured
  меньше — `null`, `enclosure` не выводится (допустимо по спеке).
- `length` обязателен по спеке, но `ImageExtractor` возвращает
  `url`/`width`/`height`, **не** `filesize`. Источник:
  `wp_get_attachment_metadata( $id )['filesize']` (из метаданных),
  fallback — `0`. **Статический кэш на рендер** (`$cache[$id]`):
  несколько постов могут ссылаться на одно вложение.
- `type` — `wp_check_filetype( get_attached_file( $id ) )['type']`
  (`image/jpeg`, `image/png`, `image/webp`).
- **`enclosure_fallback` в v1 нет** — это TD-05 (image-in-content),
  отложен. Без featured `enclosure` не выводится. Явно фиксируем:
  fallback на первое изображение из контента — TD-05, не v1.

### Окно и лимит

Три **разные оси** ограничений — не путать (таблица обязательна для
будущего читателя):

| Параметр | Ось | Что ограничивает | Значение |
|---|---|---|---|
| `limit` | Запрос (`WP_Query`) | Верхняя граница выборки (CPU, память БД) | `300` (`DzenRenderer::MAX_ITEMS`) |
| `max_content_length` | Вход (per-post) | Один пост не может быть > 500КБ HTML (защита CPU на sanitize) | `500_000` |
| Render budget | Выход (per-feed) | Суммарный XML ≤ 10МБ (защита от отклонения Дзеном) | `9_500_000` |

- `depth_hours` — **из core, без `min()`** (см. «Контекст»). 7 дней —
  верхняя граница, core-окно 1–12h укладывается с запасом. Если
  когда-нибудь расширим core max до >168h — вводить `min()` тогда
  (кандидат в TD).
- `limit = 300` — константа. Расчёт: 10МБ / 30КБ (типичный пост
  с полным HTML в RSS) ≈ 330; 300 — безопасно.
- `max_content_length = 500_000` — ресурсный лимит на пост, не
  форматный. Не путать с `truncate_limit` (другая ось, ADR-ARCH-0002).
- **Per-post `truncate_limit` / усечение по размеру — не применяем**
  (в v1).
- `taxonomy_include` / `taxonomy_exclude` — в `get_collector_args()`
  (симметрия с Yandex/Google).

### Budget guard (render-time, per-feed)

**Зачем:** worst-case `300 × 500КБ = 150МБ` — верхняя граница, не
инвариант. «300 постов ≤ 10МБ» держится только в типичном случае
(10–30КБ/пост). Без guard один выброс (5 постов по 2МБ HTML)
ломает весь фид — Дзен отклонит файл целиком.

**Семантика (зафиксировать явно):**

1. **Cut from tail, newest preserved.** `WP_Query` — `date DESC`
   (как Yandex/Google). Накопление идёт от новых к старым. При
   превышении бюджета обрываем хвост (самые старые посты). Новые
   всегда попадают первыми. Для news-фида новый контент важнее —
   это не компромисс, а требование.

2. **Stop-at-previous, не skip-and-continue.** Три варианта при
   превышении: (a) добавить и превысить — нарушает инвариант;
   (b) пропустить, продолжить со следующего — неконтинуально,
   странно; (c) остановиться на предыдущем — **принимаем**.
   (c) даёт contiguous prefix: посты `1..N`, где `N` — последний,
   влезший в бюджет. Детерминированно, кэш стабилен, тесты тривиальны.

3. **`O(n)`, не `O(n²)`.** Наивный `strlen($items_xml . $rendered)`
   в цикле копирует строку на каждой итерации — 300 × до 9.5МБ
   ≈ 3ГБ аллокаций. Правильно — накапливать счётчик:

   ```php
   $budget      = self::RENDER_BUDGET; // 9_500_000
   $items_xml   = '';
   $current_len = 0;
   $kept        = 0;
   $total       = 0;

   foreach ( $items as $item ) {
       ++$total;
       $rendered = $this->render_item( $item, $args );
       if ( null === $rendered ) {
           continue;
       }
       $rendered_len = strlen( $rendered ); // байты, не mb_strlen
       if ( $current_len + $rendered_len > $budget ) {
           $this->log( sprintf(
               'Dzen feed truncated at render budget: kept %d of %d items, %d bytes (budget %d).',
               $kept,
               $total,
               $current_len,
               $budget
           ) );
           break;
       }
       $items_xml   .= $rendered;
       $current_len += $rendered_len;
       ++$kept;
   }
   ```

   `strlen` — байты; для файлового лимита нужны байты.

4. **`9.5МБ` — правильный порог.** 10МБ — лимит Дзена, 0.5МБ headroom:
   - XML-обёртка (`<?xml?> + <rss> + <channel>`): ≈ 1–2КБ;
   - Per-item обёртки (`<item>`, `<title>`, `<link>`, `<pubDate>`,
     `<guid>`, `<category>`, `<author>`, `<contentType>`,
     `<enclosure/>`, `<content:encoded><![CDATA[]]>`): ≈ 400–500 байт;
   - `300 × 500 = 150КБ`. 0.5МБ — трёхкратный запас. Достаточно.

5. **Edge case: один пост > 9.5МБ.** `$kept = 0`, фид пустой
   (валидный `<rss>` без `<item>`, см. «ContentProcessor»),
   WARNING в лог. **Defense in depth:** при `max_content_length = 500КБ`
   (Collector) один пост физически не может быть > 500КБ; случай
   достижим только если truncation упал upstream (баг или отключённая
   опция). Пустой фид + лог лучше, чем один пост, который Дзен
   отклонит целиком.

6. **WARNING — конкретика для диагностики:**
   `Dzen feed truncated at render budget: kept N of M items, X bytes (budget 9500000).`
   Без «рекомендаций» — лог фиксирует факт, ops решает.

7. **Кэш-инвариант:** «same items → same output → same cache».
   Cut детерминирован (byte count, не timing). Если все 300 постов
   влезли — поведение идентично фиду без guard'а, кэш тот же.
   Если обрезали — в кэш идёт обрезанный XML, стабильный при том же
   наборе items (`save_post` инвалидирует).

8. **Известные ограничения (не TD, факт):**
   - **Транзиент до 9.5МБ в `wp_options`** — LONGTEXT (4ГБ) влезает,
     но чтение на каждый запрос заметно без object cache (Redis/Memcached).
     При TTL 5 мин на новостном сайте — не блокер. Yandex RSS при
     12h-окне тоже может быть несколько МБ. Если станет проблемой —
     TD «вынести транзиент фида в отдельную таблицу или object cache».
   - **Peak memory при сборке + записи:** `$items_xml` (~9.5МБ) +
     `serialize()` при записи транзиента (~9.5МБ) + SQL-escape
     (~2× из-за HTML-спецсимволов) ≈ 30МБ пиково. `memory_limit`
     по умолчанию 128МБ — влезает. Упомянуть, не блокер.

**Константы:** `DzenRenderer::MAX_ITEMS = 300`,
`DzenRenderer::RENDER_BUDGET = 9_500_000`.

### Настройки (`get_settings_schema`)

- `enabled` (bool, default `false`).
- `content_type` — `news_only` (default), `news`, `blogs_only`.
  В XML — `<contentType>` (camelCase).
- **Убрано из v1:** `category_priority` (TD-08, Yandex-specific,
  не предрешаем), `include_author` (выводим всегда если не пуст),
  `enclosure_fallback` (TD-05, отложен).

### Кэширование и инвалидация

- Транзиенты — как у остальных рендеров.
- При смене `nwff_settings` — инвалидация (увязка с TD-19; условие
  закрытия TD-19 усиливается: теперь затрагивает и Dzen).

### Совместимость с `RendererInterface`

7 методов достаточно.

**`get_normalization_config()`:**

```php
[
    'max_content_length' => 500_000,   // 500КБ, ресурсный лимит на пост
    'truncate_mode'      => 'none',
    'truncate_limit'     => 0,
    'allowed_html'       => null,      // wp_kses_post; ContentProcessor доводит до Dzen
    'image_min_width'    => 480,       // для featured/enclosure
    'image_min_height'   => 320,
    'include_terms'      => true,      // нужна category
]
```

- `allowed_html = null` — `Collector` не режет `em`/`strong`/`div`/`span`;
  `ContentProcessor` — единственный владелец Dzen-специфики (не размазываем
  по двум местам).
- `image_min_*` — для featured (`enclosure`), не для картинок в content
  (см. «ContentProcessor», п. 5).

## Альтернативы

1. **Turbo-страницы.** Закрыты с 02.06.2026 — не рассматриваются.
2. **Отдельный плагин.** Отклонено: ядро, нормализация и настройки уже
   есть; дублирование инфраструктуры.
3. **Две отдельные ленты (News и канал).** Дзен поддерживает, но
   рекомендует единую ленту с `contentType` на пост. Выбираем единую —
   меньше настроек и URL.
4. **Несколько `<enclosure>` на все изображения.** Текущая спека Дзена
   трактует `enclosure` как обложку; изображения внутри — через
   `content:encoded`. Отклонено.
5. **Slug `zen` / `/feed/zen.xml`.** Отклонено: рассинхрон slug↔URL
   (Yandex/Google — синхронны), сломает `Endpoint` (slug = query var),
   настройки и GSC-проверку. Берём `dzen` + `/feed/dzen.xml`.

## Последствия

- **TD-05 (image-in-content)** — блокирует только fallback для
  `enclosure`; в v1 feature-required. Не блокирует Дзен.
- **TD-08 (приоритет рубрик)** — Yandex-specific; в Dzen v1 — первая
  из `terms`. Когда TD-08 закроется — решим, общая настройка или
  per-renderer.
- **TD-09 (`media:group` для Яндекса)** — не блокирует Дзен; после.
- **TD-16 (`lastmod`/`modified`)** — для Дзена не обязателен; пока
  не трогаем.
- **TD-17 (`include_content`)** — не нужен для Dzen (content уже
  sanitized). Остаётся для других сценариев.
- **TD-19** — затрагивает и Dzen-фид; условие закрытия усиливается.
- **Новый кандидат TD-20** (завести в BACKLOG при коммите ADR):
  «Проверка размеров `<img>` в content под Дзен» — условие: Дзен
  пожалуется на картинки в тексте; путь — батч-резолв через
  `WP_Query` + `update_meta_cache`, не N+1.
- **Деплой:** без изменений — `git archive` + админка плагинов.
- **Render budget guard** — детерминированный cut from tail,
  contiguous prefix, `O(n)`. WARNING с `kept/total/bytes`.
  Edge case «single item > budget» → пустой фид + WARNING
  (defense in depth; при `max_content_length = 500КБ` недостижим
  штатно).
- **Известные ограничения:** транзиент до 9.5МБ в `wp_options`
  (без object cache — заметное чтение; при TTL 5 мин не блокер);
  peak memory ~30МБ при сборке + записи.
- **Движок `ContentProcessor`:** `DOMDocument` (ext-dom), см. подсекцию
  «Движок: DOMDocument». Внутри — никаких WP-функций (pure function).

## Тесты

### Integration (`EndpointTest` для `/feed/dzen.xml`)

- **200 + Content-Type:** `application/rss+xml; charset=UTF-8`,
  без 301 (наследие фикса `73bc0f8`).
- **Обязательные теги:** `rss@version="2.0"`, `channel/title`,
  `channel/link`, `channel/description`, `channel/language`.
- **Namespace `content`:** `simplexml` →
  `getNamespaces(true)['content']` присутствует.
- **CDATA:** `content:encoded` содержит `<![CDATA[`, HTML внутри
  не экранирован повторно.
- **`]]>`-escape:** пост с `]]>` в HTML → в фиде
  `]]]]><![CDATA[>`.
- **`guid isPermaLink="true"`:** атрибут присутствует, значение =
  `link`.
- **`enclosure type/length`:** `type` из `wp_check_filetype`
  (`image/jpeg` и т.п.), `length` > 0 (из `wp_get_attachment_metadata`).
- **Запрещённые теги:** `<script>`, `<style>`, `<iframe>`, `<div>`,
  `<span>` вырезаны из `content:encoded`.
- **`em→i`, `strong→b`:** нормализованы.
- **`<figure>`-обёртка:** одиночный `<img>` обёрнут; `<img>` внутри
  `<figure>` — не тронут.
- **Окно 7 дней:** комментарий-инвариант — `depth_hours` из core
  (1–12), 7 дней не превышается by construction.
- **`contentType`:** `<contentType>news_only</contentType>`
  (camelCase в XML).
- **Skip item без content:** пост с пустым `content` → item
  отсутствует, WARNING в лог.
- **Пустой фид:** валидный `<rss><channel>…</channel></rss>` без
  `<item>`, не 404.
- **Budget guard:**
  - items, суммарно превышающие 9.5МБ → contiguous prefix,
    хвост обрезан, WARNING в лог с `kept/total/bytes`;
  - **newest preserved:** при обрезке — самые новые посты в фиде
    (cut from tail);
  - **edge case single item > budget:** пустой `<rss>` без `<item>`
    + WARNING;
  - **cache invariant:** тот же набор items → тот же XML
    (два рендера подряд, byte-for-byte).

Итого ~18–20 integration.

### Unit

#### ContentProcessor:
  - разрешённые/запрещённые теги;
  - `em→i`, `strong→b`;
  - `<figure>`-обёртка (одиночный `<img>` / уже в `<figure>`);
  - `]]>`-escape;
  - пустой вход → пустой выход (сигнал для skip item).

#### Движок: DOMDocument

Реализация — на нативном `DOMDocument` (`ext-dom`, PHP 8.3). Внутри
`ContentProcessor` — **никаких WP-функций** (`wp_kses`, `wp_check_filetype`,
`apply_filters`, `do_action`): контракт «pure function, no WP side effects»,
unit-бутстрап — чистый PHPUnit (без WP). Вход уже прошёл
`HtmlSanitizer::sanitize(null)` → `wp_kses_post` в `Collector`/`ContentNormalizer`.

**Шесть ловушек DOMDocument — все обязательны, иначе баги:**

**Л1. UTF-8 — через XML-префикс, не `mb_convert_encoding`.**
`DOMDocument::loadHTML()` на PHP 8.3 без объявления кодировки режет
кириллицу. `mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8')`
deprecated с PHP 8.2 (warning). Обход — префикс `<?xml encoding="UTF-8">`:

```php
$dom  = new \DOMDocument( '1.0', 'UTF-8' );
$prev = libxml_use_internal_errors( true );
$dom->loadHTML(
    '<?xml encoding="UTF-8">' . $html,
    LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
);
libxml_clear_errors();
libxml_use_internal_errors( $prev );
```

PI **обязательно** удалить до `saveHTML()`, иначе утечёт в `content:encoded`:

```php
foreach ( iterator_to_array( $dom->childNodes ) as $node ) {
    if ( $node instanceof \DOMProcessingInstruction ) {
        $dom->removeChild( $node );
    }
}
```

`LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD` — оба обязательны: первый
убирает `<html><body>`, второй — `<!DOCTYPE>`.

**Л2. Live `NodeList` — snapshot через `iterator_to_array()` на каждом
`getElementsByTagName()`.**
`getElementsByTagName()` возвращает live `DOMNodeList`: при модификации
дерева список обновляется в момент итерации (пропуски, двойные удаления,
бесконечные циклы). Правило — **любая** итерация по `getElementsByTagName()`
с модификацией дерева: `iterator_to_array()`. Не только для `<img>`.

**Л3. `em → i`, `strong → b` — кастомный `rename_element`, не `str_ireplace`.**
В PHP DOM нет `renameNode()` (в отличие от Java DOM). Переименование —
create+attrs+move+replace:

```php
private function rename_element( \DOMDocument $dom, \DOMElement $old, string $tag ): \DOMElement {
    $new = $dom->createElement( $tag );
    foreach ( $old->attributes as $attr ) {
        $new->setAttribute( $attr->name, $attr->value );
    }
    while ( $old->firstChild ) {
        $new->appendChild( $old->firstChild );
    }
    $old->parentNode->replaceChild( $new, $old );
    return $new;
}
```

Порядок: сначала атрибуты, потом дети. **Не** `str_ireplace('<em>', '<i>', ...)`
до парсинга — ломается на `<em class="x">`, вложенности, `<em>` в тексте.

**Л4. `saveHTML()` — по `childNodes` корня, не весь документ.**
`saveHTML()` без аргументов сериализует документ целиком; с
`LIBXML_HTML_NOIMPLIED` обычно ок, но есть артефакты (`<br>` без закрытия,
entities). Надёжнее — обход:

```php
$out = '';
foreach ( iterator_to_array( $dom->childNodes ) as $node ) {
    $out .= $dom->saveHTML( $node );
}
```

**Л5. figure-wrap — проверка прямого родителя, идемпотентность.**

```php
foreach ( iterator_to_array( $dom->getElementsByTagName( 'img' ) ) as $img ) {
    $parent = $img->parentNode;
    if ( $parent instanceof \DOMElement && 'figure' === $parent->tagName ) {
        continue;
    }
    $figure = $dom->createElement( 'figure' );
    $parent->replaceChild( $figure, $img );
    $figure->appendChild( $img );
}
```

`parentNode->tagName === 'figure'` — прямой родитель, не «где-то выше».
Второй прогон видит `<img>` внутри `<figure>` и пропускает.

**Л6. Тесты — `canonicalize` + `assertSame`, не `assertStringContainsString`.**
`assertStringContainsString` нестабилен: порядок атрибутов, кавычки,
пробелы, `<br>` vs `<br/>`. Каноническое представление:

```php
private function canonicalize( string $html ): array {
    $dom = new \DOMDocument();
    $dom->loadHTML(
        '<?xml encoding="UTF-8">' . $html,
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    return $this->node_to_array( $dom->documentElement );
}

private function node_to_array( \DOMNode $node ): array {
    $result = [ 'tag' => $node->nodeName, 'attrs' => [], 'children' => [] ];
    if ( $node instanceof \DOMElement ) {
        $attrs = [];
        foreach ( $node->attributes as $attr ) {
            $attrs[ $attr->name ] = $attr->value;
        }
        ksort( $attrs );
        $result['attrs'] = $attrs;
    }
    foreach ( $node->childNodes as $child ) {
        $result['children'][] = $this->node_to_array( $child );
    }
    return $result;
}
```

`assertSame(canonicalize($expected), canonicalize($actual))`.
`assertStringContainsString` — только для presence-checks.

**Исключение — CDATA-escape.** `]]>`-escape идёт над сериализованной строкой;
canonicalize парсит HTML заново — `]]]]><![CDATA[>` в тексте превращается
в мусор или валит libxml. Тест — `assertSame` на raw string:

```php
$this->assertSame( 'foo ]]]]><![CDATA[> bar', $processor->process( 'foo ]]> bar' ) );
```

**Не унифицировать** с остальными тестами `ContentProcessor`.

**Порядок операций в `process()` — фиксирован:**

```text
1. trim($html) === '' → return ''
2. loadHTML + удаление PI
3. removeForbidden([script, style, iframe, form, input])     — cut with content
4. unwrap([div, span, h5, h6])                                — preserve content
5. unwrapEmptyFigure()                                        — figure без img/figcaption
6. renameElement([em → i, strong → b])
7. wrapImgInFigure()                                          — каждый img, если parent != figure
8. saveHTML($node) по childNodes корня → concat $out
9. str_replace(']]>', ']]]]><![CDATA[>', $out)
10. trim($out) === '' → return '' : return $out
```

Обоснования порядка:

- **3 → 4:** `<script>` внутри `<div>` при unwrap `<div>` всплывёт наверх.
  Режем до unwrap — меньше дерево для последующих операций.
- **5 → 7:** `unwrapEmptyFigure` проверяет «есть ли в figure `img` или
  `figcaption`». После 7 у каждой figure есть `img` — пустые уже не
  развернутся.
- **6 → 7:** к моменту сериализации все теги финальные. Обратный порядок
  даст тот же результат, но 6→7 чище.
- **9 после 8:** escape — над сериализованной строкой, не над деревом.

**Семантические уточнения:**

- **`div`/`span` — `unwrap`, не cut with content.** ADR п. 3 говорит
  «вырезать `div`, `span`» — уточнение: тег убрать, содержимое сохранить.
  Cut with content на WP-разметке (типичный `<div>` вокруг абзацев) снесёт
  текст статьи. `script/style/iframe/form/input` — cut with content
  (однозначно).
- **`script/style` + `form/input/iframe` — defense in depth.**
  `wp_kses_post` уже режет `script/style` с содержимым, но `form/input/iframe`
  оставляет inner. Cut-with-content в `ContentProcessor` — страховка на
  случай отключённого/пропущенного upstream-санитайза.
- **`h5`/`h6` — `unwrap`.** ADR разрешает `h1–h4`. Понижать до `h4` —
  семантическое преобразование, которого никто не просил. Дефолт — unwrap.
- **`<figure>` без `img`/`figcaption` — `unwrap`.** Бессмысленная
  конструкция. Если внутри есть `img` **или** `figcaption` — оставить
  (`figcaption` без `figure` невалиден).
- **`<a><figure><img></figure></a>` — принятое поведение.** HTML5-валидно
  (`<a>` — transparent content model, допускает flow content, `<figure>` —
  flow). Кликабельность сохраняется. Если Дзен отклонит — TD, не менять
  сейчас.
- **`figcaption` вне `figure` — `unwrap`.** `figcaption` без `figure`
  невалиден в HTML5. Отдельным шагом после `unwrap` figure без
  `img`/`figcaption`: `figcaption` с родителем-не-`figure` разворачивается,
  текст сохраняется. В `ContentProcessor` это
  `unwrap_orphan_figcaptions()` — шаг, которого нет в п.1–6 выше, но
  который прямо следует из валидности разметки.
- **Idempotency не гарантирована для входа с `]]>`.** Escape
  `]]]]><![CDATA[>` — валидная последовательность для CDATA-парсера,
  но **не** для HTML-парсера: повторный проход `process()` видит `<!`
  и трактует остаток как bogus comment. Это ограничение by design,
  не баг: `ContentProcessor` вызывается один раз на item, а
  `DzenRenderer` оборачивает результат в `<![CDATA[…]]>` и больше не
  парсит. Зафиксировано тестом
  `test_idempotency_does_not_hold_for_cdata_escape`. Если когда-нибудь
  понадобится повторный прогон — отдельный TD на «escape после
  финальной сериализации».
- **`process()` возвращает `string`, не `?string`.** Пустой результат —
  `''`, не `null`. Политика пропуска item — в `DzenRenderer::render_item()`,
  не в процессоре.

**Почему не альтернативы:**

- **Regex** — `<figure>`-wrap требует «найти `<img>` не внутри `<figure>`» —
  переменный lookbehind по вложенности, PCRE не поддерживает; эмуляция
  через стек — это уже парсер (хуже DOM). Регекс на HTML — гарантированные
  баги на `<img>` в атрибутах, комментариях, CDATA.
- **`WP_HTML_Tag_Processor`** (WP 6.2+) — лёгкий, но не реструктурирует
  DOM: не может обернуть `<img>` в `<figure>`, переименовать `<em>` в `<i>`.
- **HTML5-PHP** (`masterminds/html5`) — composer-зависимость. ADR-ARCH-0003
  уже отказался от внешних зависимостей для XML; тот же аргумент для HTML.
- **`Dom\HTMLDocument`** (PHP 8.4) — лучше по спеке, но среда — PHP 8.3.

#### EnclosureResolver:
  - featured есть → `url`/`type`/`length` корректны;
  - featured меньше 480 × 320 → `null`;
  - featured нет → `null` (без fallback, TD-05);
  - `filesize` из `wp_get_attachment_metadata`, fallback `0`;
  - static cache: два поста с одним `_thumbnail_id` → один
    `wp_get_attachment_metadata` вызов.

## Открытые вопросы

1. **`contentType` по умолчанию** — **ЗАКРЫТ 2026-10-21:** `news_only`
   (уводящая на сайт) — принятое значение по ответу Дзена. Бесшовное
   потребление (`news`) — TD, не v1.
2. **Валидатор Дзена** — ждать, как с Яндексом. Итерация после первого
   crawl: возможны ошибки формата, не видимые в спеке.

`dzenTitle`, `theme_tags`, `enclosure_fallback` — отложены (v1.1 / TD-05).

## План реализации (по чатам)

1. `ContentProcessor` + unit — отдельный чат.
2. `EnclosureResolver` + unit — отдельный чат.
3. `DzenRenderer` + unit + регистрация в `Plugin` / `Settings\Store` —
   отдельный чат.
4. Integration (`EndpointTest` для `/feed/dzen.xml`) — отдельный чат.
5. Деплой + обратная связь от Дзена.

## Ссылки

- Спека: https://dzen.ru/help/ru/export-content/export.html
- Единый RSS (seamless): https://dzen.ru/help/ru/news/seamless/rss.html
- Правила контента: https://dzen.ru/ru/requirements/content.html
- Закрытие Турбо: https://yandex.ru/support/turbo/ (условия, 02.06.2026)
