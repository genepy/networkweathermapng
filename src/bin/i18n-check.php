<?php
/**
 * NetworkWeathermapNG - check the translation catalogues
 *
 *   php src/bin/i18n-check.php [--strict] [locale...]
 *
 * Collects the interface strings of the code:
 *   - PHP: __('...'), e__('...') and I18n::forScript(['...', ...])
 *   - browser scripts: t('...') and fmt('...') in assets/js/*.js and in the views
 * and compares them with the catalogues of app/Lang/<locale>/:
 *   - PHP strings with the files not named *-js.php
 *   - script strings with the *-js.php files (served by I18n::section())
 *     and with the strings the views pass through I18n::forScript()
 *
 * Prints the missing and unused keys of each locale (all of them when no
 * locale is given). Strings built at run time (__($text)) cannot be seen,
 * so an "unused" key may still be used: check before removing it.
 *
 *   --strict   exit with status 1 when a key is missing
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$srcRoot = dirname(__DIR__);
$langRoot = $srcRoot . '/app/Lang';
$codePattern = '/^[a-z]{2,3}(_[A-Z]{2})?$/'; // same as App\Core\I18n::CODE_PATTERN

$strict = false;
$wanted = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--strict') {
        $strict = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php src/bin/i18n-check.php [--strict] [locale...]\n");
        exit(0);
    } else {
        $wanted[] = $arg;
    }
}

/** Value of a quoted PHP or JS string literal, quotes included */
function literal(string $quoted): string
{
    $q = $quoted[0];
    $body = substr($quoted, 1, -1);
    if ($q === "'") {
        // PHP single quotes know only \' and \\; JS also knows \n, \u...
        return (string) preg_replace_callback('/\\\\(u[0-9a-fA-F]{4}|.)/s', static function (array $m): string {
            $c = $m[1];
            if ($c === "'" || $c === '\\') {
                return $c;
            }
            if ($c[0] === 'u' && strlen($c) === 5) {
                return mb_chr((int) hexdec(substr($c, 1)), 'UTF-8');
            }
            return '\\' . $c;
        }, $body);
    }
    return stripcslashes($body);
}

const STRING_RE = '(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")';

/** @return list<string> files below $dir whose name matches $pattern */
function files(string $dir, string $pattern): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && preg_match($pattern, $file->getFilename())) {
            $out[] = $file->getPathname();
        }
    }
    sort($out);
    return $out;
}

/**
 * @param array<string, list<string>> $keys key => files using it
 */
function collect(string $code, string $regex, string $file, string $root, array &$keys): void
{
    if (preg_match_all($regex, $code, $m)) {
        foreach ($m[1] as $quoted) {
            $keys[literal($quoted)][] = substr($file, strlen($root) + 1);
        }
    }
}

// Strings used by the code
$phpKeys = [];
$jsKeys = [];
foreach (files($srcRoot . '/app', '/\.php$/') as $file) {
    if (str_starts_with($file, $langRoot . '/')) {
        continue;
    }
    $code = (string) file_get_contents($file);
    collect($code, '/(?<![\w$>:])e?__\(\s*' . STRING_RE . '/', $file, $srcRoot, $phpKeys);
    if (preg_match_all('/forScript\(\s*\[(.*?)\]\s*\)/s', $code, $lists)) {
        foreach ($lists[1] as $list) {
            collect($list, '/' . STRING_RE . '/', $file, $srcRoot, $phpKeys);
        }
    }
    if (str_contains($file, '/Views/')) {
        // t('...') of the scripts written in the views
        collect($code, '/(?<![\w$.>:])(?:t|fmt)\(\s*' . STRING_RE . '/', $file, $srcRoot, $jsKeys);
    }
}
foreach (files($srcRoot . '/public/assets/js', '/\.js$/') as $file) {
    collect((string) file_get_contents($file), '/(?<![\w$.])(?:t|fmt)\(\s*' . STRING_RE . '/', $file, $srcRoot, $jsKeys);
}

// Locales
$locales = [];
foreach (glob($langRoot . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
    $code = basename($dir);
    if ($code !== 'en' && preg_match($codePattern, $code) && (!$wanted || in_array($code, $wanted, true))) {
        $locales[$code] = $dir;
    }
}
foreach ($wanted as $code) {
    if (!isset($locales[$code])) {
        fwrite(STDERR, "Unknown locale: $code (no folder app/Lang/$code)\n");
        exit(2);
    }
}

printf("Strings in the code: %d PHP, %d script\n", count($phpKeys), count($jsKeys));

$anyMissing = false;
foreach ($locales as $code => $dir) {
    $php = [];
    $js = [];
    $problems = [];
    if (!is_file($dir . '/_meta.php')) {
        $problems[] = '_meta.php is missing (it gives the language name)';
    }
    foreach (glob($dir . '/*.php') ?: [] as $file) {
        $name = basename($file);
        if ($name[0] === '_') {
            continue;
        }
        $entries = require $file;
        if (!is_array($entries)) {
            $problems[] = "$name does not return an array";
            continue;
        }
        foreach ($entries as $key => $value) {
            if (str_ends_with($name, '-js.php')) {
                $js[(string) $key] = $name;
            } else {
                $php[(string) $key] = $name;
            }
        }
    }

    // Script strings may also come from the PHP catalogue through forScript()
    $missingPhp = array_diff_key($phpKeys, $php);
    $missingJs = array_diff_key($jsKeys, $js, $php);
    $unusedPhp = array_diff_key($php, $phpKeys);
    $unusedJs = array_diff_key($js, $jsKeys);
    $missing = count($missingPhp) + count($missingJs);
    $anyMissing = $anyMissing || $missing > 0 || $problems;

    printf("\n[%s] %d translated, %d missing, %d unused\n", $code, count($php) + count($js), $missing, count($unusedPhp) + count($unusedJs));
    foreach ($problems as $p) {
        echo "  ! $p\n";
    }
    foreach (['missing (PHP)' => $missingPhp, 'missing (script)' => $missingJs] as $label => $list) {
        foreach ($list as $key => $where) {
            printf("  %s: %s   <- %s\n", $label, var_export((string) $key, true), implode(', ', array_unique($where)));
        }
    }
    foreach (['unused' => $unusedPhp + $unusedJs] as $label => $list) {
        ksort($list);
        foreach ($list as $key => $file) {
            printf("  %s: %s   (%s)\n", $label, var_export((string) $key, true), $file);
        }
    }
}

exit($strict && $anyMissing ? 1 : 0);
