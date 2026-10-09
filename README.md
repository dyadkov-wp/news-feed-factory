# News Feed Factory

Generate news feeds in multiple formats (Yandex, Google, Dzen, generic RSS)
from WordPress content.

## Purpose

A news WordPress site needs export channels besides its web presentation:
news aggregators, search engines, reposters, RSS readers. Each consumer has
its own requirements for format and data, but all work with the same site
content.

This plugin builds feeds (RSS/XML and sitemaps) from WordPress content for
those consumers. The consumer does not affect the core — it only defines
the renderer format.

## Requirements

- WordPress 6.0+
- PHP 8.0+

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md) for environment setup, code
style, tests and workflow.

## Architecture

See `doc/adr/` for architecture decisions. Start with
`ADR-PRODUCT-0001-news-feed-factory.md` and `ADR-ARCH-0001-core.md`.

See [BACKLOG.md](BACKLOG.md) for deferred tasks and tech debt.

## License

MIT. See [LICENSE](LICENSE).
