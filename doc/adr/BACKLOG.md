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
