# ADR-PROC-0004: Раздельные bootstrap'ы для unit- и integration-тестов

**Статус:** Accepted
**Создано:** 2026-10-09
**Обновлено:** 2026-10-09

## Контекст

`phpunit.xml.dist` задаёт единый `bootstrap="tests/bootstrap.php"`,
который безусловно загружает WordPress test suite и поднимает
соединение с тестовой БД. CONTRIBUTING.md при этом обещает:

> `composer test:unit` — unit tests, no WP required
> Unit tests must run fast.

Первый же unit-тест для `GenreResolver` (ADR-ARCH-0003) попадёт в
противоречие: класс работает с массивами `$item` и `$settings`, WP ему
не нужен, но запуск `composer test:unit` потянет весь WP-стек и
потребует поднятого `WP_TESTS_DIR` и живой тестовой БД.

## Решение

Два bootstrap-файла, по одному на сьют:

- `tests/bootstrap-unit.php` — только `vendor/autoload.php`.
  WordPress не загружается.
- `tests/bootstrap-integration.php` — текущий `tests/bootstrap.php`
  (autoload + WP test suite + регистрация плагина как mu-plugin).

`phpunit.xml.dist` сохраняет единственный конфиг с
`bootstrap="tests/bootstrap-integration.php"` — безопасный дефолт для
WP-плагина: любой «голый» `phpunit` получает полное окружение. Unit-
прогон переопределяет bootstrap через CLI в `composer.json`:

```json
"test":             "phpunit",
"test:unit":        "phpunit --bootstrap tests/bootstrap-unit.php --testsuite=unit",
"test:integration": "phpunit --testsuite=integration"
```

Файл `tests/bootstrap.php` удаляется — два явно названных файла
читаются однозначнее, чем «дефолтный» + «специальный».

## Последствия

**Плюсы:**

- `composer test:unit` не требует `WP_TESTS_DIR`, WP core и БД.
- Unit-цикл быстрый — соответствует обещанию CONTRIBUTING.md.
- `GenreResolver` и подобные чистые классы тестируются изолированно.

**Минусы:**

- Два bootstrap-файла надо держать в согласии при изменении общего
  окружения (autoload, `WP_TESTS_PHPUNIT_POLYFILLS_PATH`).
- Разработчик, запускающий `phpunit --testsuite=unit` вручную без
  `--bootstrap`, получит WP-окружение. Митигается тем, что
  `composer test:unit` — рекомендованный путь (CONTRIBUTING.md).

## Альтернативы

- **Единый bootstrap (status quo).** Отклонено: тянет WP в unit,
  противоречит CONTRIBUTING.md.
- **Условная загрузка WP по имени сьюта.** PHPUnit не передаёт имя
  сьюта в bootstrap. Хрупко.
- **Два phpunit-конфига (`phpunit-unit.xml`, `phpunit-integration.xml`).**
  Дублирует `<testsuites>`, `<coverage>`, `<php>`. Отклонено по DRY.
- **Оставить как есть до первого unit-теста.** Откладывает решение,
  но не отменяет его; решаем сейчас, пока контекст свежий.

## Ссылки

- **Зависит от:** ADR-PROC-0001 (рабочий цикл), ADR-ARCH-0003
  (GenreResolver — первый потребитель).
- **Связано с:** CONTRIBUTING.md (раздел «Tests»),
  `phpunit.xml.dist`, `composer.json`.
