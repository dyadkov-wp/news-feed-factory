# ADR-ARCH-0006: Настройки и конфигурация рендеров

**Статус:** Proposed
**Создано:** 2026-10-14
**Обновлено:** 2026-10-14

## Контекст

ADR-ARCH-0001 описал ядро и оставил интерфейс рендера тонким:
`get_slug`, `get_content_type`, `get_normalization_config`, `render`.
Этого достаточно, чтобы рендерить готовые данные, но не чтобы
управлять тем, **какие посты попадают в рендер** и **включён ли он
вообще**.

Симптомы на текущем коде:

- `Endpoint::build_args()` хардкодит `depth_hours = 4`, `limit = 0` и
  пустые `taxonomy_include` / `taxonomy_exclude`. Рендер не может
  сказать «мне только рубрика 12, глубина 8 часов».
- `Endpoint::serve()` не проверяет, включён ли рендер. Сейчас это
  незаметно (Yandex единственный), но при добавлении Google и Дзена
  понадобится.
- `GenreResolver` принимает `$settings`, но `Endpoint` передаёт пустой
  массив — путь доставки настроек до рендера не проложен.
- `Plugin::feed_slug()` читает `nwff_settings['feed_slug']`, но опции
  ещё никто не сохраняет.

Дополнительное требование: **добавление нового рендера не должно
затрагивать ядро**, а **отключение любого рендера не должно ломать
общие механизмы** (кэш, схему БД, эндпоинты других рендеров).

## Решение

### Рендер владеет своим конфигом

`RendererInterface` расширяется тремя методами:

```php
interface RendererInterface {
    // Существующие.
    public function get_slug(): string;
    public function get_content_type(): string;
    public function get_normalization_config(): array;
    public function render( array $items, array $args ): string;

    // Новые.
    public function get_settings_schema(): array;
    public function is_enabled( array $settings ): bool;
    public function get_collector_args( array $settings ): array;
}
```

Каждый рендер декларирует себя целиком: какие поля показывать,
включён ли он, какие посты ему нужны. Ядро не знает о конкретных
рендерах; оно только передаёт им `$settings` и получает ответы.

### Контракт `$args` в `render()`

`Endpoint::serve()` формирует `$args` и передаёт в
`RendererInterface::render( $items, $args )`. Гарантированные ключи:

- **`channel`** — `title`, `link`, `description`, `language`
  (из `Endpoint::channel()`; `language` — ISO 639-1, регион отброшен,
  fallback ru при пустом).
- **`settings`** — **секция рендера** `nwff_settings['renderers'][ slug ]`,
  не полный массив. Рендер **не должен** читать `get_option('nwff_settings')`
  напрямую в production-коде.
  Допустимое исключение: инжектируемый провайдер (`settings_reader` в
  конструкторе `NewsSitemapRenderer`) как fallback для unit-тестов, где
  `$args['settings']` не передаётся. В production `Endpoint::serve()` всегда
  передаёт секцию через `$args`; fallback не задействуется.
  Кандидат в TD: удалить fallback (и провайдер) после перевода unit-тестов
  на `$args['settings']`.
- **`normalization_config`** — из `get_normalization_config()` рендера
  (кладётся в `$args` до вызова `render()`, используется
  `collect_and_normalize()`, не рендером напрямую).

**Почему.** До этого ADR наблюдалась асимметрия: `YandexRenderer`
читал `$args['settings']` (секцию), `NewsSitemapRenderer` —
`get_option()` напрямую через `read_settings()` (полный массив). Формы
разные → при передаче секции Google терял `news_publication_name` и
`news_language` (регрессия, поймана при синхронизации с Dzen-контрактом).
При добавлении третьего рендера (Dzen) контракт фиксируется здесь,
чтобы не размножать паттерн.

**Контракт закреплён тестом, не только текстом.** Тест
`NewsSitemapRendererTest::test_render_uses_renderer_section_from_args_settings`
проверяет, что `$args['settings']` — секция рендера и её значения
доходят до XML. Любой новый рендер обязан иметь аналогичный тест.
Unit-тесты рендера **всегда** передают `$args['settings']` явно (не
`array()`, не через fallback): иначе правка контракта не видна.

### Форма настроек

Одна опция `nwff_settings`, разделённая на два уровня:

```php
[
    'core' => [
        'feed_slug'   => 'feed',
        'depth_hours' => 4,
        'cache_ttl'   => 5,
    ],
    'renderers' => [
        'yandex' => [
            'enabled'            => false,
            'tag_interview'      => 0,
            'tag_article'        => 0,
            'tag_lenta'          => 0,
            'by_length'          => true,
            'include_taxonomies' => '',
            'exclude_taxonomies' => '',
        ],
    ],
]
```

**`core`** — общие параметры. Задаются один раз для всех рендеров.
Override на уровне рендера не вводится: общий дефолт проще и
покрывает текущие потребности. Если рендеру понадобится своё
значение — отдельное решение.

**`renderers`** — по секции на рендер. Ключ — `get_slug()`. Один
рендер физически не может перезаписать ключ другого.

### `get_settings_schema(): array`

Возвращает плоскую карту «ключ поля → описание»:

```php
public function get_settings_schema(): array {
    return array(
        'enabled' => array(
            'type'    => 'checkbox',
            'label'   => 'Enable Yandex feed',
            'default' => false,
        ),
        'tag_interview' => array(
            'type'    => 'number',
            'label'   => 'Interview tag ID',
            'default' => 0,
            'min'     => 0,
        ),
        'include_taxonomies' => array(
            'type'        => 'text',
            'label'       => 'Include taxonomies',
            'default'     => '',
            'description' => 'Format: category:12,15|post_tag:100.',
        ),
    );
}
```

**Имена ключей.** `include_taxonomies` / `exclude_taxonomies` — не
`include_terms`. Ключ `include_terms` уже занят в
`get_normalization_config()` (ADR-ARCH-0002) и означает другое:
булев флаг «тянуть ли terms из WP в нормализованный элемент». Два
разных смысла под одним именем привели бы к путанице в форме
настроек: пользователь видит поле «Include terms» и не понимает,
почему оно про фильтр выборки, а не про состав элемента.

Типы полей на MVP:

| Тип | Санитайз |
| --- | --- |
| `checkbox` | `(bool)` |
| `number` | `(int)` + границы `min` / `max` |
| `text` | `sanitize_text_field()` |

Другие типы (`textarea`, `select`) добавляются по мере
необходимости.

### `is_enabled( array $settings ): bool`

Рендер сам смотрит в свой конфиг:

```php
public function is_enabled( array $settings ): bool {
    $renderer = $settings['renderers'][ $this->get_slug() ] ?? array();
    return ! empty( $renderer['enabled'] );
}
```

`Endpoint::serve()` вызывает метод и возвращает `null`, если рендер
выключен. Дальше `Plugin::handle_feed_request()` ставит 404. Ядро
(`Collector`, `ContentNormalizer`, `TransientCache`) не участвует.

**Отключение рендера не меняет:**

- префикс кэша (`nwff_feed_` — общий);
- схему таблицы `nwff_feed_meta`;
- эндпоинты других рендеров;
- содержимое `nwff_settings['core']`.

Выключение Yandex не влияет на будущий Google; удаление Yandex из
`Plugin::renderers()` оставляет `serve()` возвращать `null` для
неизвестного slug без ошибки.

### `get_collector_args( array $settings ): array`

Рендер объявляет, какие посты ему нужны:

```php
public function get_collector_args( array $settings ): array {
    $core     = $settings['core'] ?? array();
    $renderer = $settings['renderers'][ $this->get_slug() ] ?? array();

    return array(
        'depth_hours'      => (int) ( $core['depth_hours'] ?? 4 ),
        'limit'            => 0,
        'taxonomy_include' => $this->parse_taxonomies( $renderer['include_taxonomies'] ?? '' ),
        'taxonomy_exclude' => $this->parse_taxonomies( $renderer['exclude_taxonomies'] ?? '' ),
    );
}
```

**Разделение зон.** `get_collector_args()` отвечает только за «какие
посты»: глубину, лимит, фильтры. `get_normalization_config()` — за
«как их готовить». Метод не возвращает `normalization_config` и не
вызывает свой же другой метод: конфиг нормализации вкладывает
`Endpoint`.

Парсер возвращает строго `array<string, int[]>`. Значения
принимаются только если строка состоит из цифр (`ctype_digit`) и
после приведения к `int` даёт `> 0`. Всё остальное (`"abc"`,
`"-1"`, `"0"`, `"1.5"`, `" 12 "`) отбрасывается молча. Таксономия,
у которой после фильтрации не осталось ID, не попадает в результат.
Пустая строка на входе → `[]`.

**Формат `include_taxonomies` / `exclude_taxonomies`.** Плоский
текст:

- `12,15` → `[ 'category' => [ 12, 15 ] ]` (без префикса —
  `category`).
- `category:12,15|post_tag:100` → обе таксономии.
- Пустая строка → `[]`.

Разделители: `|` между таксономиями, `:` после имени, `,` между
ID. Парсер — 15–20 строк, никаких AJAX-деревьев.

**Рендеры изолированы.** Yandex фильтрует по своим рубрикам, Google
будет по своим. `Endpoint` не знает, что именно вернул рендер, —
только передаёт результат в `Collector`.

### `Endpoint`

`serve()` получает `$settings` вторым параметром:

```php
public function serve( string $slug, array $settings ): ?array {
    if ( ! isset( $this->renderers[ $slug ] ) ) {
        return null;
    }

    $renderer = $this->renderers[ $slug ];
    if ( ! $renderer->is_enabled( $settings ) ) {
        return null;
    }

    $args                         = $renderer->get_collector_args( $settings );
    $args['normalization_config'] = $renderer->get_normalization_config();

    // остальное — как было
}
```

`Endpoint` больше не строит args сам. `build_args()` удаляется.

`cache_key()` не меняется: `$args` уже содержат слаг, глубину, лимит
и таксономии. У разных рендеров — разные ключи.

### `Settings\Store`

Владеет списком рендеров и настройками. Читает опцию, мёржит с
дефолтами из схем, санитайзит вход.

```php
namespace NWFF\Settings;

final class Store {
    /**
     * Renderer map keyed by slug.
     *
     * @var array<string, \NWFF\Render\RendererInterface>
     */
    private array $renderers;

    /**
     * @param array<string, \NWFF\Render\RendererInterface> $renderers Renderer map.
     */
    public function __construct( array $renderers ) {
        $this->renderers = $renderers;
    }

    /**
     * @return array<string, \NWFF\Render\RendererInterface>
     */
    public function renderers(): array;

    public function get(): array;
    public function sanitize( $input ): array;
    public function feed_slug(): string;
}
```

**Единственный владелец списка рендеров.** `Plugin::renderers()`
делегирует в `Store::renderers()`. Никто больше не создаёт инстансы
рендеров — иначе на запрос их окажется три (Store, Page,
Endpoint). Список создаётся один раз в `Plugin::make_renderers()`.

**Где живёт.** `Store` инстанцируется в `Plugin::store()` —
приватный статический метод с ленивым кэшем:

```php
private static ?Store $store = null;

/**
 * Build the renderer map. Private: instances are owned by Store.
 *
 * @return array<string, \NWFF\Render\RendererInterface>
 */
private static function make_renderers(): array {
    return array(
        'yandex' => new YandexRenderer( new GenreResolver(), new Truncator() ),
    );
}

private static function store(): Store {
    if ( null === self::$store ) {
        self::$store = new Store( self::make_renderers() );
    }
    return self::$store;
}

/**
 * @return array<string, \NWFF\Render\RendererInterface>
 */
public static function renderers(): array {
    return self::store()->renderers();
}
```

Один инстанс `Store` и один набор рендеров на запрос. Все точки
чтения настроек и списка рендеров идут через `Store`:

- `register_rewrite_rules()` — `self::store()->feed_slug()`.
- `handle_feed_request()` — `self::store()->get()` для
  `Endpoint::serve()`.
- `make_endpoint()` — `self::renderers()`, `self::store()->get()`.
- `Settings\Page` — конструктором получает `self::store()`.
- `on_save_post`, `on_deleted_post`, `on_set_object_terms` — не
  читают `Store` (инвалидация не зависит от настроек).
- `Plugin::on_settings_updated( $old, $new )` — подписан на
  `update_option_nwff_settings`, вызывает `flush_rewrite_rules()` при
  смене `feed_slug`.

**`Plugin::feed_slug()` переезжает в `Store::feed_slug()`.**

```php
public function feed_slug(): string {
    $settings = $this->get();
    $slug     = $settings['core']['feed_slug'] ?? '';

    if ( ! is_string( $slug ) || '' === $slug ) {
        return 'feed';
    }

    return $slug;
}
```

`Plugin::feed_slug()` удаляется. `register_rewrite_rules()` вызывает
`self::store()->feed_slug()`.

Кэш `Store::$store` не инвалидируется вручную: опция `nwff_settings`
меняется только через `Settings\Page`, а страница настроек в WP —
отдельный запрос (POST → redirect → GET). В GET-запросе после
redirect создаётся новый инстанс плагина, `Store` читает свежую
опцию.

`get()`:

1. Читает `nwff_settings` из опций.
2. Для каждого рендера берёт `get_settings_schema()`.
3. Мёржит сохранённые значения с дефолтами из схем.
4. Для `core` — мёржит с хардкоженными дефолтами
   (`feed_slug=feed`, `depth_hours=4`, `cache_ttl=5`).
5. Неизвестные ключи, уже лежащие в опции, не удаляются из
   возвращаемого массива — forward compat при downgrade. `get()` —
   только чтение, в опцию ничего не пишет. Программная запись
   (WP-CLI, миграция) эти ключи сохраняет; форма настроек — нет,
   её `sanitize()` их отбросит.

`sanitize( $input )`:

1. Проверяет `$input['core']['feed_slug']` → `sanitize_title()`.
2. Итерирует рендеры по схемам, чистит поля по типу.
3. Отбрасывает поля, которых нет в схеме (защита от подсунутых
   ключей через POST).

`sanitize()` — **единственная точка записи**. Белый список по `core`
плюс `get_settings_schema()` каждого рендера. Всё, чего нет в схемах,
отбрасывается. Mass-assignment через POST невозможен: чтобы подсунуть
ключ, нужно уже иметь `manage_options` — а тогда проще поменять код
плагина.

### `Settings\Page`

Регистрирует страницу `Настройки → News Feed Factory`.

```php
namespace NWFF\Settings;

final class Page {
    private Store $store;

    public function __construct( Store $store ) {
        $this->store = $store;
    }

    public function register(): void;
}
```

**Конструктор принимает `Store`, не список рендеров.** Список берётся
через `$this->store->renderers()` — единственный источник правды
(см. выше). Инстанцируется в `Plugin::init()`:

```php
add_action(
    'admin_menu',
    static function (): void {
        ( new Settings\Page( self::store() ) )->register();
    }
);
```

Метод `register()`:

- `add_options_page()` — одна страница.
- `register_setting('nwff_settings_group', 'nwff_settings',
  ['sanitize_callback' => [$this->store, 'sanitize']])`.
- Секция `nwff_core` — три общих поля.
- Секция на каждый рендер из `$this->store->renderers()`, заголовок —
  человекочитаемое имя (пока `get_slug()`).
- Рендер полей — по схеме: `type` решает HTML.

**Отключённый рендер.** Секция всё равно видна — иначе пользователь
не сможет его включить. Поля жанров/фильтров при этом можно визуально
приглушить (класс на `<fieldset disabled>`), но это необязательное
улучшение.

**Смена `feed_slug`.** При сохранении опции `Settings\Page` ничего
про rewrite не знает. Условный сброс — в `Plugin`: обработчик
`update_option_nwff_settings` сравнивает старое и новое значение
`feed_slug` и вызывает `flush_rewrite_rules()` только при изменении.
Сравнение старое/новое — забота WP, хук получает оба аргумента.

Первичная установка опции (при активации) — ответственность
`Plugin::activate()`, там flush уже есть. `add_option_*` не
используется.

### Фильтр `nwff_settings_fields`

Остаётся как точка расширения **только для сторонних плагинов**,
которые хотят добавить одно-два поля на страницу, не реализуя
`RendererInterface` и не владея рендером.

**Рендеры этот фильтр не используют.** У них есть
`get_settings_schema()`, который вызывается `Settings\Page` напрямую
и даёт типизированные поля с санитайзом. Если рендер начнёт
пользоваться фильтром, поле окажется вне секции рендера, без
санитайза по типу и с неясным жизненным циклом.

Правило: **фильтр — для плагинов, интерфейс — для рендеров**.
Смешивать нельзя.

### Поток данных

```
1. Запрос /feed/yandex.xml
2. Plugin::handle_feed_request()
   → Settings\Store::get() → $settings
   → Endpoint::serve('yandex', $settings)
3. Endpoint: renderer = renderers['yandex']
   → is_enabled($settings)? нет → null → 404
   → get_collector_args($settings) → $args
   → $args['normalization_config'] = get_normalization_config()
4. cache_key('yandex', $args) → transient?
5. Промах → Collector::collect($args) → Normalizer → renderer->render()
6. set_transient, echo, exit
```

## Последствия

**Плюсы:**

- Рендер — единственный владелец своего конфига.
- Добавление Google — новый класс и одна строка в
  `Plugin::renderers()`.
- Отключение рендера изолировано: 404, ядро не участвует.
- Разные фильтры по рубрикам и тегам — разные
  `get_collector_args()`.
- Форма настроек — единая страница, секции не плодят страниц.
- Имена ключей схемы (`include_taxonomies`) не конфликтуют с
  булевым `include_terms` нормализации.
- Один инстанс `Store` и один набор рендеров на запрос.
  `Plugin::renderers()` — тонкая делегация в `Store::renderers()`,
  дублирующих инстанцирований нет.

**Минусы:**

- `RendererInterface` растёт с 4 до 7 методов. Реализаций мало (1),
  но для будущих рендеров порог входа выше.
- Один источник дефолтов для `core` (в `Store`) и другой для полей
  рендеров (в схеме). Небольшая асимметрия, но терпимая.
- UI — плоский текст для terms. Полноценный multiselect — отдельная
  задача.

**Риски:**

- **Поведенческое изменение.** Сейчас Yandex работает всегда. После
  ADR — только при `enabled = true`. На чистой установке рендер
  выключен (соответствует ADR-ARCH-0003, поле `enabled` default
  off). Пользователю нужно включить ленту в настройках. Решение
  осознанное: opt-in.
- **Обновление тестов.** `Endpoint::serve()` получает второй
  аргумент `$settings`. Существующие интеграционные тесты
  (`EndpointTest::setUp`, `test_yandex_slug_returns_rss` и др.)
  обновляются: `serve('yandex', $this->settings)`. Без настроек
  `is_enabled()` вернёт `false` и тест увидит 404. Правка тестов —
  часть коммита PROC-0014a.
- `get_settings_schema()` может разойтись с реальными ключами,
  которые рендер читает в `get_collector_args()`. Тест на Yandex:
  все ключи, которые читает `get_collector_args()`, присутствуют в
  схеме.
- Плоский парсер taxonomies — ошибки ввода не подсвечиваются.
  Парсер отбрасывает мусор молча (см. контракт). Приемлемо для MVP,
  валидация с сообщением — отдельным решением.

## Альтернативы

- **Фильтры вместо интерфейса** (`nwff_settings_fields`,
  `nwff_collector_args`, `nwff_renderer_enabled`). Отклонено: slug
  передаётся в колбэк параметром, легко забыть проверку, порядок
  фильтров значим. Рендеры — часть ядра, явный интерфейс честнее.
- **Value-object `RendererConfig`** вместо трёх методов. Отклонено
  преждевременно: три метода читаются проще, чем объект с восемью
  полями.
- **Per-renderer override для `depth_hours` / `cache_ttl`.**
  Отклонено: два уровня UI для одного параметра. Вводим, когда
  конкретный рендер явно попросит.
- **Разные страницы настроек на рендер.** Отклонено: один экран
  проще для контентщиков (ADR-ARCH-0001, «Настройки»).
- **Хранить поля рендеров в отдельных опциях**
  (`nwff_settings_yandex`). Отклонено: одно чтение опции вместо N,
  одна форма.
- **AJAX-дерево таксономий в UI.** Отклонено: 200 строк кода ради
  эргономики ввода `12,15`. Плоский текст — MVP.
- **`normalization_config` внутри `get_collector_args()`.** Отклонено:
  рендер вызывает свой же метод из своего же метода, зоны
  ответственности сливаются. Конфиг нормализации вкладывает
  `Endpoint`.
- **Рендеры владеет `Plugin`, Store только читает настройки.**
  Отклонено: `Plugin::renderers()` вызывался бы из трёх мест (Store,
  Page, Endpoint), каждый раз создавая новые инстансы. Рендеры без
  состояния, но три инстанса YandexRenderer на запрос — лишняя
  работа и лишний повод для рассинхрона. Store как единственный
  владелец списка устраняет обе проблемы.
- **Сохранять неизвестные ключи из POST.** Отклонено:
  mass-assignment. Чтобы подсунуть ключ, нужно уже иметь
  `manage_options`, но компромисс не стоит выгоды — схема
  остаётся единственным источником правды о полях.
- **`flush_rewrite_rules()` в `Settings\Page` при каждом сохранении.**
  Отклонено: дорого (правка `tag_interview` дёргает rewrite) и
  неправильно по месту (`sanitize_callback` срабатывает до того,
  как `Page` узнаёт результат). Условный flush через
  `update_option_nwff_settings` — точнее.

## Ссылки

- **Зависит от:** ADR-ARCH-0001 (ядро, интерфейс рендера,
  настройки), ADR-ARCH-0002 (нормализация), ADR-ARCH-0003
  (Yandex — первый потребитель).
- **Уточняет:** ADR-ARCH-0001 (разделы «Настройки», «Интерфейс
  рендерера», «Эндпоинты»), ADR-ARCH-0003 (форма секции настроек
  Yandex).
- **Связано с:** ADR-PROC-0001 (рабочий цикл).

## Открытые вопросы

- **Override `depth_hours` / `cache_ttl`.** Введён не будет, пока
  конкретный рендер не потребует. Точка возврата — добавление
  Google.
- **Секция ядра в UI.** Пока только `feed_slug`. Если появятся
  другие глобальные параметры — пересмотреть группировку.
- **Санитайз `include_taxonomies` / `exclude_taxonomies`.** Парсер
  отбрасывает мусор молча. Валидация с сообщением об ошибке —
  отдельным решением.
