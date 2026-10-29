<?php
/**
 * NetworkWeathermapNG - write the list of the icons shipped with the project
 *
 *   php src/bin/icons-manifest.php
 *
 * The editor does not remove a shipped icon: the next deployment would
 * bring it back. The list (app/Services/Images/shipped-icons.php) holds the
 * icons of public/objects tracked by git; run this after adding or removing
 * an icon set. A test checks that the list is up to date.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$srcRoot = dirname(__DIR__);
$target = $srcRoot . '/app/Services/Images/shipped-icons.php';

exec('git -C ' . escapeshellarg($srcRoot) . ' ls-files -- public/objects', $files, $status);
if ($status !== 0) {
    fwrite(STDERR, "git ls-files failed: run this from a git checkout\n");
    exit(1);
}

$icons = [];
foreach ($files as $file) {
    $path = substr($file, strlen('public/'));
    if (preg_match('#^objects/([^/]+/)?[^/]+\.(png|gif|jpe?g)$#i', $path)) {
        $icons[] = $path;
    }
}
sort($icons);

$php = "<?php\n"
    . "/**\n"
    . " * Icons shipped with NetworkWeathermapNG, which the editor does not remove.\n"
    . " * Written by src/bin/icons-manifest.php: do not edit by hand.\n"
    . " */\n\n"
    . "return [\n";
foreach ($icons as $icon) {
    $php .= '    ' . var_export($icon, true) . ",\n";
}
$php .= "];\n";

file_put_contents($target, $php);
echo count($icons) . " icons written to $target\n";
