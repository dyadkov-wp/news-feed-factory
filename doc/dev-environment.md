# Development Environment

This document describes the VM layout used for developing and testing
News Feed Factory. It is intended for onboarding new contributors and
for providing environment context to AI assistants.

> **Secrets.** This file does not contain passwords, SSH keys, or
> database credentials. See `wp-tests-config.php` on the VM for the
> test database password.

## Overview

The project runs on a Debian VM with:

| Component | Version |
| --- | --- |
| OS | Debian GNU/Linux 13 (trixie) |
| PHP | 8.3.35 (cli, NTS) |
| MariaDB | 11.8.6 |
| Composer | 2.10.3 |
| WordPress | latest (test suite copy) |

Three WordPress trees coexist:

| Path | Purpose |
| --- | --- |
| `/var/www/wordpress/` | Working WordPress install, used for manual checks and `wp-cli` |
| `/var/www/wordpress-tests-lib/` | WordPress PHPUnit test suite (`includes/`, `data/`) |
| `/var/www/wordpress-test/` | Separate WP core copy, referenced as `ABSPATH` in `wp-tests-config.php` |

The plugin is developed in:

```
/var/www/wordpress/wp-content/plugins/news-feed-factory/
```

## Access

- **SSH:** host, port, user — ask the repository owner.
- **Editor:** Zed Remote over SSH (any remote editor works).
- **Working directory:** `/var/www/wordpress/wp-content/plugins/news-feed-factory/`.

## PHP extensions

Verified on the VM:

```
curl, gd, libxml, mbstring, mysqli, SimpleXML, xml, xmlreader, xmlwriter
```

`gd` is required for `ImageExtractor` integration tests — they call
`imagecreatetruecolor()` to generate fixture images.

`mbstring` is required for `Truncator` and any multibyte string
operations.

## Database

Test database settings live in
`/var/www/wordpress-tests-lib/wp-tests-config.php`:

| Constant | Value |
| --- | --- |
| `DB_NAME` | `wordpress_test` |
| `DB_USER` | `wp` |
| `DB_HOST` | `localhost` |
| `DB_PASSWORD` | (see the file on the VM) |

CLI client — `mariadb` (and `mysql` alias):

```bash
mariadb -u wp -p wordpress_test
```

To recreate the test database from scratch:

```bash
mariadb -u root -p -e "
  DROP DATABASE IF EXISTS wordpress_test;
  CREATE DATABASE wordpress_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
"
```

## Environment variables

Required by PHPUnit integration tests. Set in shell profile or passed
inline:

```bash
export WP_TESTS_DIR=/var/www/wordpress-tests-lib
export WP_TESTS_PHPUNIT_POLYFILLS_PATH=/var/www/wordpress/wp-content/plugins/news-feed-factory/vendor/yoast/phpunit-polyfills
export WP_CLI_ALLOW_ROOT=1
```

`phpunit.xml.dist` provides defaults for `WP_TESTS_DIR` and
`WP_TESTS_PHPUNIT_POLYFILLS_PATH`, so `composer test:integration` works
without exports when the layout matches.

## Commands

From the plugin root:

```bash
composer install            # install dependencies
composer install-hooks      # install git hooks (also runs on post-install)
composer lint               # PHP_CodeSniffer
composer lint:fix           # auto-fix style
composer analyse            # PHPStan level 6
composer test:unit          # unit tests, no WordPress
composer test:integration   # integration tests, requires WP test suite
composer test               # all suites
composer check              # lint + analyse + unit tests
```

WordPress-CLI commands (for debugging on the working install):

```bash
cd /var/www/wordpress
wp post list --post_type=post --posts_per_page=1
wp eval '$p = get_posts(["numberposts" => 1])[0]; var_dump($p->post_content);'
```

## Git

Remote: `git@github.com:dyadkov-wp/news-feed-factory.git`

Identity configured locally:

```
user.name  = Dyadkov-wp
user.email = dyadkovm@gmail.com
```

Hooks installed via `composer install-hooks`:

- **pre-commit** — runs PHPCS on staged PHP files. Fails if any file
  has style violations. Bypass with `git commit --no-verify`.
- **pre-push** — runs `composer check`. Fails if anything is red.
  Bypass with `git push --no-verify`.

Hook scripts live in `tools/git-hooks/` and are copied to `.git/hooks/`
on every `composer install` / `composer update`.

## Local-only paths

Two directories are excluded via `.git/info/exclude` (not committed):

- `dev_tools/` — local development utilities
- `out/` — local build outputs

These are personal and do not belong in the shared repository. On a
fresh clone, recreate them if needed.

## Editor configuration

`.editorconfig` in the repository root enforces indentation:

- Tabs for PHP, Markdown, JSON, NEON, YAML.
- 2 spaces for CSS/SCSS/LESS, JS.
- LF line endings, final newline, trimmed trailing whitespace.

Zed users: `settings.json` must set `hard_tabs: true` for PHP, or the
editor will silently convert tabs to spaces on save and break WPCS:

```json
{
  "languages": {
    "PHP": {
      "hard_tabs": true,
      "tab_size": 4
    }
  }
}
```

## Recreating the environment

If the VM is lost, the setup sequence is:

1. Install Debian 13, PHP 8.3 (`php8.3-cli`, `php8.3-gd`,
   `php8.3-mbstring`, `php8.3-mysql`, `php8.3-xml`, `php8.3-curl`),
   MariaDB 11, Composer 2, WP-CLI.
2. Create the test database and user (see "Database").
3. Run `install-wp-tests.sh` (from `wp-cli/scaffold-command`) to
   populate `/var/www/wordpress-tests-lib/` and
   `/var/www/wordpress-test/`.
4. Fix `ABSPATH` in `/var/www/wordpress-tests-lib/wp-tests-config.php`
   if the core path differs.
5. Install WordPress in `/var/www/wordpress/`.
6. Clone this repository into
   `/var/www/wordpress/wp-content/plugins/news-feed-factory/`.
7. `composer install` (installs dependencies and git hooks).

Detailed instructions for `install-wp-tests.sh` are in
`CONTRIBUTING.md`, section "Installing the WordPress test suite".

## Known issues

- **Zed tab handling.** Without `hard_tabs: true`, Zed normalizes tabs
  to spaces on save, breaking WPCS. The pre-commit hook catches this.
- **Silent exit from `ABSPATH` guards.** Classes in `src/` must not
  start with `if ( ! defined( 'ABSPATH' ) ) exit;` — this kills
  PHPUnit silently under the unit bootstrap. See ADR-PROC-0005.
- **Integration tests are not in `composer check`.** They require the
  WordPress test suite and run via `composer test:integration`
  separately.
