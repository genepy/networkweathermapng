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
    public function toPng(string $svg, int $maxSide = self::MAX_SIDE): ?string
    {
        $dir = sys_get_temp_dir() . '/wm-svg-' . bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0700)) {
            return null;
        }
        $in = $dir . '/in.svg';
        $out = $dir . '/out.png';
        file_put_contents($in, $svg);

        try {
            $command = $this->commandFor($in, $out, self::targetSize($svg, $maxSide) ?? ($maxSide < self::MAX_SIDE ? [$maxSide, $maxSide] : null));
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

    /**
     * Size to draw at: the SVG's own size, scaled down to fit $maxSide.
     * Null when the SVG gives no usable size (the converter's default then).
     *
     * @return array{int, int}|null
     */
    public static function targetSize(string $svg, int $maxSide = self::MAX_SIDE): ?array
    {
        if (!preg_match('/<svg\b[^>]*>/is', $svg, $tag)) {
            return null;
        }
        $attr = function (string $name) use ($tag): ?float {
            if (preg_match('/\s' . $name . '\s*=\s*["\']\s*([0-9.]+(?:e[0-9]+)?)\s*(px)?\s*["\']/i', $tag[0], $m)) {
                return (float) $m[1];
            }
            return null;
        };
        $width = $attr('width');
        $height = $attr('height');
        if (($width === null || $height === null)
            && preg_match('/\sviewBox\s*=\s*["\']\s*[-0-9.e]+[\s,]+[-0-9.e]+[\s,]+([0-9.e]+)[\s,]+([0-9.e]+)\s*["\']/i', $tag[0], $m)) {
            $vw = (float) $m[1];
            $vh = (float) $m[2];
            if ($vw > 0 && $vh > 0) {
                $width ??= $height !== null ? $height * $vw / $vh : $vw;
                $height ??= $width * $vh / $vw;
            }
        }
        if (!$width || !$height || $width <= 0 || $height <= 0) {
            return null;
        }

        $scale = min(1.0, min($maxSide, self::MAX_SIDE) / max($width, $height));

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    /**
     * @param array{int, int}|null $size
     * @return list<string>|null
     */
    private function commandFor(string $in, string $out, ?array $size = null): ?array
    {
        if ($this->command !== null) {
            return array_map(fn(string $a): string => str_replace(['{in}', '{out}'], [$in, $out], $a), $this->command);
        }

        // the drawing is always bounded: the SVG's own size, scaled down to MAX_SIDE
        [$w, $h] = $size ?? [self::MAX_SIDE, self::MAX_SIDE];
        if ($bin = self::find('rsvg-convert')) {
            return [$bin, '--format=png', '--width=' . $w, '--height=' . $h, '--keep-aspect-ratio', '--output=' . $out, $in];
        }
        if ($bin = self::find('magick') ?? self::find('convert')) {
            // ImageMagick draws at full size before resizing: cap what it may allocate
            return [$bin, '-limit', 'width', '8192', '-limit', 'height', '8192', '-limit', 'area', '64MP',
                '-background', 'none', 'svg:' . $in, '-resize', $w . 'x' . $h . '>', 'png:' . $out];
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
