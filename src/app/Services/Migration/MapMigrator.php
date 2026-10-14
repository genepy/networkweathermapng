<?php
/**
 * Brings an existing Weathermap .conf (Cacti plugin, older versions) into
 * this application.
 *
 *   analyze()  reads the file with the engine and lists what needs care:
 *              warnings, INCLUDEs, output paths, missing images, targets to
 *              convert and the interfaces that match them, bitmap fonts.
 *   convert()  writes the adapted .conf: images relinked, targets replaced
 *              by the chosen interfaces, bitmap fonts swapped for TrueType.
 *
 * The positions, VIAs and every other setting are kept as they are.
 */

declare(strict_types=1);

namespace App\Services\Migration;

class MapMigrator
{
    /** GD built-in font n becomes FONTDEFINE 100+n with this TrueType font and size */
    public const FONT_MAP = [
        1 => ['DejaVuSansMono.ttf', 6],
        2 => ['DejaVuSansMono.ttf', 7],
        3 => ['DejaVuSansMono-Bold.ttf', 7],
        4 => ['DejaVuSansMono.ttf', 9],
        5 => ['DejaVuSansMono-Bold.ttf', 9],
    ];

    /** Settings that make the renderer write files where the old server wanted them */
    private const OUTPUT_FIELDS = ['htmloutputfile', 'imageoutputfile', 'dataoutputfile', 'imageuri'];

    private string $publicPath;
    private string $fontDir;
    private ?TargetMatcher $matcher;

    /**
     * @param string $publicPath the web root: icons and fonts are relative to it
     */
    public function __construct(string $publicPath, ?TargetMatcher $matcher = null, string $fontDir = 'fonts')
    {
        $this->publicPath = rtrim($publicPath, '/');
        $this->matcher = $matcher;
        $this->fontDir = trim($fontDir, '/');
    }

    // ------------------------------------------------------------------

    public function analyze(string $conf): array
    {
        [$clean, $removed] = $this->sanitize($conf);
        [$map, $warnings] = $this->read($clean);

        $report = [
            'title' => (string) $map->title,
            'size' => [(int) $map->width, (int) $map->height],
            'counts' => ['nodes' => 0, 'links' => 0],
            'warnings' => $warnings,
            'removed' => $removed,
            'outputs' => [],
            'images' => [],
            'targets' => [],
            'fonts' => [],
        ];

        foreach (self::OUTPUT_FIELDS as $field) {
            if ((string) $map->$field !== '') {
                $report['outputs'][] = strtoupper($field) . ' ' . $map->$field;
            }
        }

        if ((string) $map->background !== '') {
            $report['images'][] = $this->image('background', 'Map background', (string) $map->background);
        }

        foreach ($map->nodes as $name => $node) {
            if ($this->isTemplate($name, $node)) {
                continue;
            }
            $report['counts']['nodes']++;
            $icon = (string) $node->iconfile;
            if ($icon !== '' && !in_array($icon, ['box', 'rbox', 'round', 'inpie', 'outpie', 'nink', 'gauge', 'none'], true) && !str_contains($icon, '{')) {
                $report['images'][] = $this->image('icon', 'NODE ' . $name, $icon);
            }
            $this->collectTargets($report, 'NODE', $name, $node->targets);
            $this->collectFont($report, (int) $node->labelfont);
        }

        foreach ($map->links as $name => $link) {
            if ($name === 'DEFAULT' || $name === ':: DEFAULT ::' || !isset($link->a)) {
                continue;
            }
            $report['counts']['links']++;
            $this->collectTargets($report, 'LINK', $name, $link->targets);
            $this->collectFont($report, (int) $link->bwfont);
            if (array_filter((array) $link->comments, fn ($c) => (string) $c !== '')) {
                $this->collectFont($report, (int) $link->commentfont);
            }
        }

        foreach (['keyfont', 'timefont', 'titlefont'] as $f) {
            $this->collectFont($report, (int) $map->$f);
        }
        $report['fonts'] = array_values(array_unique($report['fonts']));
        sort($report['fonts']);

        return $report;
    }

    /**
     * @param array $choices target key ("LINK:name:0") => interface to use, as
     *                       an array with "target" and optional "bandwidth" and
     *                       "datasource", or "keep" for the old target
     * @param array $options fonts (bool): swap bitmap fonts for TrueType
     */
    public function convert(string $conf, array $report, array $choices = [], array $options = []): string
    {
        [$clean] = $this->sanitize($conf);
        [$map] = $this->read($clean);

        foreach (self::OUTPUT_FIELDS as $field) {
            $map->$field = '';
        }

        // Images found under another path
        foreach ($report['images'] as $img) {
            if ($img['status'] !== 'relinked') {
                continue;
            }
            if ($img['kind'] === 'background') {
                $map->background = $img['new'];
            } else {
                $name = substr($img['item'], 5);
                if (isset($map->nodes[$name]) && $map->nodes[$name]->iconfile === $img['path']) {
                    $map->nodes[$name]->iconfile = $img['new'];
                }
            }
        }

        // Targets
        foreach ($report['targets'] as $t) {
            $choice = $choices[$t['key']] ?? ($t['match']['status'] === 'matched' ? $t['match']['choice'] : 'keep');
            if (!is_array($choice) || empty($choice['target'])) {
                continue;
            }
            $item = $t['kind'] === 'NODE' ? ($map->nodes[$t['name']] ?? null) : ($map->links[$t['name']] ?? null);
            if (!$item || !isset($item->targets[$t['index']])) {
                continue;
            }

            $new = $this->keepModifiers($t['raw'], (string) $choice['target']);
            $item->targets[$t['index']] = [$new, '', '', 0, $new];

            if (!empty($choice['datasource']) && $t['kind'] === 'LINK') {
                $item->hints['datasource'] = json_encode($choice['datasource'], JSON_UNESCAPED_SLASHES);
            }
        }

        if (!empty($options['fonts'])) {
            $this->convertFonts($map);
        }

        return $this->write($map);
    }

    /**
     * A PNG of the map as the engine draws it, without live data.
     */
    public function renderPng(string $conf): string
    {
        [$clean] = $this->sanitize($conf);
        return $this->quietly(fn () => $this->inPublicDir(function () use ($clean) {
            [$map] = $this->read($clean);
            $out = tempnam(sys_get_temp_dir(), 'wm_mig_') . '.png';
            ob_start();
            $map->DrawMap($out);
            ob_end_clean();
            $png = is_file($out) ? (string) file_get_contents($out) : '';
            @unlink($out);
            return $png;
        }));
    }

    /**
     * The map as SVG, without live data.
     */
    public function renderSvg(string $conf, ?callable $fontUrl = null): string
    {
        [$clean] = $this->sanitize($conf);
        return $this->quietly(fn () => $this->inPublicDir(function () use ($clean, $fontUrl) {
            [$map] = $this->read($clean);
            return \WeatherMapSvg::render($map, $fontUrl ? ['font_url' => $fontUrl] : []);
        }));
    }

    // ------------------------------------------------------------------

    /**
     * INCLUDE reads other files on this server: an imported map never keeps it.
     *
     * @return array{0: string, 1: list<string>}
     */
    public function sanitize(string $conf): array
    {
        $removed = [];
        $lines = preg_split('/\r\n|\r|\n/', $conf);
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*INCLUDE\s+/i', $line)) {
                $removed[] = trim($line);
                $lines[$i] = '# removed by the migration: ' . trim($line);
            }
        }
        return [implode("\n", $lines), $removed];
    }

    private function read(string $conf): array
    {
        $file = tempnam(sys_get_temp_dir(), 'wm_mig_');
        file_put_contents($file, $conf);

        $previous = $GLOBALS['weathermap_warnings'] ?? null;
        $GLOBALS['weathermap_warnings'] = [];

        try {
            $map = $this->inPublicDir(function () use ($file) {
                $map = new \WeatherMap();
                $map->context = 'editor';
                ob_start();
                $map->ReadConfig($file);
                ob_end_clean();
                return $map;
            });
            $warnings = array_values(array_unique($GLOBALS['weathermap_warnings']));
        } finally {
            $GLOBALS['weathermap_warnings'] = $previous;
            @unlink($file);
        }

        return [$map, $warnings];
    }

    private function write(\WeatherMap $map): string
    {
        $file = tempnam(sys_get_temp_dir(), 'wm_mig_');
        @unlink($file);
        ob_start();
        $map->WriteConfig($file);
        ob_end_clean();
        $out = (string) file_get_contents($file);
        @unlink($file);
        return $out;
    }

    /** Engine warnings while drawing were already reported by analyze() */
    private function quietly(callable $fn): mixed
    {
        $previous = $GLOBALS['weathermap_warnings'] ?? null;
        $GLOBALS['weathermap_warnings'] = [];
        try {
            return $fn();
        } finally {
            $GLOBALS['weathermap_warnings'] = $previous;
        }
    }

    private function inPublicDir(callable $fn): mixed
    {
        $cwd = getcwd();
        if (is_dir($this->publicPath)) {
            chdir($this->publicPath);
        }
        try {
            return $fn();
        } finally {
            if ($cwd !== false) {
                chdir($cwd);
            }
        }
    }

    private function isTemplate(string $name, $node): bool
    {
        return $name === 'DEFAULT' || $name === ':: DEFAULT ::' || $node->x === null;
    }

    private function image(string $kind, string $item, string $path): array
    {
        $out = ['kind' => $kind, 'item' => $item, 'path' => $path, 'status' => 'ok', 'new' => ''];

        if ($this->readable($path)) {
            return $out;
        }

        // the same file name in this application's image folders
        $folder = $kind === 'background' ? 'backgrounds' : 'objects';
        foreach ([$folder, $folder === 'objects' ? 'backgrounds' : 'objects'] as $dir) {
            $candidate = $dir . '/' . basename($path);
            if ($this->readable($candidate)) {
                return array_merge($out, ['status' => 'relinked', 'new' => $candidate]);
            }
        }

        return array_merge($out, ['status' => 'missing']);
    }

    private function readable(string $path): bool
    {
        if ($path === '' || str_contains($path, '..')) {
            return false;
        }
        return $path[0] === '/' ? false : is_readable($this->publicPath . '/' . $path);
    }

    private function collectTargets(array &$report, string $kind, string $name, array $targets): void
    {
        foreach ($targets as $i => $t) {
            $raw = (string) ($t[4] ?? $t[0] ?? '');
            if ($raw === '') {
                continue;
            }
            $parsed = TargetParser::parse($raw);
            $match = ['status' => $parsed['convert'] ? 'unmatched' : 'keep', 'choice' => null, 'candidates' => [], 'note' => ''];

            if ($parsed['kind'] === 'external') {
                $match['note'] = 'Runs a command on the old server: replace it by hand.';
            } elseif ($parsed['convert'] && $this->matcher) {
                $match = $this->matcher->match($parsed);
            } elseif ($parsed['convert']) {
                $match['note'] = 'Choose a data source to look for the interface.';
            }

            $report['targets'][] = [
                'key' => $kind . ':' . $name . ':' . $i,
                'kind' => $kind,
                'name' => $name,
                'index' => $i,
                'raw' => $raw,
                'parsed' => $parsed,
                'match' => $match,
            ];
        }
    }

    private function collectFont(array &$report, int $n): void
    {
        if ($n >= 1 && $n <= 5) {
            $report['fonts'][] = $n;
        }
    }

    /** "-old" and "8*old" keep their sign and factor on the new target */
    private function keepModifiers(string $raw, string $target): string
    {
        return preg_match('/^(-?(?:\d+(?:\.\d+)?\*)?)/', $raw, $m) ? $m[1] . $target : $target;
    }

    private function convertFonts(\WeatherMap $map): void
    {
        $used = [];
        $swap = function ($n) use (&$used) {
            $n = (int) $n;
            if ($n >= 1 && $n <= 5) {
                $used[$n] = true;
                return 100 + $n;
            }
            return $n;
        };

        // the built-in ":: DEFAULT ::" items keep the engine defaults, so that
        // DEFAULT, which differs from them now, writes its new fonts
        foreach ($map->nodes as $name => $node) {
            if ($name === ':: DEFAULT ::') {
                continue;
            }
            $node->labelfont = $swap($node->labelfont);
        }
        foreach ($map->links as $name => $link) {
            if ($name === ':: DEFAULT ::') {
                continue;
            }
            $link->bwfont = $swap($link->bwfont);
            $link->commentfont = $swap($link->commentfont);
        }
        foreach (['keyfont', 'timefont', 'titlefont'] as $f) {
            $map->$f = $swap($map->$f);
        }

        foreach (array_keys($used) as $n) {
            [$file, $size] = self::FONT_MAP[$n];
            $font = new \WMFont();
            $font->type = 'truetype';
            $font->file = $this->fontDir . '/' . $file;
            $font->size = $size;
            $map->fonts[100 + $n] = $font;
        }
    }
}
