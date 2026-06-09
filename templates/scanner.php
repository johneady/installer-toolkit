<?php

/**
 * PHP Missing Dependency Auditor
 * ONLY reports on functions called in code that are MISSING locally.
 */

// 1. Static Dictionary for Extensions (To detect what ISN'T there)
$staticDb = [
    'bcmath' => ['bcadd', 'bcsub', 'bcmul', 'bcdiv', 'bcpow', 'bcsqrt', 'bccomp'],
    'curl' => ['curl_init', 'curl_exec', 'curl_setopt', 'curl_close', 'curl_getinfo'],
    'gd' => ['imagecreate', 'imagepng', 'imagejpeg', 'imagewebp', 'imagecopyresampled', 'getimagesize'],
    'imagick' => ['imagick', 'newimagick', 'imagick_readimage'],
    'intl' => ['idn_to_ascii', 'idn_to_utf8', 'numfmt_create', 'collator_create', 'msgfmt_format'],
    'mbstring' => ['mb_strlen', 'mb_substr', 'mb_convert_encoding', 'mb_detect_encoding', 'mb_strpos', 'mb_strtolower'],
    'mysqli' => ['mysqli_connect', 'mysqli_query', 'mysqli_fetch_assoc', 'mysqli_real_escape_string'],
    'soap' => ['is_soap_fault', 'use_soap_error_handler', 'soapclient'],
    'zip' => ['zip_open', 'zip_read', 'zip_close', 'zip_entry_open'],
    'redis' => ['redis', 'phpredis'],
    'gmp' => ['gmp_add', 'gmp_sub', 'gmp_mul', 'gmp_init'],
    'sqlite3' => ['sqlite3_open'],
    'exif' => ['exif_read_data', 'exif_imagetype'],
    'iconv' => ['iconv', 'iconv_strlen'],
];

$safetyMap = [
    'bcmath' => 'Gen. Safe', 'curl' => 'Safe', 'gd' => 'Safe', 'imagick' => 'Optional',
    'intl' => 'Gen. Safe', 'mbstring' => 'Safe', 'mysqli' => 'Safe', 'soap' => 'Optional',
    'zip' => 'Gen. Safe', 'redis' => 'Optional', 'gmp' => 'Optional', 'sqlite3' => 'Gen. Safe',
    'exif' => 'Gen. Safe', 'iconv' => 'Safe',
];

// 2. Build Lookup and Identify Local Environment
$lookup = [];
$loadedExts = array_map('strtolower', get_loaded_extensions());

foreach ($staticDb as $ext => $funcs) {
    foreach ($funcs as $f) {
        $lookup[strtolower($f)] = $ext;
    }
}

// 3. Scanner
$restrictedFunctions = ['exec', 'shell_exec', 'passthru', 'proc_open', 'proc_close', 'system', 'popen'];
$restrictedReport = [];
$missingReport = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__));

$extraFiles = [
    __DIR__.'/package/install.php',
];

echo 'Searching for missing PHP dependencies...'.PHP_EOL;

$filesToScan = function () use ($it, $extraFiles) {
    foreach ($it as $file) {
        yield $file;
    }
    foreach ($extraFiles as $path) {
        if (file_exists($path)) {
            yield new SplFileInfo($path);
        }
    }
};

foreach ($filesToScan() as $file) {
    if ($file->getExtension() !== 'php' || $file->getFilename() === basename(__FILE__)) {
        continue;
    }

    $content = file_get_contents($file->getPathname());
    $tokens = @token_get_all($content);

    foreach ($tokens as $i => $token) {
        if (is_array($token) && $token[0] === T_STRING) {
            $name = strtolower($token[1]);

            if (isset($lookup[$name])) {
                $ext = $lookup[$name];

                // FILTER: Only proceed if the extension is NOT loaded locally
                if (! in_array($ext, $loadedExts)) {
                    $next = $tokens[$i + 1] ?? null;
                    if ($next === '(') {
                        $relativeFile = str_replace(__DIR__, '.', $file->getPathname());
                        $missingReport[] = [
                            'func' => $name,
                            'ext' => strtoupper($ext),
                            'safety' => $safetyMap[$ext] ?? 'Check Host',
                            'file' => $relativeFile,
                            'line' => $token[2],
                        ];
                    }
                }
            }

            // Check for restricted core functions (disabled via disable_functions in php.ini)
            if (in_array($name, $restrictedFunctions)) {
                $next = $tokens[$i + 1] ?? null;
                if ($next === '(') {
                    $disabled = array_map('trim', explode(',', ini_get('disable_functions')));
                    if (in_array($name, $disabled)) {
                        $relativeFile = str_replace(__DIR__, '.', $file->getPathname());
                        $restrictedReport[] = [
                            'func' => $name,
                            'file' => $relativeFile,
                            'line' => $token[2],
                        ];
                    }
                }
            }
        }
    }
}

// 4. Output Filtered Report
if (empty($missingReport)) {
    echo "\033[32m✔ SUCCESS: No missing dependencies found. All detected functions match your local PHP environment.\033[0m".PHP_EOL;
} else {
    echo str_repeat('=', 100).PHP_EOL;
    printf("\033[31m%-25s | %-12s | %-12s | %-40s\033[0m\n", 'Missing Function', 'Extension', 'Host Safety', 'Location (File:Line)');
    echo str_repeat('-', 100).PHP_EOL;

    foreach ($missingReport as $item) {
        printf(
            "%-25s | %-12s | %-12s | %-40s\n",
            $item['func'],
            $item['ext'],
            $item['safety'],
            $item['file'].':'.$item['line']
        );
    }

    echo str_repeat('=', 100).PHP_EOL;
    $uniqueMissing = array_unique(array_column($missingReport, 'ext'));
    echo "\033[33mACTION REQUIRED:\033[0m Install or enable these extensions locally: ".implode(', ', $uniqueMissing).PHP_EOL;
}

// 5. Restricted Core Functions Report
if (! empty($restrictedReport)) {
    echo PHP_EOL.str_repeat('=', 100).PHP_EOL;
    printf("\033[31m%-20s | %-55s\033[0m\n", 'Disabled Function', 'Location (File:Line)');
    echo str_repeat('-', 100).PHP_EOL;

    foreach ($restrictedReport as $item) {
        printf("%-20s | %-55s\n", $item['func'], $item['file'].':'.$item['line']);
    }

    echo str_repeat('=', 100).PHP_EOL;
    $uniqueRestricted = array_unique(array_column($restrictedReport, 'func'));
    echo "\033[33mWARNING:\033[0m These core functions are used in the codebase but disabled via disable_functions: ".implode(', ', $uniqueRestricted).PHP_EOL;
    echo "\033[33m        \033[0m Remove them from disable_functions in php.ini — required by Laravel Scheduler and Symfony Process.".PHP_EOL;
} elseif (! empty($restrictedFunctions)) {
    $disabled = array_filter($restrictedFunctions, fn ($f) => in_array($f, array_map('trim', explode(',', ini_get('disable_functions')))));
    if (empty($disabled)) {
        echo "\033[32m✔ Restricted functions check: none of exec/shell_exec/proc_open/etc. are disabled.\033[0m".PHP_EOL;
    }
}
