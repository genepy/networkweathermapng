<?php
/**
 * The text of a map's .conf, for the editor's text mode.
 *
 * validate() reads a text with the engine and reports its warnings with the
 * line they are about. check() refuses what the text mode must not change:
 * INCLUDE (it reads other files on this server) and the output paths
 * (*OUTPUTFILE, IMAGEURI), which belong to the application.
 */

declare(strict_types=1);

namespace App\Services\Editor;

use InvalidArgumentException;

class ConfigSource
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    private const PROTECTED_KEYWORDS = '/^\s*(HTMLOUTPUTFILE|IMAGEOUTPUTFILE|DATAOUTPUTFILE|IMAGEURI)\b/i';

    private string $workDir;

    /** @param string $workDir directory relative paths (icons, fonts) are read from */
    public function __construct(string $workDir = '')
    {
        $this->workDir = $workDir;
    }

    /**
     * Problems that stop the text from being saved: [{line, message}].
     */
    public function errors(string $text, ?string $current): array
    {
        $errors = [];

        if (strlen($text) > self::MAX_BYTES) {
            return [['line' => null, 'message' => __('The text is too large')]];
        }

        $protectedNow = [];
        foreach (self::lines($current ?? '') as $line) {
            if (preg_match(self::PROTECTED_KEYWORDS, $line)) {
                $protectedNow[] = preg_replace('/\s+/', ' ', trim($line));
            }
        }

        $protectedNew = [];
        foreach (self::lines($text) as $i => $line) {
            if (preg_match('/^\s*INCLUDE\b/i', $line)) {
                $errors[] = ['line' => $i + 1, 'message' => __('INCLUDE is not allowed: it reads other files on the server')];
            } elseif (preg_match(self::PROTECTED_KEYWORDS, $line)) {
                $protectedNew[] = ['line' => $i + 1, 'text' => preg_replace('/\s+/', ' ', trim($line))];
            }
        }

        $left = $protectedNow;
        foreach ($protectedNew as $p) {
            $k = array_search($p['text'], $left, true);
            if ($k === false) {
                $errors[] = ['line' => $p['line'], 'message' => __('Output paths (*OUTPUTFILE, IMAGEURI) cannot be changed here')];
            } else {
                unset($left[$k]);
            }
        }
        if ($left) {
            $errors[] = ['line' => null, 'message' => __('Output paths (*OUTPUTFILE, IMAGEURI) cannot be changed here')];
        }

        return $errors;
    }

    /**
     * Read the text with the engine: {errors, warnings, nodes, links}.
     * Warnings are [{line, message}]; line is null when the engine does not say.
     */
    public function validate(string $text, ?string $current): array
    {
        $errors = $this->errors($text, $current);
        if (strlen($text) > self::MAX_BYTES) {
            return ['errors' => $errors, 'warnings' => [], 'nodes' => 0, 'links' => 0];
        }

        // never follow an INCLUDE, even to report on it
        $safe = implode("\n", array_map(
            static fn ($l) => preg_match('/^\s*INCLUDE\b/i', $l) ? '# ' . $l : $l,
            self::lines($text)
        ));

        $file = tempnam(sys_get_temp_dir(), 'wm_src_');
        if ($file === false) {
            throw new \RuntimeException('No temporary file');
        }
        file_put_contents($file, $safe);

        $previous = $GLOBALS['weathermap_warnings'] ?? null;
        $GLOBALS['weathermap_warnings'] = [];
        $cwd = getcwd();
        $nodes = $links = 0;

        try {
            if ($this->workDir !== '' && is_dir($this->workDir)) {
                chdir($this->workDir);
            }
            $map = new \WeatherMap();
            $map->context = 'editor';
            ob_start();
            try {
                $map->ReadConfig($file);
            } finally {
                ob_end_clean();
            }
            foreach ($map->nodes as $name => $n) {
                if ($name !== 'DEFAULT' && !str_starts_with((string) $name, ':: ') && $n->x !== null) {
                    $nodes++;
                }
            }
            foreach ($map->links as $l) {
                if (isset($l->a)) {
                    $links++;
                }
            }
            $raw = array_values(array_unique((array) $GLOBALS['weathermap_warnings']));
        } finally {
            $GLOBALS['weathermap_warnings'] = $previous;
            if ($cwd !== false) {
                chdir($cwd);
            }
            @unlink($file);
        }

        $warnings = [];
        foreach ($raw as $message) {
            $message = (string) $message;
            $line = null;
            if (preg_match('/\bline\s+(\d+)/i', $message, $m)) {
                $line = (int) $m[1];
            }
            // the scratch file's name means nothing to the reader
            $message = str_replace($file, '', $message);
            $warnings[] = ['line' => $line, 'message' => trim($message)];
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'nodes' => $nodes, 'links' => $links];
    }

    /** The text as it will be written: Unix line ends, a final newline. */
    public static function normalise(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return rtrim($text, "\n") . "\n";
    }

    /**
     * Refuse a text with errors (see errors()).
     */
    public function check(string $text, ?string $current): void
    {
        $errors = $this->errors($text, $current);
        if ($errors) {
            $e = $errors[0];
            throw new InvalidArgumentException($e['line'] !== null
                ? __('Line :line: :message', ['line' => $e['line'], 'message' => $e['message']])
                : $e['message']);
        }
    }

    /** @return string[] */
    private static function lines(string $text): array
    {
        return preg_split('/\r\n|\r|\n/', $text) ?: [];
    }
}
