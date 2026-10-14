# Contributing to News Feed Factory

Thanks for your interest. This guide covers setup, code style, tests
and the workflow for submitting changes.

## Requirements

| Component | Version |
| --- | --- |
| PHP | 8.0+ (8.3 recommended) |
| Composer | 2.x |
| MySQL / MariaDB | 5.7+ / 10.5+ |
| WordPress | 6.0+ |

For running integration tests, a WordPress test suite is required
(see below).

## Environment options

Three setups are supported. Pick the one that matches your workflow.

### Option A: wp-env (Docker)

Best for contributors who do not want to manage a VM or a local LAMP
stack. Requires Docker Desktop or Docker Engine.

```
npm install -g @wordpress/env
cd /path/to/news-feed-factory
wp-env start
```

The plugin is mounted into the WordPress container at
`wp-content/plugins/news-feed-factory`. WP is available at
`http://localhost:8888`, admin at `http://localhost:8888/wp-admin`
(login: `admin` / `password`).

Run tests inside the container:

```
wp-env run tests-cli --env-cwd=wp-content/plugins/news-feed-factory composer check
```

### Option B: VM (full control)

A Debian VM with PHP, MariaDB and WordPress.

1. Install PHP 8.3, MariaDB, Composer, WP-CLI.
2. Install WordPress in `/var/www/wordpress`.
3. Install the WordPress test suite (see below).
4. Clone this repository into `wp-content/plugins/`.
5. Run `composer install`.

### Option C: local WordPress

For developers with an existing WordPress installation.

1. Clone this repository into `wp-content/plugins/`.
2. Run `composer install`.
3. Activate the plugin in WP admin.
4. Install the WordPress test suite (see below) for integration tests.

## Installing the WordPress test suite

Integration tests require the WordPress test suite and a dedicated
test database.

### 1. Create a test database

```
CREATE DATABASE wordpress_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'wp'@'localhost' IDENTIFIED BY 'wp-pass-here';
GRANT ALL PRIVILEGES ON wordpress_test.* TO 'wp'@'localhost';
FLUSH PRIVILEGES;
```

Choose your own password. Do not commit it.

### 2. Install the test suite

```
cd /var/www
curl -sS -o install-wp-tests.sh \
    https://raw.githubusercontent.com/wp-cli/scaffold-command/main/templates/install-wp-tests.sh
chmod +x install-wp-tests.sh
./install-wp-tests.sh wordpress_test wp 'wp-pass-here' localhost latest
```

The script downloads the WordPress test suite and a matching WordPress
core copy. By default it uses `/tmp/wordpress` and
`/tmp/wordpress-tests-lib`. To use permanent paths, export before
running:

```
export WP_TESTS_DIR=/var/www/wordpress-tests-lib
export WP_CORE_DIR=/var/www/wordpress-test
```

### 3. Fix `wp-tests-config.php`

If the paths were moved after installation, update `ABSPATH` in
`wp-tests-config.php`:

```
grep ABSPATH /var/www/wordpress-tests-lib/wp-tests-config.php
```

The value must match the `WP_CORE_DIR` path.

### 4. Configure the project

`phpunit.xml.dist` expects `WP_TESTS_DIR` and
`WP_TESTS_PHPUNIT_POLYFILLS_PATH`. Defaults match the VM layout. On
other setups, override via environment variables or a local
`phpunit.xml` (ignored by git).

## Code style

The project follows WordPress Coding Standards.

- Tabs for indentation, spaces for alignment inside lines.
- One class/interface/trait per file. Filename matches the class name
  (PSR-4).
- Consecutive assignments align their `=` to the longest variable
  name in the block. If alignment looks noisy, restructure the code
  instead of padding by hand.
- Multi-line arrays have a trailing comma on the last element.
- Wrap PHP code at ~100 columns.
- Yoda conditions: `if ( 1 === $x )`.
- All output escaped: `esc_html`, `esc_attr`, `esc_url`.
- All input sanitized.
- Text domain: `news-feed-factory`.
- Comments and docblocks in English.

### File header

Every PHP file starts with:

```php
<?php
/**
 * Short description of what this file is.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\...;
```

`declare( strict_types=1 )` is mandatory. Use statements are
alphabetically sorted.

### Type declarations

Every parameter, property and return value has a PHP type. Use
PHPDoc shapes (`array<string, mixed>`, `array{url: string, width:
int}`) when PHP types cannot express the structure.

### Docblocks

Every class, interface, method, property and constant gets a
docblock.

Short description rules:

- Starts with a capital letter.
- Ends with a period.
- **Does not change the case of an identifier.** Rephrase so the
  identifier is not the first word.

  Wrong:

  > `taxonomy_include restricts to matching terms.`

  Right:

  > `Restricts to matching terms for taxonomy_include.`

  Same for `include_terms`, `limit`, `content`, `image_url`,
  `exclude_from_feeds`. Prefer a verb (`Caps`, `Sanitizes`,
  `Populates`, `Yields`, `Restricts`, `Removes`) or a neutral phrase
  (`When include_terms is false, ...`).

Tag conventions:

- `@param type $name Description.`
- `@return type Description.`
- `@var type` on properties.
- `@throws` when a method can throw.

All tag descriptions end with a period.

### PHP classes in `src/`

Fully qualified, no `use`:

```php
$query = new \WP_Query( $args );
if ( ! $post instanceof \WP_Post ) { ... }
```

No `ABSPATH` guard on top-level in `src/` — see ADR-PROC-0005.

### SQL

- All values go through `$wpdb->prepare()` with `%d`, `%s`, `%f`.
- Table names come from `$wpdb->prefix`. Never hardcode.
- Custom tables (`nwff_feed_meta`) have no WP API. Direct queries
  are allowed with a scoped ignore.
- When SQL is built in a variable and then passed through
  `prepare()`, WPCS's `NotPrepared` sniff fires anyway. Add a
  scoped ignore above the `prepare()` call.
- When calling `$wpdb->get_results()` / `insert()` / `update()`
  directly, add `DirectQuery` and `NoCaching` ignores.
- **Reasons are mandatory.** `phpcs:ignore Rule -- reason.`
  Never bare `phpcs:ignore Rule.`

Examples:

```php
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built above with %d placeholders, one per ID, and passed through $wpdb->prepare() on the next line.
$prepared = $wpdb->prepare( $sql, ...$chunk );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; result caching lives in TransientCache upstream.
$rows = $wpdb->get_results( $prepared );
```

### WP globals

Never assign to WP globals in `src/`. In tests, wrap the assignment
in a single private helper with one scoped ignore:

```php
private function install_wpdb( object $wpdb ): void {
    // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test double; see method docblock.
    $GLOBALS['wpdb'] = $wpdb;
}
```

### When PHPCS disagrees

WPCS is authoritative. If a sniff is wrong for this project, add a
scoped ignore with a reason. Never use `phpcs:disable` at file level.
If you fight the same sniff repeatedly, propose an exception in an
ADR before working around it file-by-file.

Run the linter before committing:

```
composer lint
composer lint:fix   # auto-fix where possible
```

PHPStan runs at level 6:

```
composer analyse
```

Both must pass before a commit lands.

## Tests

Follow the cycle in `doc/adr/ADR-PROC-0001-workflow.md`:
problem → test → fix. A test is written before the fix, and it stays
in the codebase afterward.

```
composer test:unit          # unit tests, no WP required
composer test:integration   # integration tests, requires WP test suite
composer test               # all suites
```

Unit tests must run fast. Integration tests are excluded from local
runs by default.

### Test doubles

Test doubles (a fake `$wpdb`, a fake HTTP client, a spy) are named
classes in their own files under `tests/unit/` or
`tests/integration/`. Never use anonymous classes for doubles.

Two reasons:

- PHPUnit 9.6 accumulates anonymous class definitions across test
  methods. Nine methods each defining their own double pushed the
  suite into OOM (3.1 GB RSS, kernel kill) during PROC-0009.
- WPCS forbids two class structures in one file
  (`Generic.Files.OneObjectStructurePerFile.MultipleFound`).

Naming: `FakeWpdb`, `FakeHttpClient`, `SpyRenderer`. Prefix signals
the intent.

## Submitting changes

### Commits

Conventional Commits, English messages. See
`doc/adr/ADR-PROC-0003-git-workflow.md` for the full rule.

```
feat(collector): add taxonomy sorting
fix(cache): invalidate on term change
docs(adr): add renderer decision
test: cover empty feed case
chore: bump dev dependency
```

One commit — one logical change. Do not mix refactoring with new
functionality.

### Branching

Trunk-based. Commit directly to `main`. No feature branches except for
large work that cannot be split into atomic commits.

### Before push

```
composer check
```

Must be green. If it fails, fix in a new commit — do not amend
published commits.

### Pull requests

Pull requests are welcome for non-trivial changes. For small fixes,
a direct commit to `main` is fine if you have write access.

For PRs:

1. Describe the problem the change solves.
2. Reference related issues.
3. Include tests.
4. Keep the diff focused.

## Architecture

Read `doc/adr/` before making non-trivial changes. ADRs are the
authoritative record of decisions. If your change contradicts an ADR,
raise it in the PR — do not silently override.

Start with:

- `doc/adr/ADR-PRODUCT-0001-news-feed-factory.md`
- `doc/adr/ADR-ARCH-0001-core.md`

## Composer and network issues

### Mirrors

`repo.packagist.org` may be unreachable from some networks. The project
uses the Aliyun mirror by default:

```
composer config repo.packagist composer https://mirrors.aliyun.com/composer/
```

Alternatives:

- SJTUG: `https://packagist.mirrors.sjtug.sjtu.edu.cn/`
- Tsinghua: `https://mirrors.tuna.tsinghua.edu.cn/composer/`

Always end the URL with `/`.

### Audit

`composer audit` always contacts `packagist.org/advisories` and ignores
mirrors. In restricted networks, disable blocking:

```
composer config --global audit.block-insecure false
```

See `BACKLOG.md` TD-01 for the offline audit database plan.

## Common commands

```
composer install          # install dependencies
composer lint             # PHP_CodeSniffer
composer lint:fix         # auto-fix code style
composer analyse          # PHPStan
composer test             # all PHPUnit suites
composer test:unit        # unit tests only (fast)
composer test:integration # integration tests (requires WP test suite)
composer check            # lint + analyse + unit tests
```

## WP-CLI as root

WP-CLI refuses to run as root by default. In a dev VM, allow it:

```
export WP_CLI_ALLOW_ROOT=1
```

Add to `~/.bashrc` to make it permanent.

## Local overrides

These files are git-ignored and can be created per-developer:

| File | Purpose |
| --- | --- |
| `phpcs.xml` | Local PHP_CodeSniffer overrides |
| `phpstan.neon` | Local PHPStan overrides |
| `phpunit.xml` | Local PHPUnit overrides (e.g., different paths) |
| `.phpactor.json` | Phpactor IDE config |

Never edit the `.dist` variants for local needs — commit changes there
only if they apply to all developers.

## Troubleshooting

### `Function tests_add_filter not found` from PHPStan

Expected. `tests_add_filter` comes from the WordPress test suite, not
core. It is excluded in `phpstan.neon.dist`.

### `The PHPUnit Polyfills library is a requirement`

Install `yoast/phpunit-polyfills`:

```
composer require --dev yoast/phpunit-polyfills:"^2.0"
```

Then set `WP_TESTS_PHPUNIT_POLYFILLS_PATH` in `phpunit.xml.dist` to
the absolute path of the polyfills directory inside `vendor/`.

### Database connection errors during tests

Verify `wp-tests-config.php` points to the correct database and that
the test DB is empty (or drop and recreate it).

### SSH / remote development

Not covered here — setup varies. Use whatever remote workflow you
prefer (Zed Remote, VS Code Remote, PhpStorm Gateway).
