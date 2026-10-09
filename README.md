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

### Setup

```
composer install
```

### Checks

```
composer check      # lint + analyse + unit tests
composer lint       # PHP_CodeSniffer
composer analyse    # PHPStan
composer test:unit  # PHPUnit, unit suite only
composer test       # PHPUnit, all suites
```

Integration tests require a real WordPress test environment (wp-env or the
WordPress test suite) and are excluded from local runs by default.

### Release

Built with `git archive`:

```
git archive --format=zip --prefix=news-feed-factory/ \
  --output=build/news-feed-factory.zip HEAD
```

Files excluded from the archive are listed in `.gitattributes`
(`export-ignore`).

## Architecture

See `doc/adr/` for architecture decisions. Start with
`ADR-PRODUCT-0001-news-feed-factory.md` and `ADR-ARCH-0001-core.md`.

## License

MIT. See [LICENSE](LICENSE).
