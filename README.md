# Installer Toolkit

Shared installer wizard, self-update engine, and `package:build` command for Laravel apps distributed as self-installing zips.

Rather than reimplementing installation and updates in every product, each app pulls this package in and gets a browser-based install wizard, signed self-updates, and a one-command build pipeline that produces the customer-facing zip.

## Requirements

- PHP 8.2+
- Laravel 11, 12, or 13
- Extensions: `json`, `sodium`, `zip`

## Installation

This package is hosted on GitHub and is **not** published to Packagist, so the repository must be registered before it can be required:

```bash
composer config repositories.installer-toolkit vcs https://github.com/johneady/installer-toolkit.git
composer require johneady/installer-toolkit:^2.5
```

> To stay on the development branch instead of tagged releases, replace `^2.5` with `dev-main` and set `"minimum-stability": "dev"` (with `"prefer-stable": true`) in your `composer.json`.

The service provider is auto-discovered via Laravel's package discovery.

Publish the update config:

```bash
php artisan vendor:publish --provider="InstallerToolkit\Update\UpdateServiceProvider"
```

## Commands

| Command | Purpose |
| --- | --- |
| `package:build` | Build the distributable package (zip + installer + readme) |
| `package:sandbox` | Provision a throwaway environment for testing an install |
| `package:test` | Run the packaged installer end to end |
| `update:keygen` | Generate an Ed25519 signing keypair |
| `update:prune` | Delete abandoned upload artifacts |

## Building a release

```bash
php artisan package:build --output=package
```

This produces an outer zip containing the application archive, `install.php`, and `readme.html` — the three files a customer uploads to their document root.

## Update signing

Update packages are signed with Ed25519 and verified **fail-closed**: an unsigned or badly signed package is rejected rather than trusted.

Generate a keypair:

```bash
php artisan update:keygen
```

Trusted public keys live in `config/updates.php` under `signing.trusted_keys`, keyed by `key_id`. Each consuming app must set a matching `signing_key_id` in its `package/package-config.php`.

To rotate keys, add the new `key_id` alongside the old one so in-flight updates keep verifying, ship a release signed with the new key, then drop the old entry in a later release.

**The private signing key must never be committed.** The build reads it from `UPDATE_SIGNING_KEY_FILE` in your local `.env`, which is gitignored.

## Repository layout

| Path | Contents |
| --- | --- |
| `src/` | Build commands and the update engine |
| `src/Update/` | Signing, authorization, and update HTTP endpoints |
| `templates/` | Installer wizard views |
| `stubs/` | Files copied into built packages |
| `config/updates.php` | Update and signing configuration |
| `readme.html` | Customer-facing install guide, packed into built zips |

> `readme.html` is a shipped build artifact, not documentation for this repo. `package:build` fails if it is missing, and `[[MIN_PHP_VERSION]]` inside it is substituted at build time.

## Testing

```bash
composer test    # Pest
composer pint    # code style
```

## License

Proprietary — all rights reserved. See [LICENSE](LICENSE).

This source is publicly readable so that consuming applications can install it
without authentication. That does not make it open source: no permission is
granted to use, copy, modify, or redistribute this software without prior
written consent.
