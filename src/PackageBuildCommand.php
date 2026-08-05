<?php

namespace InstallerToolkit;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InstallerToolkit\Concerns\LoadsPackageConfig;
use InstallerToolkit\Update\UpdateSignature;
use Symfony\Component\Process\Process;
use ZipArchive;

abstract class PackageBuildCommand extends Command
{
    use LoadsPackageConfig;

    protected $signature = 'package:build {--output=package : Output directory for the distributable files} {--toolkit=~/php/installer-toolkit : Path to the installer-toolkit directory}';

    protected $description = 'Build the distributable package (zip + installer + readme)';

    /** @var array<string> */
    protected array $excludeDirs = [
        '.git',
        'node_modules',
        'vendor',
        'tests',
        'package',
        'docs',
        'demos',
        'reports',
        '.claude',
        '.github',
        '.vscode',
        '.kilo',
        '.migration',
    ];

    /**
     * App-specific directories to exclude in addition to $excludeDirs, so
     * subclasses don't have to redeclare the shared list just to add one entry.
     *
     * @var array<string>
     */
    protected array $extraExcludeDirs = [];

    /** @var array<string> */
    protected array $excludeStoragePaths = [
        'app',
        'debugbar',
        'logs',
        'framework/cache/data',
        'framework/sessions',
        'framework/views',
    ];

    /** @var array<string> */
    protected array $includeStorageFiles = [];

    /** @var array<string> */
    protected array $includeRootFiles = [
        'artisan',
        'composer.json',
        'composer.lock',
        '.env.install',
    ];

    /**
     * update.php and the shared_* helpers are operator tools published from
     * the toolkit's stubs (or their legacy per-app equivalents). They run
     * privileged Artisan commands, so they must never ship inside a customer
     * package — the operator uploads them explicitly when needed.
     *
     * updater.php is excluded from the *copy* for a different reason: a
     * stale copy in the app checkout (e.g. laid down by a local install run)
     * must never win over the freshly-generated one embedTooling() stages.
     *
     * @var array<string>
     */
    protected array $excludePublicFiles = [
        'hot',
        'update.php',
        'updater.php',
        'shared_update.php',
        'shared_install.php',
    ];

    protected string $slug;

    /** @var array<string, mixed> */
    protected array $config;

    public function handle(): int
    {
        if ($configError = $this->loadPackageConfig()) {
            $this->error($configError);

            return self::FAILURE;
        }

        $version = (string) config('app.version');

        if (! $this->isSemVer($version)) {
            $this->error("Invalid version format: '{$version}'. Expected SemVer (e.g., 1.2.0). Set 'version' in config/app.php.");

            return self::FAILURE;
        }

        $this->info("Building distributable package v{$version} for {$this->config['name']}...");
        $this->newLine();

        $basePath = base_path();
        $outputDir = $this->resolveOutputDir($this->option('output'));
        $stagingDir = storage_path('app/package-build-'.uniqid());
        $toolkitOutputDir = storage_path('app/package-toolkit-'.uniqid());
        $demoConfig = $this->config['demo'] ?? null;

        try {
            $this->runToolkit($basePath, $toolkitOutputDir);

            $this->buildFrontend($basePath);

            $this->info('Creating staging directory...');
            File::ensureDirectoryExists($stagingDir.'/'.$this->slug);

            $this->copyProjectFiles($basePath, $stagingDir.'/'.$this->slug, $stagingDir);

            $this->installProductionDependencies($stagingDir.'/'.$this->slug);

            $this->embedTooling($toolkitOutputDir, $stagingDir.'/'.$this->slug);

            $zipPath = $stagingDir.'/'.$this->slug.'-full.zip';
            $this->createZip($stagingDir, $this->slug, $zipPath);

            $demoZipPath = null;
            $updateZipPath = $zipPath;

            if ($demoConfig['enabled'] ?? false) {
                // Omitting the license key file is what puts a demo/update install
                // into trial mode: LicenseManager::read() returns null when the file
                // is absent, and the app falls back to a time-limited countdown.
                // This is deliberate licensing behavior, not incidental packaging —
                // do not repurpose this key for arbitrary file exclusions.
                $excludeFiles = isset($demoConfig['license_key_path'])
                    ? [$demoConfig['license_key_path']]
                    : [];

                // The demo zip and the update zip share the same exclusions, so
                // they're byte-identical content — build once and reuse the file
                // for both purposes instead of re-walking and re-compressing.
                $demoZipPath = $stagingDir.'/'.$this->slug.'-demo-full.zip';
                $this->info('Creating demo zip archive (without license key)...');
                $this->createZip($stagingDir, $this->slug, $demoZipPath, $excludeFiles);

                $updateZipPath = $demoZipPath;
            }

            $manifestPath = $this->generateManifest($stagingDir, $updateZipPath, $version);

            $this->assembleOutput($toolkitOutputDir, $zipPath, $demoZipPath, $manifestPath, $outputDir, $version, $updateZipPath);

            $this->info('Cleaning up staging directory...');
            File::deleteDirectory($stagingDir);
            File::deleteDirectory($toolkitOutputDir);

            $this->newLine();
            $this->info('Package built successfully!');
            $this->newLine();
            $this->printSummary($outputDir, $version, $demoZipPath !== null);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Build failed: '.$e->getMessage());

            if (is_dir($stagingDir)) {
                File::deleteDirectory($stagingDir);
            }

            if (is_dir($toolkitOutputDir)) {
                File::deleteDirectory($toolkitOutputDir);
            }

            return self::FAILURE;
        }
    }

    protected function runToolkit(string $basePath, string $toolkitOutputDir): void
    {
        $toolkitOption = $this->option('toolkit');

        if (str_starts_with($toolkitOption, '~')) {
            $home = getenv('HOME');

            if ($home === false || $home === '') {
                throw new \RuntimeException('Cannot resolve "~" in --toolkit path: the HOME environment variable is not set. Pass an absolute --toolkit path instead.');
            }

            $toolkitOption = $home.substr($toolkitOption, 1);
        }

        $buildScript = $toolkitOption.'/bin/build';

        if (! file_exists($buildScript)) {
            throw new \RuntimeException("Installer toolkit not found at: {$toolkitOption}");
        }

        $this->info('Running installer toolkit...');

        $process = new Process(['php', $buildScript, $basePath, $toolkitOutputDir], $basePath);
        $process->setTimeout(60);
        $process->run(function ($type, $buffer): void {
            if ($type === Process::OUT) {
                $this->output->write($buffer);
            }
        });

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Installer toolkit failed: '.$process->getErrorOutput());
        }
    }

    protected function buildFrontend(string $basePath): void
    {
        $this->info('Building frontend assets...');

        $process = new Process(['npm', 'run', 'build'], $basePath);
        $process->setTimeout(300);
        $process->run(function ($type, $buffer): void {
            if ($type === Process::OUT) {
                $this->output->write($buffer);
            }
        });

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('npm run build failed: '.$process->getErrorOutput());
        }

        $this->info('Frontend assets built.');
    }

    protected function copyProjectFiles(string $source, string $destination, string $stagingDir = ''): void
    {
        $this->info('Copying project files to staging...');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $stagingRelative = $stagingDir ? substr($stagingDir, strlen($source) + 1) : '';

        $count = 0;
        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($source) + 1);

            if ($stagingRelative && str_starts_with($relativePath, $stagingRelative)) {
                continue;
            }

            if ($this->shouldExclude($relativePath)) {
                continue;
            }

            $targetPath = $destination.'/'.$relativePath;

            if ($item->isDir()) {
                File::ensureDirectoryExists($targetPath);
            } else {
                File::ensureDirectoryExists(dirname($targetPath));
                copy($item->getPathname(), $targetPath);
                $count++;
            }
        }

        $this->info("Copied {$count} files.");
    }

    protected function shouldExclude(string $relativePath): bool
    {
        foreach ([...$this->excludeDirs, ...$this->extraExcludeDirs] as $dir) {
            if (str_starts_with($relativePath, $dir.'/') || $relativePath === $dir) {
                return true;
            }
        }

        if (! str_contains($relativePath, '/')) {
            if (! in_array($relativePath, $this->includeRootFiles)) {
                return true;
            }
        }

        foreach ($this->excludePublicFiles as $file) {
            if ($relativePath === 'public/'.$file) {
                return true;
            }
        }

        if (str_starts_with($relativePath, 'bootstrap/cache/') && ! str_ends_with($relativePath, '.gitkeep')) {
            return true;
        }

        if (str_starts_with($relativePath, 'database/') && str_contains($relativePath, '.sqlite')) {
            return true;
        }

        if (str_starts_with($relativePath, 'storage/')) {
            $storagePath = substr($relativePath, strlen('storage/'));

            if (str_starts_with($storagePath, 'app/package-build-')) {
                return true;
            }

            foreach ($this->excludeStoragePaths as $excluded) {
                if (str_starts_with($storagePath, $excluded.'/') || $storagePath === $excluded) {
                    if (in_array($storagePath, $this->includeStorageFiles)) {
                        return false;
                    }

                    $basename = basename($relativePath);

                    return $basename !== '.gitignore' && $basename !== '.gitkeep';
                }
            }
        }

        return false;
    }

    protected function installProductionDependencies(string $directory): void
    {
        $this->info('Installing production dependencies (this may take a minute)...');

        // Path repositories in the staged lock use relative urls (e.g.
        // ../update-toolkit) that don't resolve from the staging directory.
        // composer install fetches path-repo packages from the lock's dist.url,
        // so rewrite those to absolute paths before installing.
        $this->rewritePathDistsInLock($directory.'/composer.lock');

        $process = new Process(
            ['composer', 'install', '--no-dev', '--no-scripts', '--optimize-autoloader', '--no-interaction'],
            $directory
        );
        $process->setTimeout(600);
        $process->run(function ($type, $buffer): void {
            if ($type === Process::OUT) {
                $this->output->write($buffer);
            }
        });

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('composer install failed: '.$process->getErrorOutput());
        }

        // composer installs path repositories as symlinks by default; the zip
        // archiver does not follow symlinks, so replace any symlinked vendor
        // directories with real copies so the distributable ships real files.
        $this->materializeSymlinkedVendors($directory);

        $this->info('Production dependencies installed.');
    }

    /**
     * Replace symlinked vendor directories with real copies.
     *
     * composer installs `path` repositories as symlinks by default, but the zip
     * archiver uses RecursiveDirectoryIterator without FOLLOW_SYMLINKS, so a
     * symlinked vendor package would be archived as an empty directory and the
     * distributable would ship without its files. Materializing the symlinks
     * guarantees real files in the built package.
     */
    protected function materializeSymlinkedVendors(string $directory): void
    {
        $vendorPath = $directory.'/vendor';

        if (! is_dir($vendorPath)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($vendorPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $symlinks = [];

        foreach ($iterator as $item) {
            if ($item->isLink() && $item->isDir()) {
                $symlinks[] = $item->getPathname();
            }
        }

        foreach ($symlinks as $link) {
            $target = realpath($link);

            if ($target === false || $target === $link) {
                continue;
            }

            // Remove the symlink (not its target) and copy the real files in.
            @unlink($link);

            if (! file_exists($link)) {
                $this->copyPackageSource($target, $link);
            }
        }
    }

    /**
     * Copy a path-repo package into vendor, excluding its own dev artifacts.
     *
     * A path repository points at a working checkout that contains its own
     * vendor/, tests/, and .git/ — none of which belong in a distributable.
     * vendor/ would bloat the package (and may duplicate dependencies), and
     * .env may hold secrets such as the update signing key. Only the package
     * source is copied.
     */
    protected function copyPackageSource(string $source, string $destination): void
    {
        $exclude = ['vendor', 'tests', 'Tests', '.git', 'node_modules'];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $topSegment = explode('/', $relative)[0];

            if (in_array($topSegment, $exclude, true)) {
                continue;
            }

            if ($relative === '.env') {
                continue;
            }

            $dest = $destination.'/'.$relative;

            if ($item->isDir()) {
                File::ensureDirectoryExists($dest);
            } else {
                File::ensureDirectoryExists(dirname($dest));
                copy($item->getPathname(), $dest);
            }
        }
    }

    /**
     * Rewrite relative `path` dist urls in composer.lock to absolute paths.
     */
    protected function rewritePathDistsInLock(string $lockPath): void
    {
        if (! file_exists($lockPath)) {
            return;
        }

        $lock = json_decode((string) file_get_contents($lockPath), true);

        if (! is_array($lock)) {
            return;
        }

        $changed = false;

        foreach (['packages', 'packages-dev'] as $section) {
            if (! isset($lock[$section])) {
                continue;
            }

            foreach ($lock[$section] as &$package) {
                if (($package['dist']['type'] ?? '') !== 'path') {
                    continue;
                }

                $url = (string) ($package['dist']['url'] ?? '');

                if ($url === '' || str_starts_with($url, '/') || parse_url($url, PHP_URL_SCHEME) !== null) {
                    continue;
                }

                $absolute = realpath(base_path().'/'.$url);

                if ($absolute === false) {
                    continue;
                }

                $package['dist']['url'] = $absolute;
                $changed = true;
            }

            unset($package);
        }

        if ($changed) {
            file_put_contents(
                $lockPath,
                json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
            );
        }
    }

    /**
     * Stage the toolkit-generated update tooling into the app tree before
     * zipping, so it ships inside every package (full, demo, and .update):
     *
     *  - public/updater.php — the standalone updater. Fresh installs lay it
     *    down via extraction; updates refresh it (the running updater swaps
     *    itself at finalize, since extraction skips the executing file).
     *  - .updater/post_update.php — the hook updater.php includes after
     *    extraction to boot the new code once for migrations/caches. Living
     *    inside the inner zip puts it under the manifest's Ed25519 signature.
     */
    protected function embedTooling(string $toolkitOutputDir, string $stagedAppDir): void
    {
        $updater = $toolkitOutputDir.'/updater.php';
        $hook = $toolkitOutputDir.'/post_update.php';

        if (! file_exists($updater) || ! file_exists($hook)) {
            throw new \RuntimeException('updater.php/post_update.php not found in the toolkit output. Toolkit build may have failed.');
        }

        File::ensureDirectoryExists($stagedAppDir.'/public');
        File::ensureDirectoryExists($stagedAppDir.'/.updater');

        copy($updater, $stagedAppDir.'/public/updater.php');
        copy($hook, $stagedAppDir.'/.updater/post_update.php');

        $this->info('Embedded updater.php and post_update hook.');
    }

    /**
     * @param  array<string>  $excludeFiles  Files to exclude from the zip, relative to the app folder.
     */
    protected function createZip(string $stagingDir, string $appFolder, string $zipPath, array $excludeFiles = []): void
    {
        $this->info('Creating zip archive...');

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create zip file.');
        }

        $sourceDir = $stagingDir.'/'.$appFolder;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $fileCount = 0;
        foreach ($iterator as $item) {
            $fileRelativePath = substr($item->getPathname(), strlen($sourceDir) + 1);
            $relativePath = $appFolder.'/'.$fileRelativePath;

            if ($item->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                if (in_array($fileRelativePath, $excludeFiles)) {
                    continue;
                }

                $zip->addFile($item->getPathname(), $relativePath);
                $fileCount++;
            }
        }

        $zip->close();

        $size = $this->formatBytes(filesize($zipPath));
        $this->info("Zip created: {$fileCount} files, {$size}");
    }

    protected function assembleOutput(string $toolkitOutputDir, string $zipPath, ?string $demoZipPath, string $manifestPath, string $outputDir, string $version, string $updateZipPath): void
    {
        $this->info('Assembling output...');

        $packagesDir = $outputDir.'/packages';
        File::ensureDirectoryExists($packagesDir);

        $installFile = $toolkitOutputDir.'/install.php';
        $readmeFile = $toolkitOutputDir.'/readme.html';

        if (! file_exists($installFile)) {
            throw new \RuntimeException('install.php not found. Toolkit build may have failed.');
        }

        if (! file_exists($readmeFile)) {
            throw new \RuntimeException('readme.html not found. Toolkit build may have failed.');
        }

        $fullZipPath = $packagesDir.'/'.$this->slug.'-v'.$version.'-full.zip';
        $this->createOuterZip($fullZipPath, $zipPath, $installFile, $readmeFile);

        $size = $this->formatBytes(filesize($fullZipPath));
        $this->info("Distributable zip created: {$size}");

        if ($demoZipPath !== null) {
            $demoOuterZipPath = $packagesDir.'/'.$this->slug.'-v'.$version.'-demo.zip';
            $this->createOuterZip($demoOuterZipPath, $demoZipPath, $installFile, $readmeFile);

            $size = $this->formatBytes(filesize($demoOuterZipPath));
            $this->info("Demo distributable zip created: {$size}");
        }

        $updatePackagePath = $packagesDir.'/'.$this->slug.'-v'.$version.'.update';
        $this->createUpdateZip($updatePackagePath, $updateZipPath, $manifestPath);

        $size = $this->formatBytes(filesize($updatePackagePath));
        $this->info("Update package created: {$size}");

        $this->info("Output written to: {$outputDir}");
    }

    protected function createOuterZip(string $outerZipPath, string $innerZipPath, string $installFile, string $readmeFile): void
    {
        $zip = new ZipArchive;
        if ($zip->open($outerZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create distributable zip file.');
        }

        $zip->addFile($innerZipPath, $this->slug.'.zip');
        $zip->addFile($installFile, 'install.php');
        $zip->addFile($readmeFile, 'readme.html');
        $zip->close();
    }

    protected function generateManifest(string $stagingDir, string $innerZipPath, string $version): string
    {
        $this->info('Generating update manifest...');

        $checksum = hash_file('sha256', $innerZipPath);

        $zip = new ZipArchive;
        $filesCount = 0;
        if ($zip->open($innerZipPath) === true) {
            $filesCount = $zip->numFiles;
            $zip->close();
        }

        $minimumVersion = $this->resolveMinimumVersion();

        $manifest = [
            'type' => 'update',
            'version' => $version,
            'minimum_version' => $minimumVersion,
            'minimum_php' => $this->config['min_php_version'],
            'checksum' => $checksum,
            'built_at' => now()->toIso8601String(),
            'files_count' => $filesCount,
            // Signature-covered via the inner zip's checksum: the hook lives
            // inside {slug}/.updater/, so tampering with it breaks the
            // checksum and therefore the Ed25519 signature.
            'post_update' => '.updater/post_update.php',
        ];

        $manifest = array_merge($manifest, $this->signManifest($version, $minimumVersion, $checksum));

        $manifestPath = $stagingDir.'/manifest.json';
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info('Manifest generated.');

        return $manifestPath;
    }

    /**
     * The manifest's `minimum_version`: the lowest installed version this
     * update package may be applied to (ValidatesUpdatePackage rejects the
     * upload otherwise). Defaults to '1.0.0' — unchanged behavior for apps
     * that don't set this — but is configurable via package-config.php's
     * `minimum_update_version` for a release that needs to raise the floor
     * (e.g. a breaking schema change that only a migration path starting
     * from some later version handles correctly).
     */
    protected function resolveMinimumVersion(): string
    {
        $minimumVersion = (string) ($this->config['minimum_update_version'] ?? '1.0.0');

        if (! $this->isSemVer($minimumVersion)) {
            throw new \RuntimeException("package-config.php's 'minimum_update_version' must be SemVer (e.g. 1.2.0), got: '{$minimumVersion}'.");
        }

        return $minimumVersion;
    }

    protected function isSemVer(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+$/', $version) === 1;
    }

    /**
     * Sign the manifest when the app opts into signing via package-config's
     * `signing_key_id`. Fails the build (never silently ships unsigned) if a
     * key_id is configured but UPDATE_SIGNING_KEY is missing or invalid — a
     * broken CI secret must not quietly downgrade a signed release to
     * unsigned. Apps that haven't set `signing_key_id` build unsigned, same
     * as before this feature existed.
     *
     * @return array{key_id?: string, signature?: string, signature_algorithm?: string}
     */
    protected function signManifest(string $version, string $minimumVersion, string $checksum): array
    {
        $keyId = $this->config['signing_key_id'] ?? null;

        if ($keyId === null) {
            return [];
        }

        $privateKey = $this->resolveSigningKey();

        if ($privateKey === '') {
            throw new \RuntimeException("package-config.php sets 'signing_key_id' => '{$keyId}' but no signing key resolved from config('updates.signing'). Set UPDATE_SIGNING_KEY (from your CI secret) or point UPDATE_SIGNING_KEY_FILE at a key file, and make sure config/updates.php maps them to signing.private_key / signing.private_key_file — a published config that omits those entries resolves empty even when the env vars are set. Or remove signing_key_id to build unsigned.");
        }

        $this->info("Signing manifest with key '{$keyId}'...");

        return UpdateSignature::sign($this->slug, $version, $minimumVersion, $checksum, $keyId, $privateKey);
    }

    /**
     * The private key comes from UPDATE_SIGNING_KEY directly (CI secret), or
     * from the file UPDATE_SIGNING_KEY_FILE points at (local dev, where the
     * key lives outside every distributed project). The file may be a dotenv
     * file containing an UPDATE_SIGNING_KEY= line or hold the bare key. A
     * configured-but-unreadable file fails the build rather than falling
     * through to unsigned.
     */
    protected function resolveSigningKey(): string
    {
        $privateKey = (string) config('updates.signing.private_key', '');

        if ($privateKey !== '') {
            return $privateKey;
        }

        $keyFile = (string) config('updates.signing.private_key_file', '');

        if ($keyFile === '') {
            return '';
        }

        if (! is_readable($keyFile)) {
            throw new \RuntimeException("UPDATE_SIGNING_KEY_FILE points to '{$keyFile}' but the file does not exist or is not readable.");
        }

        $contents = trim((string) file_get_contents($keyFile));

        if (preg_match('/^UPDATE_SIGNING_KEY=(.+)$/m', $contents, $matches) === 1) {
            return trim($matches[1], " \t\"'");
        }

        if (preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $contents) === 1) {
            return $contents;
        }

        throw new \RuntimeException("UPDATE_SIGNING_KEY_FILE '{$keyFile}' does not contain an UPDATE_SIGNING_KEY entry or a bare key.");
    }

    protected function createUpdateZip(string $updateZipPath, string $innerZipPath, string $manifestPath): void
    {
        $zip = new ZipArchive;
        if ($zip->open($updateZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create update zip file.');
        }

        $zip->addFile($manifestPath, 'manifest.json');
        $zip->addFile($innerZipPath, $this->slug.'.zip');
        $zip->close();
    }

    protected function printSummary(string $outputDir, string $version, bool $hasDemoZip): void
    {
        $packagesDir = $outputDir.'/packages';
        $filename = $this->slug.'-v'.$version.'-full.zip';
        $demoFilename = $this->slug.'-v'.$version.'-demo.zip';
        $updateFilename = $this->slug.'-v'.$version.'.update';

        $files = $hasDemoZip ? [$filename, $demoFilename, $updateFilename] : [$filename, $updateFilename];

        $rows = [];
        foreach ($files as $file) {
            $path = $packagesDir.'/'.$file;
            $size = file_exists($path) ? $this->formatBytes(filesize($path)) : 'N/A';
            $rows[] = [$file, $size];
        }

        $this->table(['File', 'Size'], $rows);

        $this->newLine();
        $this->info('Output directory: '.$packagesDir);
        $this->info("Send {$filename} to your customer.");
        if ($hasDemoZip) {
            $this->info("Send {$demoFilename} for demo/trial purposes.");
        }
        $this->info("Send {$updateFilename} to existing customers for updates.");
    }

    /**
     * Deliberately a separate implementation from the framework-free
     * templates/shared/Concerns/ProvidesUtilities::formatBytes(), not
     * duplication to merge: this one is Composer-autoloaded CLI code with a
     * pinned output format (always 2 decimals, zero-guarded — see
     * PackageBuildCommandTest), while the shared trait formats for the
     * installer/updater's wizard UI and must stay dependency-free for
     * bin/build to inline it into a single standalone script.
     */
    protected function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0.00 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return sprintf('%.2f %s', $bytes / pow(1024, $factor), $units[$factor]);
    }
}
