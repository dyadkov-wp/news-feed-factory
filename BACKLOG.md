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
