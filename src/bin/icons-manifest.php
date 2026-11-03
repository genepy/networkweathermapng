<?php
/**
 * NetworkWeathermapNG - write the lists of the icons and backgrounds
 * shipped with the project
 *
 *   php src/bin/icons-manifest.php
 *
 * The editor does not remove a shipped image: the next deployment would
 * bring it back. The lists (app/Services/Images/shipped-icons.php and
 * shipped-backgrounds.php) hold the images of public/objects and
 * public/backgrounds tracked by git; run this after adding or removing an
 * icon set or a background. Tests check that the lists are up to date.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$srcRoot = dirname(__DIR__);

foreach (['objects' => 'icons', 'backgrounds' => 'backgrounds'] as $folder => $kind) {
    $files = [];
    exec('git -C ' . escapeshellarg($srcRoot) . ' ls-files -- public/' . $folder, $files, $status);
    if ($status !== 0) {
        fwrite(STDERR, "git ls-files failed: run this from a git checkout\n");
        exit(1);
    }

    $images = [];
    foreach ($files as $file) {
        $path = substr($file, strlen('public/'));
        if (preg_match('#^' . $folder . '/([^/]+/)?[^/]+\.(png|gif|jpe?g)$#i', $path)) {
            $images[] = $path;
        }
    }
    sort($images);

    $target = $srcRoot . '/app/Services/Images/shipped-' . $kind . '.php';
    $php = "<?php\n"
        . "/**\n"
        . ' * ' . ucfirst($kind) . " shipped with NetworkWeathermapNG, which the editor does not remove.\n"
        . " * Written by src/bin/icons-manifest.php: do not edit by hand.\n"
        . " */\n\n"
        . "return [\n";
    foreach ($images as $image) {
        $php .= '    ' . var_export($image, true) . ",\n";
    }
    $php .= "];\n";

    file_put_contents($target, $php);
    echo count($images) . " $kind written to $target\n";
}
