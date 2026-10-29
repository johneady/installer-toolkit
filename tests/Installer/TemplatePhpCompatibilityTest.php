<?php

// The generated install.php/updater.php is one file, so PHP compiles all of it
// before any line runs. Anything that fails to compile on an old host stops
// the requirements page from ever rendering, and the customer sees a fatal
// error instead of "PHP 8.5 required". Trait constants are the one PHP 8.2+
// construct the templates had; they belong on the class in class.php.
test('template traits declare no constants, so generated files compile on PHP 8.0', function () {
    $traitFiles = glob(toolkitRoot().'/templates/*/Concerns/*.php');

    expect($traitFiles)->not->toBeEmpty();

    $offenders = [];

    foreach ($traitFiles as $file) {
        if (preg_match_all('/^\s*(?:(?:public|protected|private|final)\s+)*const\s+(\w+)/m', file_get_contents($file), $matches)) {
            foreach ($matches[1] as $name) {
                $offenders[] = basename(dirname($file, 2)).'/Concerns/'.basename($file).': '.$name;
            }
        }
    }

    expect($offenders)->toBe([]);
});
