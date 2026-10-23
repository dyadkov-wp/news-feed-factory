# BACKLOG — News Feed Factory

Отложенные задачи и техдолг. Не roadmap — roadmap живёт в issues GitHub.
Здесь — то, что решили отложить осознанно.

## Tech Debt

### TD-01: Офлайн-база уязвимостей Composer

**Контекст.** `composer audit` ходит напрямую на `packagist.org/advisories`,
игнорируя настроенное зеркало (`repo.packagist`). В сетях без доступа к
`packagist.org` audit либо зависает с таймаутом, либо требует отключения.

**Что сделали сейчас.** Глобально:

```
composer config --global audit.block-insecure false
```

Это убирает блокировку установки при недоступности audit-API, но **не
даёт проверки уязвимостей**.

**Что делать потом.** Подключить офлайн-базу
[FriendsOfPHP/security-advisories](https://github.com/FriendsOfPHP/security-advisories):

```
git clone https://github.com/FriendsOfPHP/security-advisories \
    /opt/security-advisories
composer config audit.audit-db /opt/security-advisories
```

Обновление базы — периодически вручную или через cron, когда есть связь.

**Когда делать.** Перед первым публичным релизом или при появлении
production-зависимостей (не dev).

### TD-02: Защита unit-прогона от silent exit

**Контекст.** `exit` / `die` в autoloadable-классах убивает PHPUnit
тихо, с кодом 0. В PROC-0002 мы убрали `ABSPATH`-guard из `src/`,
но защита от **новых** подобных ситуаций — только через ревью и
ADR-PROC-0005.

**Что делать потом.** Добавить в `tests/bootstrap-unit.php` или в
`composer test:unit` проверку: если PHPUnit завершился с кодом 0, но
не напечатал `OK (N tests...)` — считать прогон неуспешным. Варианты:

- `register_shutdown_function` в bootstrap, пишущий маркер в конце.
- Обёртка над phpunit, парсящая вывод.
- PHPStan-расширение на запрет top-level `exit`/`die`.

**Когда делать.** При появлении CI или перед публичным релизом.

### TD-03: Записывающие методы MetaRepository

**Контекст.** Сейчас реализован только `get_by_post_ids()`. Нужны
`save()` (для метабокса), `delete_by_post_id()` (для хука
`deleted_post`), `delete_older_than()` (для cron-очистки,
ADR-ARCH-0001).

**Что делать потом.** Реализовать три метода с тестами (unit для
SQL-генерации, integration для реальной таблицы). Метабокс и cron
сейчас не реализованы — заведутся в PROC-0013+.

**Когда делать.** В цикле, где появятся Admin/PostFlags и
Install/Cron.

### TD-04: Выделить RssWriter

**Контекст.** `YandexRenderer` генерирует XML вручную — в ADR-ARCH-0003
это осознанный YAGNI. Второй RSS-рендер покажет, что общего в
generation, escaping и CDATA.

**Что делать потом.** Вынести `RssWriter` как класс, принимающий
channel + items, после появления второго RSS-рендера. Тогда видно,
что общего.

**Когда делать.** При появлении второго **RSS**-рендера. Google News
sitemap (ADR-ARCH-0004) — не RSS, триггером не является. Кандидаты:
Дзен, generic RSS для сторонних читалок, RSS для ВК.

### TD-05: Поиск картинки в content, если нет featured

**Контекст.** `ImageExtractor` берёт только featured image
(ADR-ARCH-0002). Если featured нет, но есть `<img>` в
`post_content` — картинка теряется.

**Что делать потом.** Расширить `ImageExtractor` правилами
извлечения из HTML и определения размеров. Для Дзена это скорее
всего обязательно.

**Когда делать.** Не блокирует Dzen v1 — v1 работает только с
featured (ADR-ARCH-0005). Триггер: жалоба Дзен-валидатора на
отсутствие обложки или продуктовое требование fallback.

### TD-06: Логирование ошибок Collector

**Контекст.** Сейчас ошибки сборщика идут в `error_log` через
`wp_trigger_error` (ADR-ARCH-0001). Настройки нет.

**Что делать потом.** Опция в `nwff_settings['log_level']` и
подключение к `WP_DEBUG_LOG` либо отдельный файл. Решить, что
именно логируем: пропуски, деградации, битые attachment.

**Когда делать.** Когда появятся реальные жалобы на тихую
деградацию фида.

### TD-07: Колонка created_at в nwff_feed_meta

**Контекст.** В схеме только `updated_at` (ADR-ARCH-0001). Для
аналитики «когда запись впервые попала в фид» — не хватает.

**Что делать потом.** `ALTER TABLE`, миграция по `nwff_db_version`,
правка `Schema::activate()` и `MetaRepository`.

**Когда делать.** Если понадобится аналитика или отладка «почему
старая запись выпала из фида».

### TD-08: Приоритет рубрик в YandexRenderer

**Контекст.** ADR-ARCH-0003: категория — первая из `terms`. Если
редакторы ждут «главную», нужно либо метаполе, либо настройка
порядка.

**Что делать потом.** Опция «главная рубрика» в метабоксе или
правило выбора по ID/порядку.

**Когда делать.** После обратной связи от контентщиков.

### TD-09: Медиа в YandexRenderer (media:group)

**Контекст.** ADR-ARCH-0003: отложено, Яндекс не требует, но
полезно для единообразия фидов.

**Что делать потом.** Добавить `media:group` с
`media:content`/`media:thumbnail` из `image_*` нормализованного
элемента.

**Когда делать.** TD-08 Yandex-specific. Dzen v1 (ADR-ARCH-0005)
не использует приоритет — берёт первую из `terms`. Когда TD-08
закроется — решить: общая настройка или per-renderer.

### TD-10: Yandex: pdalink и theme_tags

**Контекст.** ADR-ARCH-0003: `pdalink` (мобильная версия) и
`yandex:theme_tags` (JSON-файл тем) отложены — не нужны
большинству сайтов.

**Что делать потом.** Опция в настройках рендера + вывод элемента.
`theme_tags` требует внешний JSON — вне рамок плагина.

**Когда делать.** При появлении реальной потребности.

### TD-11: Кэш нормализованных элементов отдельно от XML

**Контекст.** Сейчас кэшируется готовый XML (ADR-ARCH-0001). При
появлении третьего рендера одна и та же нормализация будет
пересчитываться для каждого формата.

**Что делать потом.** Двухуровневый кэш: нормализованный массив
(общий) + XML (на формат). Решение зависит от того, сколько
форматов реально поддерживается одновременно.

**Когда делать.** Когда появится третий рендер с общими данными.

### TD-12: Plain-версия полного content

**Контекст.** Сейчас `content` — sanitized HTML, этого требует
`yandex:full-text`. Если появится рендер, которому нужен plain
полного контента (например, RSS для ВК с `first_paragraph` от
`content`, а не от `excerpt`) — нужна либо опция конфига
(`content_mode = 'html' | 'plain'`), либо второе поле
`content_plain` в нормализованном элементе.

**Что делать потом.** Расширить нормализацию и `$raw`.

**Когда делать.** Когда появится конкретный рендер с такой
потребностью.

### TD-13: Инвалидация кэша по префиксу на больших сайтах

**Контекст.** `TransientCache::delete_by_prefix()` выполняет
`DELETE FROM wp_options WHERE option_name LIKE '_transient_nwff_feed_%'`
на каждый `save_post`, `deleted_post`, `set_object_terms`. При 100+
сохранениях в час на новостном сайте — нагрузка на `wp_options`.
Решение принято осознанно в ADR-ARCH-0001 («сброс по префиксу дешевле,
чем вычисление затронутых ключей»), но порог деградации не измерен.

**Что делать потом.** Троттлить (не чаще раза в N секунд через
транзиент-замок) или батчить (накапливать ID и инвалидировать на
`shutdown`). Выбор зависит от замеров.

**Когда делать.** При появлении жалоб на медленное сохранение или
перед production-нагрузкой.

### TD-14: Вынос парсера таксономий в общий хелпер

**Контекст.** `parse_taxonomies()` дублируется в `YandexRenderer`
и `NewsSitemapRenderer` (15–20 строк). В ADR-ARCH-0006 зафиксирован
формат (`category:12,15|post_tag:100`), в ADR-ARCH-0003 и
ADR-ARCH-0004 — по экземпляру в каждом рендере.

**Что делать потом.** Вынести `TaxonomyParser` как отдельный класс
или статический хелпер. Один экземпляр кода, один набор тестов.

**Условия активации:**

- Появился третий рендер (Дзен), которому тоже нужен парсер, **ИЛИ**
- Найден баг в парсере, проявляющийся в двух местах одновременно
  (правка в двух местах = сигнал, что пора выносить).

**Когда делать.** Отдельный коммит `refactor:` без изменения
поведения, когда сработает одно из условий.

### TD-15: Sitemap index для >1000 URL

**Контекст.** Google News sitemap ограничен 1000 `<news:news>` на
файл. При превышении нужен `<sitemapindex>` с дочерними sitemap'ами
по 1000 URL. Сейчас `NewsSitemapRenderer` (ADR-ARCH-0004) отдаёт
один файл, `limit = 1000` жёстко, самые свежие 1000 попадают.

**Что делать потом.** Второй эндпоинт `/feed/google-news-index.xml`
с `<sitemapindex>`, дочерние `/feed/google-news-{N}.xml`. Правка
`get_collector_args()` и `render()`.

**Условия активации:**

- Реальный объём публикаций делает >1000 за `depth_hours`
  реалистичным (для окна 4–12 часов это ~85–250 постов/час; при
  текущих объёмах Облгазеты — **нереалистично**, зафиксировано в
  ADR-ARCH-0004), **ИЛИ**
- Search Console показывает, что часть свежих статей не индексируется
  через news sitemap (признак, что 1000 — узкое место).

**Когда делать.** При срабатывании одного из условий. Отдельный
цикл.

### TD-16: `<lastmod>` / `modified` в нормализованном элементе

**Контекст.** `<lastmod>` в sitemap опционален, но полезен — Google
может учитывать при определении свежести. Для его вывода нужен
`post_modified` в контракте `ContentNormalizer::normalize()`
(ADR-ARCH-0002). Сейчас `modified` в нормализованном элементе нет,
`NewsSitemapRenderer` (ADR-ARCH-0004) `<lastmod>` не выводит.

**Что делать потом.** Добавить `modified` в `$raw` и в
нормализованный элемент, вывести `<lastmod>` в sitemap через
`->format('c')`. Правка ADR-ARCH-0002 + тесты `ContentNormalizer`
+ фикстуры + правка `NewsSitemapRenderer`.

**Условия активации:**

- Google Search Console начнёт предупреждать об отсутствии
  `lastmod` (сейчас необязательно, но Google может ужесточить), **ИЛИ**
- Дзен-рендер потребует `modified` для своих целей.

**Когда делать.** При срабатывании одного из условий.

### TD-17: Флаг `include_content` в `normalization_config`

**Контекст.** `Collector` прогоняет `post_content` через
`HtmlSanitizer::sanitize()` для всех рендеров (ADR-ARCH-0002). Для
`NewsSitemapRenderer` (ADR-ARCH-0004) `content` в XML не попадает
— sanitize делается впустую. На окне 4–12 часов и 1000 постах это
доли секунды, но при росте объёмов станет заметно.

**Что делать потом.** Добавить в `normalization_config` флаг
`include_content: bool` (default `true` для обратной совместимости).
`Collector` при `false` пропускает `HtmlSanitizer::sanitize()`,
`ContentNormalizer` кладёт `content = ''`. Правка ADR-ARCH-0002 +
тесты `Collector` + флаг в конфиге Google-рендера.

**Условия активации:**

- CPU на sanitize станет измеримой проблемой на реальных объёмах
  (профилирование, а не догадка), **ИЛИ**
- Появится рендер, которому нужен plain `content` без sanitize
  (например, RSS для ВК с `first_paragraph` от полного контента,
  см. TD-12).

**Когда делать.** При срабатывании одного из условий.

### TD-19: Инвалидация транзиентов при изменении nwff_settings

**Контекст.** `TransientCache` инвалидируется только на `save_post`,
`deleted_post`, `set_object_terms` (ADR-ARCH-0001). Смена `nwff_settings`
через `update_option_nwff_settings` не сбрасывает транзиенты
`nwff_feed_*`. После изменения `depth_hours`, `cache_ttl`,
`include_taxonomies`/`exclude_taxonomies` рендера — старый XML
отдаётся до истечения `cache_ttl` (по умолчанию 5 минут, но может
быть увеличено).

**Симптом.** Пользователь меняет `depth_hours` 4 → 12, сохраняет,
открывает фид — видит старое окно 4h. Через `cache_ttl` минут —
правильное. Сбивает с толку при верификации изменений.

**Что не затронуто.** `feed_slug` — смена даёт новый URL, старые
транзиенты становятся сиротами (истекают естественно, не мешают).
`enabled` — `Endpoint::serve()` проверяет `is_enabled()` до чтения
кэша, выключенный рендер отдаёт `null` независимо от транзиента.

**Что делать потом.** В `Plugin::on_settings_updated()` (уже
подписан на `update_option_nwff_settings`, сейчас обрабатывает
только смену `feed_slug` и вызывает `flush_rewrite_rules()`)
добавить `TransientCache::delete_by_prefix()` при изменении любого
поля, влияющего на сборку или рендер: `core.depth_hours`,
`core.cache_ttl`, `renderers.*.include_taxonomies`,
`renderers.*.exclude_taxonomies`. Проще — инвалидировать при любом
изменении `nwff_settings` (`DELETE` по `wp_options` дёшев,
overhead одного `LIKE`-запроса пренебрежим при редкой записи опции).

**Условия активации:**

- Первая жалоба «поменял настройки — фид старый», **ИЛИ**
- При следующем касании `Plugin::on_settings_updated()` или
  `Settings\Page`, **ИЛИ**
- Первый фид Dzen в проде (ADR-ARCH-0005: транзиент до 9.5MB в
  `wp_options`, при отсутствии object cache — заметное чтение).

**Когда делать.** При срабатывании одного из условий. Отдельный
коммит `fix(cache): invalidate feed transients on settings change`.

### **TD-20:** Проверка размеров `<img>` в content под Дзен.
  Условие: Дзен пожалуется на картинки в тексте. Путь: батч-резолв
  через `WP_Query` + `update_meta_cache`, не N+1.

### TD-21: Dzen — `contentType=news` (бесшовное потребление)

**Контекст.** ADR-ARCH-0005 закрывает открытый вопрос №1 в пользу
`news_only` (уводящая на сайт). Бесшовное потребление (`news`) —
альтернатива, отложенная до продуктового решения редакции. Настройка
`content_type` в `get_settings_schema()` уже есть (значения:
`news_only` / `news` / `blogs_only`).

**Что делать потом.** Переключить default на `news` (или добавить
per-feed override), при необходимости — миграция существующих настроек.
Обновить ADR-ARCH-0005 (открытый вопрос №1) и тесты `DzenRenderer`.

**Когда делать.** Продуктовое решение редакции oblgazeta.ru или
ответ Дзена о рекомендациях для СМИ.

### TD-22: Dzen — unwrap `<a>` вокруг `<figure>`

**Контекст.** `ContentProcessor::wrapImgInFigure()` (ADR-ARCH-0005)
оборачивает одиночный `<img>` в `<figure>`, сохраняя прямой родитель.
Если `<img>` внутри `<a>`, результат — `<a><figure><img></figure></a>`.
HTML5-валидно (transparent content model), кликабельность сохраняется.
Решение (b) в ADR-ARCH-0005: оставить как есть до жалобы Дзена.

**Что делать потом.** В `wrapImgInFigure()`: если `parent` — `<a>` и
`parent->parentNode` — не `<figure>`, unwrap `<a>` перед обёрткой.
Тест: `test_img_inside_link_is_unwrapped`.

**Когда делать.** Жалоба Дзен-валидатора на `<a>`-обёртку вокруг
`<figure>`.

### TD-23: `content_filters` — регистрация не реализована

**Контекст.** ADR-ARCH-0002 (разделы «Нормализация контента»,
«Редакционные вырезы», «Точки расширения») описывает редакционные
вырезы `content_filters` и регистрацию через
`apply_filters('nwff_content_filters', ...)`. Ни `Collector`, ни
`ContentNormalizer` этот фильтр не вызывают — механизм не работает.

**Что делать потом.** Реализовать регистрацию через `apply_filters` +
применение вырезов в `ContentNormalizer` (позиция — до/после `sanitize()`
— уточнить в ADR), либо удалить описание из ADR-ARCH-0002.

**Когда делать.** Перед первым релизом 0.1.0.

### TD-24: `YandexRenderer` — тихий skip на невалидные items

**Контекст.** ADR-ARCH-0003 требует WARNING при пропуске item без
`title`/`link`/`published`. В коде (`src/Render/YandexRenderer.php:316-327`)
— три тихих `return` без лога. `NewsSitemapRenderer` логирует корректно.

**Что делать потом.** Добавить лог при каждом `return` в `render_item()`;
формат — консистентно с `NewsSitemapRenderer`.

**Когда делать.** Перед первым релизом 0.1.0.

### TD-25: `HtmlSanitizer::to_plain` — блочные теги за пределами `p`/`div`

**Контекст.** `to_plain()` превращает `<p>` и `<div>` в `\n\n`, остальные
теги удаляет без разделителя. ADR-ARCH-0002 перечисляет `<p>`, `<div>`
как блочные. Проверка реальной выдачи oblgazeta.ru (30 постов via REST
API, `excerpt`): наблюдаемые блочные — `h1`, `p`, `figure`, `figcaption`,
`div`. `h2`/`blockquote`/`ul`/`table` не встречаются.

Потенциальная проблема: `figure`/`figcaption` не дают `\n\n` — текст
`figcaption` может слипнуться со следующим `<p>`. Сейчас латентно:
`excerpt` не используется в production-фидах (Yandex item — только
`title/link/pubDate`, Google — `title`).

**Что делать потом.** Добавить `h1-h6`, `blockquote`, `ul/ol/li`,
`table`, `figure`, `figcaption` в блок-разделители (или обновить
ADR-ARCH-0002 с явным решением «figure/figcaption — не блочные»).
Тест: `<figure><figcaption>cap</figcaption></figure><p>text</p>` →
`cap\n\ntext`.

**Когда делать.** Триггер: `excerpt` начинает использоваться в
production-рендере, ИЛИ жалоба на слипшиеся абзацы, ИЛИ появление
`h2`/`blockquote`/`ul`/`table` в реальных excerpt.

### TD-26: Перепроверить README.md, CHANGELOG.md, readme.txt перед релизом

**Контекст.** Аудит перед 0.1.0:
- `README.md` — «Google ... are planned», но Google уже реализован
  (ADR-ARCH-0004 закрыт, в проде).
- `CHANGELOG.md` — на русском (ADR-PROC-0002 требует английский), не
  отражает Google-рендер.
- `readme.txt` (WordPress.org) — теги/описание обещают Dzen и generic
  RSS, которых нет в поставке. Риск отклонения на review.

**Что делать потом.** Пройтись по трём файлам, привести к фактическому
состоянию: `README.md` — статус Google/Dzen; `CHANGELOG.md` — перевод
на английский + запись про Google; `readme.txt` — синхронизировать
список рендеров и теги с реальностью.

**Когда делать.** Перед первым публичным релизом 0.1.0.

### TD-27: `NewsSitemapRenderer` — удалить `settings_reader` fallback

**Контекст.** ADR-ARCH-0006 фиксирует контракт: `$args['settings']` —
секция рендера; рендер не читает `get_option('nwff_settings')` в
production-коде. `NewsSitemapRenderer` сохраняет fallback на
`read_settings()` (с инжектируемым `settings_reader`) — используется
только unit-тестами, которые передают `render( $items, array() )` без
`$args['settings']`.

**Что делать потом.** Перевести все unit-тесты Google на передачу
`$args['settings']` (секции), удалить `settings_reader` из конструктора
и `read_settings()` из класса. Конструктор станет без параметров
(или только `$logger`).

**Когда делать.** При следующем касании `NewsSitemapRenderer` или
перед добавлением Dzen-рендера (чтобы не размножать паттерн fallback).

### TD-28: Единая абстракция логирования (`Logger`) вместо `?\Closure` + `wp_trigger_error`

**Проблема.** Логирование WARNING'ов реализовано в `src/` тремя
способами, четвёртый на подходе:

- `Render/Google/NewsSitemapRenderer` — инъекция `?\Closure $logger`,
  fallback `wp_trigger_error( self::class . '::render', ... )` под
  `function_exists` guard;
- `Install/Schema::activate()` — прямой `wp_trigger_error( __METHOD__, ... )`
  **без** `function_exists` guard (на WP < 6.4 — fatal);
- `Render/Dzen/EnclosureResolver` — копия паттерна `NewsSitemapRenderer`
  (введена этим циклом);
- `Render/Dzen/DzenRenderer` (следующий чат) — понадобится четвёртый
  экземпляр для «skip item без content».

Три места с одинаковым `if ( function_exists ) { wp_trigger_error }`
переживаемо. `DzenRenderer` станет четвёртым — сигнал к извлечению.

**Решение.** Извлечь `NWFF\Support\Logger` (интерфейс с методом
`warning( string $message ): void`) + три реализации:
- `WpTriggerLogger` — `wp_trigger_error( $caller, $message, E_USER_WARNING )`;
- `NullLogger` — no-op;
- `CallableLogger` — обёртка над `\Closure` для тестов.

Все renderer'ы и `Schema` принимают `Logger` через конструктор. При
отсутствии — `NullLogger` по умолчанию (не `WpTriggerLogger`, чтобы
поведение совпадало с текущим «нет логгера → тишина в тестах»); в
`Plugin` — явная передача `WpTriggerLogger`.

**Что не входит.** Унификация сообщений и формата лога — только
абстракция транспорта. Логирование в `Collector`/`ContentNormalizer`
не трогаем (там пока нет WARNING'ов).

**Когда.** Отдельный `refactor(support)` **после** `DzenRenderer`.
Не в этом цикле: `EnclosureResolver` уже приведён к паттерну
`NewsSitemapRenderer` по факту, до `DzenRenderer` дублирования в двух
местах достаточно.

**Риски.** `Schema::activate()` живёт в activation hook — до
`function_exists( 'wp_trigger_error' )` там вообще не должно быть
(это уже баг WP < 6.4, но вне этого TD). Проверить `function_exists`
независимо.

### TD-29: `Settings\Page` — поддержка `select` / `radio` и UI-выбор `content_type` для Dzen

**Проблема.** В схеме `DzenRenderer::get_settings_schema()` поле
`content_type` объявлено с `'type' => 'text'`: пользователь видит
свободное поле ввода и `description`, допустимые значения проверяются
только в рендере (`resolve_content_type()`, тихий откат на `news_only`).
Это работает, но UX плохой — легко опечататься.

**Что делать.** Добавить в `Settings\Page::render_field()` (`switch`
по `$field['type']`) поддержку `'select'` (и, при желании, `'radio'`).
Формат поля:

```php
'content_type' => array(
    'type'    => 'select',
    'label'   => 'Content type',
    'default' => 'news_only',
    'options' => array(
        'news_only'  => 'news_only',
        'news'       => 'news',
        'blogs_only' => 'blogs_only',
    ),
),
```

Перевести `DzenRenderer::get_settings_schema()['content_type']` на
`'select'`. Валидацию `resolve_content_type()` оставить как defense in
depth (значение всё равно придёт из БД, теоретически может быть
испорчено).

**Объём.** `Settings\Page` — новая ветка `case 'select'` (~10 строк),
юнит-тест на рендер `<select>` с `<option>`, ADR-0006 §Форма настроек —
дополнить упоминанием `select`. Рендерер не трогаем.

**Когда.** После `EndpointTest` для Dzen, до первого релиза 0.1.0.

### TD-30: Рефакторинг endpoint-тестов — разбиение по рендерерам, общие хелперы, testable-404

**Проблема.** `tests/integration/Feed/EndpointTest.php` покрывает два
рендерера (yandex, google-news) в одном файле с общим `setUp()`.
Docblock файла врёт: «against … both renderers». Добавление Dzen
(третий рендерер, своя схема — `<contentType>` внутри `<item>`,
`<guid isPermaLink="true">`, `<enclosure>`) в этот же файл размывает
принадлежность теста. По той же причине Dzen заведён отдельным файлом
`DzenEndpointTest.php`, и хелперы `make_post()` / `parse()` (25 строк)
продублированы — это осознанный техдолг на время цикла ADR-0005.

Отдельно: `Plugin::handle_feed_request()` (`src/Plugin.php:215`) на
disabled/unknown slug делает `status_header(404); nocache_headers();
exit;`. Путь `exit` в `WP_UnitTestCase` не тестируется (PHPUnit
обрывается). Сейчас контракт «404 на disabled/unknown» покрыт только
косвенно — через `Endpoint::serve() === null` (unit-уровень). HTTP-путь
404 — correct-by-construction, без теста.

**Что делать.**

1. Разбить `EndpointTest.php`:
   - `YandexEndpointTest.php` — только yandex-специфичные ассерты;
   - `GoogleNewsEndpointTest.php` — google-news-специфичные;
   - `DzenEndpointTest.php` (из цикла ADR-0005) остаётся отдельным.
2. Вынести общее в `tests/integration/Feed/AbstractFeedEndpointTest.php`
   (abstract class предпочтительнее trait — общие `setUp()` и
   `$this->endpoint`): `make_post( int $age_seconds ): int`,
   `parse( string $xml ): SimpleXMLElement`, `make_endpoint(): Endpoint`,
   базовый `$settings` без рендерер-специфичных ключей. Наследники
   добавляют свои рендерер-ключи и вызывают `parent::setUp()`.
3. Testable-404: извлечь из `handle_feed_request()` сборку ответа в
   `private static function build_feed_response( string $slug ): ?array`
   (или `public static`, если нужен прямой тест). В
   `handle_feed_request()` остаются только `header()` / `echo` / `exit`.
   Тест: `assertNull( Plugin::build_feed_response( 'dzen' ) )` при
   disabled + `assertNull( … 'unknown' )`.

**Объём.** `tests/integration/Feed/` — 1 новый файл (abstract class),
2 переименования/разбиения (~600 строк механически), `DzenEndpointTest`
теряет 25 строк хелперов. `src/Plugin.php` — `handle_feed_request`
разделяется на 2 метода (~10 строк). Тесты на 200/404 не дублируются
между тремя файлами.

**Что не входит.** Правки `Endpoint::serve()` — контракт не меняется.
Правки рендереров — не трогаем. Параллелизация прогона
(`@group slow` / `parallel-lint`) — отдельная задача.

**Когда.** После приёмки ADR-ARCH-0005 (Dzen renderer → Accepted),
до TD-29 (`Settings\Page` select). Приёмка ADR-0005 фиксирует
текущее состояние (DzenEndpointTest с дублированными хелперами) как
Green; TD-30 устраняет долг следующим refactor-циклом.

**Риски.** При разбиении `EndpointTest.php` есть шанс вытащить
скрытые зависимости между yandex- и google-news-кейсами (общий
`$this->settings` сейчас содержит оба рендерера; после разбиения
каждый файл должен нести только свой). Если какой-то ассерт
сломается — это баг теста, не кода; фиксировать отдельным
`fix(test)`-коммитом, не подгонять под зелёный.
