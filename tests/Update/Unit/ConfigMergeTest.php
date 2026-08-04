<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use InstallerToolkit\Update\UpdateServiceProvider;

/**
 * Guards how the package's config/updates.php is layered under a host app's
 * published copy.
 *
 * Consuming apps publish a PARTIAL config/updates.php declaring only
 * app-specific values. mergeConfigFrom() is a shallow array_merge, so an app
 * declaring `signing.trusted_keys` replaces the whole `signing` array — and
 * any sibling key the package adds later (signing.private_key) silently
 * vanishes, leaving package:build to resolve an empty key and fail a signed
 * build. UpdateServiceProvider::mergeSigningDefaults() backfills exactly
 * those two build-time entries.
 *
 * The counterpart matters just as much: trusted_keys must NOT be unioned with
 * the package's, or an app could never revoke a trust anchor by removing it.
 */
function registerWithPublishedConfig(mixed $signing): Repository
{
    $app = app();

    $app['config']->set('updates.signing', $signing);

    (new UpdateServiceProvider($app))->register();

    return $app['config'];
}

it('backfills the build-time signing keys when an app publishes only trusted_keys', function () {
    $config = registerWithPublishedConfig([
        'trusted_keys' => ['app-key' => 'app-public-key'],
    ]);

    // The two entries package:build reads are reachable again...
    expect($config->get('updates.signing'))
        ->toHaveKeys(['private_key', 'private_key_file']);

    // ...without disturbing what the app actually declared.
    expect($config->get('updates.signing.trusted_keys'))
        ->toBe(['app-key' => 'app-public-key']);
});

it('does not re-add package trusted_keys the app omitted, so revocation works', function () {
    // An app rotating away from a retired anchor pins only the new key.
    $config = registerWithPublishedConfig([
        'trusted_keys' => ['key-2026-07-b' => 'BqglICOiaFr+pu2siQu0hc+AgXdsMs+G0/yAvren5Fc='],
    ]);

    expect(array_keys($config->get('updates.signing.trusted_keys')))
        ->toBe(['key-2026-07-b'])
        ->not->toContain('key-2026-07');
});

it('leaves an explicit app signing key untouched', function () {
    $config = registerWithPublishedConfig([
        'private_key_file' => '/app/chosen/signing.env',
        'trusted_keys' => [],
    ]);

    expect($config->get('updates.signing.private_key_file'))
        ->toBe('/app/chosen/signing.env');
});

it('tolerates a published config with no signing array at all', function () {
    $config = registerWithPublishedConfig(null);

    expect($config->get('updates.signing'))->not->toBeNull();
});

it('leaves a cached config untouched', function () {
    // A cached config is already fully resolved. Backfilling into it would
    // re-read the package config on every request and, because env() returns
    // null once cached, risk writing empty strings over real cached values.
    $app = app();

    $app['config']->set('updates.signing', ['trusted_keys' => []]);

    // What Application::configurationIsCached() consults first, so this flips
    // the real code path rather than mocking the provider's collaborator.
    $app->instance('config_loaded_from_cache', true);

    (new UpdateServiceProvider($app))->register();

    expect($app['config']->get('updates.signing'))
        ->toBe(['trusted_keys' => []]);
});
