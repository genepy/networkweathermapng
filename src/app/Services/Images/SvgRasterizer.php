<?php
/**
 * NetworkWeathermapNG - draws the PNG copy of an SVG icon. The map engine
 * (GD) cannot read SVG, so every SVG icon needs a PNG twin next to it.
 *
 * Uses rsvg-convert when installed (the Docker image has it), else
 * ImageMagick. Both run without a shell, on an already cleaned SVG.
 */

declare(strict_types=1);

namespace App\Services\Images;

class SvgRasterizer
{
    public const MAX_SIDE = 1024;
    private const TIMEOUT = 15;

    /** @var list<string>|null */
    private ?array $command;

    /** @param list<string>|null $command override for tests: program and arguments, {in} and {out} replaced */
    public function __construct(?array $command = null)
    {
        $this->command = $command;
    }

    public function available(): bool
    {
        return $this->commandFor('in.svg', 'out.png') !== null;
    }

    /** PNG data, or null when no converter is installed or it failed. */
    public function toPng(string $svg): ?string
    {
        $dir = sys_get_temp_dir() . '/wm-svg-' . bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0700)) {
            return null;
        }
        $in = $dir . '/in.svg';
        $out = $dir . '/out.png';
        file_put_contents($in, $svg);

        try {
            $command = $this->commandFor($in, $out);
            if ($command === null || !$this->run($command) || !is_file($out)) {
                return null;
            }
            $png = (string) file_get_contents($out);
            $info = @getimagesizefromstring($png);
            if (!$info || $info[2] !== IMAGETYPE_PNG || $info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE) {
                return null;
            }
            return $png;
        } finally {
            @unlink($in);
            @unlink($out);
            @rmdir($dir);
        }
    }

    /** @return list<string>|null */
    private function commandFor(string $in, string $out): ?array
    {
        if ($this->command !== null) {
            return array_map(fn(string $a): string => str_replace(['{in}', '{out}'], [$in, $out], $a), $this->command);
        }

        $size = (string) self::MAX_SIDE;
        if ($bin = self::find('rsvg-convert')) {
            return [$bin, '--format=png', '--keep-aspect-ratio', '--output=' . $out, $in];
        }
        if ($bin = self::find('magick') ?? self::find('convert')) {
            return [$bin, '-background', 'none', 'svg:' . $in, '-resize', $size . 'x' . $size . '>', 'png:' . $out];
        }

        return null;
    }

    private static function find(string $program): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin') as $dir) {
            if ($dir !== '' && is_file($dir . '/' . $program) && is_executable($dir . '/' . $program)) {
                return $dir . '/' . $program;
            }
        }
        return null;
    }

    /** @param list<string> $command */
    private function run(array $command): bool
    {
        $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) {
            return false;
        }

        $deadline = microtime(true) + self::TIMEOUT;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                proc_close($process);
                return $status['exitcode'] === 0;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);

        proc_terminate($process, 9);
        proc_close($process);
        return false;
    }
}
