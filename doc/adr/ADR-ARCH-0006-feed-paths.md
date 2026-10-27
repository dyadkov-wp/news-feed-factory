# ADR-ARCH-0006: Настраиваемые пути фидов (feed paths)

**Статус:** Proposed
**Создано:** 2026-10-27
**Обновлено:** 2026-10-27

## Контекст

URL фида = `home_url( '/' . feed_slug . '/' . renderer_slug . '.xml' )`.
`feed_slug` — core-настройка (по умолчанию `feed`), slug рендерера — константа
класса (`SLUG` в `DzenRenderer`/`YandexRenderer`, литерал `get_slug()` в
`NewsSitemapRenderer`). Одно правило rewrite (`Plugin::register_rewrite_rules()`):
`^<feed_slug>/([^/]+)\.xml/?$` → `index.php?nwff_feed_slug=$matches[1]`.

Проблема — операционная. При изменении схемы фида (например, новый
`content:encoded` с figure/figcaption whitelist) Дзен проверяет **новый URL**
три дня. Обход: отдать новый фид по **старому** URL, который Дзен уже доверяет.
Но старый URL жёстко привязан к slug рендерера (`/feed/dzen.xml`) и `feed_slug`.
Сменить slug — сломать URL, сменить `feed_slug` — сломать URL **всех** рендереров.

На практике oblgazeta использует нестандартный путь `/feed/zen` (без `.xml`),
унаследованный от старого плагина. Плагин должен уметь отдавать фид по
произвольному пути, не ломая дефолтный.

## Решение

**1. Per-renderer настройка `feed_path`.**

Каждый рендерер объявляет поле в `get_settings_schema()`:

```php
'feed_path' => array(
    'type'        => 'text',
    'label'       => 'Feed path',
    'default'     => '',
    'description' => 'Override. Empty = feed/<slug>.xml. Example: feed/zen.',
),
```

Пустое значение = дефолт. Непустое — полный относительный путь
(`feed/zen`, `feeds/dzen/v2`), без требования `.xml`.

**2. Pure-контракт в `RendererInterface`.**

```php
public function get_default_feed_path( string $feed_slug ): string;
```

Возвращает `<feed_slug>/<renderer_slug>.xml`. Без WP-функций (правило 8),
unit-тестируется.

**3. `Store` — единственная точка разрешения пути.**

- `feed_path_for( string $renderer_slug ): string` — сохранённый override
  (trim `/`), либо `''`.
- `resolve_feed_path( string $renderer_slug ): string` — override, либо
  `$renderer->get_default_feed_path( $this->feed_slug() )`.

**4. Sanitize.**

`[a-z0-9_\-/]` (ASCII, без `..`, `?`, `#`, `%`, пробелов, кириллицы).
Ведущие/хвостовые `/` тримятся. Невалидное значение → `''` (fallback на дефолт),
не ошибка сохранения.

**5. Регистрация rewrite.**

`Plugin::register_rewrite_rules()` регистрирует **оба** пути одновременно:

- generic `^<feed_slug>/([^/]+)\.xml/?$` (как раньше, backward compat);
- для каждого рендерера с непустым override — `^<escaped_override>/?$`.

`preg_quote` без делимитера: `extra_rules_top` хранит голый regex, WP применяет
делимитер сам (`WP::parse_request()`). WPCS-предупреждение
`PregQuoteDelimiter.Missing` подавлено inline с обоснованием.

**6. Flush.**

`Plugin::on_settings_updated()` вызывает `flush_rewrite_rules()`, если
изменился `feed_slug` **или** любой `feed_path` (сравнение по объединению
slug'ов старого и нового значений).

**7. UI.**

Поле `feed_path` — обычный `text`. Placeholder = `get_default_feed_path(feed_slug)`.
Под полем — подсказка `Resolved URL: <code>home_url('/' . resolve_feed_path(slug))</code>`.
Для остальных полей подсказка не рендерится.

## Последствия

**Плюсы:**

- Смена схемы фида не требует смены URL — Дзен не уходит в трёхдневный карантин.
- Тестовый канал можно нацелить на отдельный путь (`feed/dzen-test`) без
  второго плагина.
- Единый механизм для Dzen / Yandex / Google News sitemap — не только Дзен.
- Дефолтный путь всегда работает: выключение override не ломает существующие
  каналы.

**Минусы / ограничения:**

- **Один override на рендерер.** Алиасы (два пути одновременно) не
  поддерживаются. Если понадобятся — отдельное решение (textarea + парсинг).
- **Смена `feed_slug` по-прежнему меняет дефолтные URL всех рендереров**, у
  которых override пуст. Override защищает только конкретный рендерер.
- **`preg_quote` без делимитера** — WPCS-warning, подавлен inline. При
  обновлении WPCS правило может переименоваться — перепроверить.
- **Placeholder не влияет на сохранение.** Если пользователь не трогает поле,
  сохраняется пустая строка (дефолт), а не placeholder.

## Открытые вопросы

1. **Алиасы.** Стоит ли поддержать N путей на рендерер (миграция «оба URL
   какое-то время»)? Сейчас — нет. Триггер — если Дзен-валидатор потребует
   сохранять старый URL после переключения.
2. **Валидация кириллицы.** `sanitize_feed_path` режет всё, кроме ASCII.
   Если понадобится URL вида `/лента/дзен` — потребуется percent-encode
   (`rawurlencode` по сегментам) + `urldecode` при матчинге. Сейчас — YAGNI.
3. **End-to-end тест.** `go_to('/feed/zen')` + `is_feed()` — не написан
   (проверено `register_rewrite_rules` + `get_option('rewrite_rules')`,
   end-to-end отложен).
4. **`Page::render_field` — `home_url()`** в подсказке. Если сайт
   переедет на другой домен — подсказка обновится автоматически
   (`home_url` динамический). Проблем нет, но фиксируем для аудита.
