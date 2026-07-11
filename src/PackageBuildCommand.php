<?php

namespace InstallerToolkit;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InstallerToolkit\Concerns\LoadsPackageConfig;
use Symfony\Component\Process\Process;
use UpdateToolkit\UpdateSignature;
use ZipArchive;

abstract class PackageBuildCommand extends Command
{
    use LoadsPackageConfig;

    protected $signature = 'package:build {--output=package : Output directory for the distributable files} {--toolkit=~/php/installer-toolkit : Path to the installer-toolkit directory}';

    protected $description = 'Build the distributable package (zip + installer + readme)';

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

    protected array $excludeStoragePaths = [
        'app',
        'debugbar',
        'logs',
        'framework/cache/data',
        'framework/sessions',
        'framework/views',
    ];

    protected array $includeStorageFiles = [];

    protected array $includeRootFiles = [
        'artisan',
        'composer.json',
        'composer.lock',
        '.env.install',
    ];

    /**
     * update.php and the shared_* helpers are operator tools published from
     * update-toolkit's stubs (or their legacy per-app equivalents). They run
     * privileged Artisan commands, so they must never ship inside a customer
     * package — the operator uploads them explicitly when needed.
     */
    protected array $excludePublicFiles = [
        'hot',
        'update.php',
        'shared_update.php',
        'shared_install.php',
    ];

    protected string $slug;

    protected array $config;

    public function handle(): int
    {
        if ($configError = $this->loadPackageConfig()) {
            $this->error($configError);

            return self::FAILURE;
        }

        $version = config('app.version');

        if (! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
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

        $this->info('Production dependencies installed.');
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

        $minimumVersion = '1.0.0';

        $manifest = [
            'type' => 'update',
            'version' => $version,
            'minimum_version' => $minimumVersion,
            'minimum_php' => $this->config['min_php_version'] ?? '8.3.0',
            'checksum' => $checksum,
            'built_at' => now()->toIso8601String(),
            'files_count' => $filesCount,
        ];

        $manifest = array_merge($manifest, $this->signManifest($version, $minimumVersion, $checksum));

        $manifestPath = $stagingDir.'/manifest.json';
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info('Manifest generated.');

        return $manifestPath;
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
            throw new \RuntimeException("package-config.php sets 'signing_key_id' => '{$keyId}' but the UPDATE_SIGNING_KEY environment variable is not set. Set it (from your CI secret), point UPDATE_SIGNING_KEY_FILE at a key file, or remove signing_key_id to build unsigned.");
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
        $privateKey = (string) env('UPDATE_SIGNING_KEY', '');

        if ($privateKey !== '') {
            return $privateKey;
        }

        $keyFile = (string) env('UPDATE_SIGNING_KEY_FILE', '');

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
