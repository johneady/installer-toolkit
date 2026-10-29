# Installer Toolkit

Shared installer wizard, self-update engine, and `package:build` command for Laravel apps distributed as self-installing zips.

Rather than reimplementing installation and updates in every product, each app pulls this package in and gets a browser-based install wizard, signed self-updates, and a one-command build pipeline that produces the customer-facing zip.

## Requirements

- PHP 8.5+
- Laravel 11, 12, or 13
- Extensions: `json`, `sodium`, `zip`

## Installation

This package is hosted on GitHub and is **not** published to Packagist, so the repository must be registered before it can be required:

```bash
composer config repositories.installer-toolkit vcs https://github.com/johneady/installer-toolkit.git
composer require johneady/installer-toolkit:^2.8
```

> To stay on the development branch instead of tagged releases, replace `^2.8` with `dev-main` and set `"minimum-stability": "dev"` (with `"prefer-stable": true`) in your `composer.json`.

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

The installer and updater are generated from the toolkit installed in the app's `vendor/`, so a package is built from exactly the toolkit version its `composer.lock` pins. To try an unreleased toolkit change against a real app, point the build at a checkout:

```bash
php artisan package:build --toolkit=~/php/installer-toolkit
```

### Building in CI

`.github/workflows/release.yml` is a reusable workflow that runs an app's whole release. On each push to `main`, release-please keeps a release PR open. Merging that PR tags `vX.Y.Z` and publishes a GitHub Release. The workflow then builds that tag with `build-package.yml` and attaches the full zip, demo zip and `.update` to the release. The app's `.github/workflows/release-please.yml` only calls it:

```yaml
name: Release Please

on:
  push:
    branches: [main]
  workflow_dispatch:
    inputs:
      tag:
        description: Existing release tag to rebuild, e.g. v1.4.0
        required: true
        type: string

permissions:
  contents: read

jobs:
  release:
    uses: johneady/installer-toolkit/.github/workflows/release.yml@v2.9.0
    with:
      runs-on: ${{ vars.CI_RUNNER || 'ubuntu-latest' }}
      release-timeout-minutes: ${{ vars.CI_RUNNER && 30 || 5 }}
      package-timeout-minutes: ${{ vars.CI_RUNNER && 120 || 45 }}
      rebuild-tag: ${{ inputs.tag }}
    permissions:
      contents: write
      pull-requests: write
      issues: write
    secrets: inherit
```

Each app also needs the following:

- `release-please-config.json`: release type `simple`, `"include-component-in-tag": false` (tags must be plain `vX.Y.Z`), `"extra-files": ["config/app.php"]`, and a `bootstrap-sha` for the first release.
- `.release-please-manifest.json`.
- A `// x-release-please-version` marker on the `'version'` line in `config/app.php`.
- "Allow GitHub Actions to create and approve pull requests" turned on under Settings → Actions → General.

To rebuild a release whose package job failed, run the workflow by hand ("Run workflow", on `main`) with the tag. That skips release-please and rebuilds only that tag. The tag's GitHub Release must already exist.

The signing key reaches the build as the `UPDATE_SIGNING_KEY` secret. The job runs in the app repo's `release` environment. On GitHub Pro or Team, an environment secret limited to `main` takes precedence. A private repo on GitHub Free ignores environment secrets, so there the key must be a repository secret. That secret has no branch restriction: any workflow run from any branch of the repo can read it by naming it, including pull request runs from same-repo branches. Only Dependabot and fork PRs never receive it. So only people with push access must be able to change workflows. Never name the secret, or pass `secrets: inherit`, in a workflow that runs on pull requests, because those runs execute third-party package code on the same runners.

## Update signing

Update packages are signed with Ed25519 and verified **fail-closed**: an unsigned or badly signed package is rejected rather than trusted.

Generate a keypair:

```bash
php artisan update:keygen
```

Trusted public keys live in `config/updates.php` under `signing.trusted_keys`, keyed by `key_id`. Each consuming app must set a matching `signing_key_id` in its `package/package-config.php`.

To rotate keys, add the new `key_id` alongside the old one so in-flight updates keep verifying, ship a release signed with the new key, then drop the old entry in a later release.

**The private signing key must never be committed.** The build looks for it in this order:

1. `UPDATE_SIGNING_KEY`: the key itself. This is the CI secret.
2. `UPDATE_SIGNING_KEY_FILE`: a path to a file holding the key, either bare or as an `UPDATE_SIGNING_KEY=` line.
3. `~/.config/installer-toolkit/signing.key` (or `$XDG_CONFIG_HOME/installer-toolkit/signing.key`): one file per machine, so local builds need no signing entries in any app's `.env`. Keep it `chmod 600`.

If the app sets `signing_key_id` and no key resolves, the build fails rather than shipping an unsigned package.

As of 2.6.0 the build resolves that key through `config('updates.signing.private_key_file')` rather than reading the environment directly, so it still resolves when the config is cached — the `env()` call returns null once `config:cache` has run, which silently broke signed builds in release pipelines.

## Upgrading to 2.6.0

Two changes require action in consuming apps:

**PHP 8.3 is now the minimum.** Bump `"php"` in the app's `composer.json` to `^8.3`, or Composer will refuse to resolve.

**Published configs need the signing key entries.** `mergeConfigFrom()` is a shallow merge, so an app whose published `config/updates.php` declares a `signing` array replaces the package's wholesale. The provider now backfills `private_key` and `private_key_file` when they are absent, so existing apps keep building — but declaring them explicitly is clearer:

```php
'signing' => [
    'private_key' => env('UPDATE_SIGNING_KEY', ''),
    'private_key_file' => env('UPDATE_SIGNING_KEY_FILE', ''),

    'trusted_keys' => [
        // ...
    ],
],
```

Note that only those two build-time entries are backfilled, and only when the config is not cached — a cached config is already fully resolved. `trusted_keys` remains a deliberate wholesale override: unioning it with the package's defaults would re-add keys an app had removed, making it impossible to revoke a trust anchor from the app side.

## Releases

Releases are cut by [release-please](https://github.com/googleapis/release-please). Merging conventional commits to `main` keeps a release PR open. Merging that PR bumps `VERSION`, writes `CHANGELOG.md`, tags `vX.Y.Z` and publishes the GitHub Release. Composer resolves versions from those tags. `fix:` bumps the patch version, `feat:` the minor version, and `feat!:` or `BREAKING CHANGE` the major version. Don't edit `VERSION` by hand.

## Repository layout

| Path | Contents |
| --- | --- |
| `src/` | Build commands and the update engine |
| `src/Update/` | Signing, authorization, and update HTTP endpoints |
| `templates/` | Installer wizard views |
| `stubs/` | Files copied into built packages |
| `config/updates.php` | Update and signing configuration |
| `readme.html` | Customer-facing install guide, packed into built zips |
| `.github/workflows/release.yml` | Reusable release pipeline that apps call (release-please, then `build-package.yml`) |

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
