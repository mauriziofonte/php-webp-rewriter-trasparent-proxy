# Contributing

Thank you for considering a contribution. This page explains how to set up a development copy, which checks CI runs, and how commits are written.

## Before you start

- For a bug, open an issue with the version (or commit), the PHP version, the web server, the relevant configuration keys (never the secrets) and, when you can, the log lines of the request with their `request_id`.
- For a security problem, do not open an issue: follow [SECURITY.md](SECURITY.md).
- For a larger change, open an issue first to agree on the approach.

## Development setup

You need PHP 7.2.5 or later (the code must keep running on PHP 7.2), Composer, and Docker for the integration, contract and performance suites.

```bash
git clone https://github.com/mauriziofonte/php-webp-rewriter-trasparent-proxy.git
cd php-webp-rewriter-trasparent-proxy
composer install
```

No lock file is committed: one lock cannot serve both PHP 7.2 and PHP 8.5, so Composer resolves the dependencies for the PHP version that runs it. CI tests both the lowest and the highest versions allowed by `composer.json`; to reproduce the lowest set locally:

```bash
composer update --prefer-lowest --prefer-stable
```

The static analysis tools cannot be installed on PHP 7.2 and live in `tools/<name>/`, each with its own `composer.json`. Install them with a recent PHP:

```bash
for tool in tools/*/; do composer install --working-dir="$tool"; done
```

## Checks

These are the commands CI runs:

| Command | What it runs |
| --- | --- |
| `composer validate --strict` | The Composer manifests (also for `tools/*/composer.json`). |
| `composer test` | PHPUnit: the `unit`, `functional` and `integration` suites (`contract` is skipped unless its harness runs it). |
| `composer cs:check` | php-cs-fixer, as a dry run with a diff. `composer cs:fix` applies the fixes. |
| `composer analyse` | PHPStan at level max, once as PHP 7.2.5 and once as PHP 8.5. |
| `composer taint` | Psalm taint analysis of `src/`. |
| `composer audit` | Known advisories in the resolved dependencies. |
| `composer ci` | `cs:check`, `analyse` and `test`, in this order. |

PHPUnit turns notices, warnings and deprecations into failures (`tests/bootstrap.php`). Run one suite with `vendor/bin/phpunit --testsuite unit`.

The Docker-based suites, also run by CI:

| Command | What it checks |
| --- | --- |
| `tests/Integration/docker/run.sh --php 8.5` | Apache and nginx with PHP-FPM against a stand-in origin: hits served without PHP, variants, encodings, query strings, PURGE, trusted proxies, and one origin fetch for 500 concurrent requests. Needs `vendor/` installed for that PHP version (for example `composer update --no-dev`). |
| `tests/Integration/laravel/run.sh` | The kernel mounted in a fresh Laravel application. |
| `tests/Contract/run.sh --api <checkout> --php 8.5` | The remote converter against a checkout of the conversion API. |
| `tests/Performance/run.sh --php 8.5` | The latency suite and its regression gate (see [docs/performance.md](docs/performance.md)). |

CI runs the test suite on PHP 7.2 to 8.5 with the lowest and the highest dependencies, and the coding style and static analysis checks on PHP 8.4.

## Coding standards

- PSR-12 plus the rules of [`.php-cs-fixer.dist.php`](.php-cs-fixer.dist.php), which are shared with the related repositories.
- PHP 7.2 syntax only in `src/`, `bin/`, `public/` and `config/php/`: no arrow functions, no `match`, no constructor promotion, no trailing commas in calls or parameter lists. PHPStan as PHP 7.2.5 catches most of these.
- `declare(strict_types=1);` in every PHP file; final classes unless a class is meant to be extended.
- New behavior comes with tests. A change to the Apache or nginx configuration comes with a change to the integration harness, and the media types of `public/.htaccess`, `config/nginx/proxy-cdn-types.conf` and `src/Http/MediaTypes.php` stay identical (a test checks it).
- A new configuration key goes in `ConfigLoader::KEYS` (or `OperationsConfig::KEYS`), in the validator, in `.env.example` and in [docs/configuration.md](docs/configuration.md). A key that is renamed or removed goes in `src/Config/DeprecatedKeys.php`, with the advice `check-env` prints.
- Update the documentation in the same change as the behavior it describes.

## Commits and pull requests

Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/):

```text
fix(cache): forget the coalescing outcome on purge

A purge left the outcome of a failed fetch in place, so the next
request was answered with the old error for up to five seconds.
```

- Types: `feat`, `fix`, `perf`, `refactor`, `test`, `docs`, `build`, `ci`, `chore`; a scope names the area (`cache`, `http`, `origin`, `image`, `config`, `console`, `readme`, ...).
- The subject is imperative, lower case after the colon, without a final period, and at most 72 characters. Body lines are at most 72 characters too.
- The body explains why the change is made, not only what it does.
- One logical change per commit, and every commit leaves the test suite green.
- A breaking change is marked with `!` after the type or scope and described in a `BREAKING CHANGE:` footer, and in [UPGRADING.md](UPGRADING.md).

Open the pull request against the default branch, describe what changes and how you tested it, and make sure CI is green.

## License

By contributing, you agree that your contributions are licensed under the [BSD 3-Clause License](LICENSE) of the project.
