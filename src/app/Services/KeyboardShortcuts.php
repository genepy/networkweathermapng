<?php
/**
 * NetworkWeathermapNG - Keyboard shortcuts of each user
 *
 * The catalogue of the shortcuts the editor, the map viewer and the command
 * palette understand, with their default keys, and each user's changes
 * (table user_shortcuts: only the actions a user changed have a row).
 * The browser scripts read the result through window.WM_KEYS
 * (assets/js/shortcuts.js).
 *
 * A key combination is written "Ctrl+Alt+Shift+Key", modifiers in that
 * order. Ctrl also stands for Cmd on a Mac. Letters are upper case. Shift is
 * never written before a symbol or a digit ("?", "+", "0"): the character
 * already says it. Named keys use the browser's names (ArrowLeft, Delete,
 * F2...) and "Space".
 *
 * Drag actions ("editor-drag" area) are bound to one modifier held while
 * dragging: Ctrl, Alt or Shift, or nothing.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

class KeyboardShortcuts
{
    /** Areas, in the order of the shortcuts page */
    public const AREAS = ['global', 'viewer', 'editor', 'editor-drag'];

    /** id => [area, default keys]; labels in labels() */
    public const ACTIONS = [
        'palette' => ['global', ['Ctrl+K']],

        'viewer.find' => ['viewer', ['/']],
        'viewer.zoom-in' => ['viewer', ['+', '=']],
        'viewer.zoom-out' => ['viewer', ['-', '_']],
        'viewer.fit' => ['viewer', ['0']],
        'viewer.actual-size' => ['viewer', ['1']],
        'viewer.pan-left' => ['viewer', ['ArrowLeft']],
        'viewer.pan-right' => ['viewer', ['ArrowRight']],
        'viewer.pan-up' => ['viewer', ['ArrowUp']],
        'viewer.pan-down' => ['viewer', ['ArrowDown']],
        'viewer.panel' => ['viewer', ['P']],
        'viewer.fullscreen' => ['viewer', ['F']],
        'viewer.edit' => ['viewer', ['E']],
        'viewer.history' => ['viewer', ['H']],
        'viewer.play' => ['viewer', ['Space']],

        'editor.undo' => ['editor', ['Ctrl+Z']],
        'editor.redo' => ['editor', ['Ctrl+Shift+Z', 'Ctrl+Y']],
        'editor.copy' => ['editor', ['Ctrl+C']],
        'editor.cut' => ['editor', ['Ctrl+X']],
        'editor.paste' => ['editor', ['Ctrl+V']],
        'editor.duplicate' => ['editor', ['Ctrl+D']],
        'editor.delete' => ['editor', ['Delete', 'Backspace']],
        'editor.select-all' => ['editor', ['Ctrl+A']],
        'editor.find' => ['editor', ['Ctrl+F']],
        'editor.group' => ['editor', ['Ctrl+G']],
        'editor.ungroup' => ['editor', ['Ctrl+Shift+G']],
        'editor.lock' => ['editor', ['Ctrl+L']],
        'editor.front' => ['editor', ['Ctrl+]']],
        'editor.back' => ['editor', ['Ctrl+[']],
        'editor.menu' => ['editor', ['ContextMenu', 'Shift+F10']],
        'editor.link-tool' => ['editor', ['L']],
        'editor.select-tool' => ['editor', ['V']],
        'editor.nudge-left' => ['editor', ['ArrowLeft']],
        'editor.nudge-right' => ['editor', ['ArrowRight']],
        'editor.nudge-up' => ['editor', ['ArrowUp']],
        'editor.nudge-down' => ['editor', ['ArrowDown']],
        'editor.snap' => ['editor', ['G']],
        'editor.fit' => ['editor', ['F']],
        'editor.actual-size' => ['editor', ['0']],
        'editor.zoom-in' => ['editor', ['+', '=']],
        'editor.zoom-out' => ['editor', ['-']],
        'editor.shortcuts' => ['editor', ['?']],

        'editor.drag-pan' => ['editor-drag', ['Ctrl']],
        'editor.drag-duplicate' => ['editor-drag', ['Alt']],
        'editor.drag-nosnap' => ['editor-drag', ['Shift']],
    ];

    /** Modifiers a drag action may use */
    public const DRAG_KEYS = [
        'editor.drag-pan' => ['Ctrl', 'Alt'],
        'editor.drag-duplicate' => ['Ctrl', 'Alt', 'Shift'],
        'editor.drag-nosnap' => ['Ctrl', 'Alt', 'Shift'],
    ];

    /** Keys kept by every page: leaving a menu, moving the focus, applying a field */
    public const RESERVED = ['Escape', 'Tab', 'Shift+Tab', 'Enter'];

    /** Keys an area keeps for itself */
    public const AREA_RESERVED = [
        // hold Space and drag to move the view
        'editor' => ['Space'],
    ];

    /** Most bindings an action may have */
    public const MAX_KEYS = 3;

    private const NAMED_KEYS = [
        'Space', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Delete', 'Backspace', 'Insert',
        'Home', 'End', 'PageUp', 'PageDown', 'ContextMenu', 'Escape', 'Tab', 'Enter',
    ];

    public function __construct(private Database $database)
    {
    }

    /** True when the key combination is well formed */
    public static function validCombo(string $combo): bool
    {
        if (!preg_match('/^(Ctrl\+)?(Alt\+)?(Shift\+)?(.+)$/', $combo, $m)) {
            return false;
        }
        $key = $m[4];
        if (preg_match('/^\p{Lu}$/u', $key) || preg_match('/^F([1-9]|1[0-2])$/', $key) || in_array($key, self::NAMED_KEYS, true)) {
            return true;
        }
        // one printable character other than a letter: Shift is in the character
        return $m[3] === '' && mb_strlen($key) === 1 && !preg_match('/[\s\p{L}]/u', $key);
    }

    /** Translated names of the areas */
    public static function areaLabels(): array
    {
        return [
            'global' => __('Everywhere'),
            'viewer' => __('Map viewer'),
            'editor' => __('Map editor'),
            'editor-drag' => __('Map editor: drag with a key held'),
        ];
    }

    /** Translated names of the actions: id => label */
    public static function labels(): array
    {
        return [
            'palette' => __('Open the command palette'),

            'viewer.find' => __('Find a node'),
            'viewer.zoom-in' => __('Zoom in'),
            'viewer.zoom-out' => __('Zoom out'),
            'viewer.fit' => __('Fit the map to the window'),
            'viewer.actual-size' => __('Actual size'),
            'viewer.pan-left' => __('Move the view left'),
            'viewer.pan-right' => __('Move the view right'),
            'viewer.pan-up' => __('Move the view up'),
            'viewer.pan-down' => __('Move the view down'),
            'viewer.panel' => __('Show or hide the side panel'),
            'viewer.fullscreen' => __('Full screen'),
            'viewer.edit' => __('Edit the map'),
            'viewer.history' => __('Play back the recorded hours'),
            'viewer.play' => __('Play or pause the playback'),

            'editor.undo' => __('Undo'),
            'editor.redo' => __('Redo'),
            'editor.copy' => __('Copy'),
            'editor.cut' => __('Cut'),
            'editor.paste' => __('Paste'),
            'editor.duplicate' => __('Duplicate'),
            'editor.delete' => __('Delete'),
            'editor.select-all' => __('Select everything'),
            'editor.find' => __('Select by criteria'),
            'editor.group' => __('Group'),
            'editor.ungroup' => __('Ungroup'),
            'editor.lock' => __('Lock or unlock position'),
            'editor.front' => __('Bring to front'),
            'editor.back' => __('Send to back'),
            'editor.menu' => __('Menu of actions'),
            'editor.link-tool' => __('Link tool'),
            'editor.select-tool' => __('Select tool'),
            'editor.nudge-left' => __('Move left by 1 px (with Shift: 10 px)'),
            'editor.nudge-right' => __('Move right by 1 px (with Shift: 10 px)'),
            'editor.nudge-up' => __('Move up by 1 px (with Shift: 10 px)'),
            'editor.nudge-down' => __('Move down by 1 px (with Shift: 10 px)'),
            'editor.snap' => __('Snap to the grid'),
            'editor.fit' => __('Fit the map to the window'),
            'editor.actual-size' => __('Actual size'),
            'editor.zoom-in' => __('Zoom in'),
            'editor.zoom-out' => __('Zoom out'),
            'editor.shortcuts' => __('Show the keyboard shortcuts'),

            'editor.drag-pan' => __('Drag on empty space: move the view'),
            'editor.drag-duplicate' => __('Drag a node: duplicate the selected nodes'),
            'editor.drag-nosnap' => __('While dragging: no snapping to the other objects'),
        ];
    }

    /** Keys and gestures that cannot be changed, by area: [keys, what they do] */
    public static function fixed(): array
    {
        return [
            'global' => [
                ['Esc', __('Close a menu, a dialog or a panel')],
            ],
            'viewer' => [
                [__('Wheel, drag'), __('Zoom, move the view')],
                [__('Click a node'), __('Show its card and zoom on it')],
            ],
            'editor' => [
                [__('Click, Shift+click'), __('Select, add to the selection')],
                [__('Drag on empty space'), __('Select an area (with Shift or Ctrl: add it to the selection)')],
                [__('Ctrl+click empty space'), __('New node')],
                [__('Alt+click'), __('One item of a group')],
                [__('Double-click a link'), __('Add a bend point there')],
                [__('Right-click'), __('Menu of actions')],
                [__('Space+drag, middle button drag'), __('Move the view')],
                [__('Wheel'), __('Zoom')],
                [__('Enter in a field'), __('Apply the change')],
            ],
        ];
    }

    /** Translated names of the modifiers of the drag actions */
    public static function dragKeyLabels(): array
    {
        return ['' => __('None'), 'Ctrl' => 'Ctrl', 'Alt' => 'Alt', 'Shift' => 'Shift'];
    }

    /** The defaults: id => keys */
    public static function defaults(): array
    {
        return array_map(static fn (array $a): array => $a[1], self::ACTIONS);
    }

    /** A user's changes: id => keys (an empty list turns the action off) */
    public function overrides(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $out = [];
        foreach ($this->database->query('SELECT action, binding FROM user_shortcuts WHERE user_id = ?', [$userId]) as $row) {
            $id = (string) $row['action'];
            $keys = json_decode((string) $row['binding'], true);
            if (isset(self::ACTIONS[$id]) && is_array($keys)) {
                $out[$id] = array_values(array_filter($keys, 'is_string'));
            }
        }
        return $out;
    }

    /** The keys of every action for a user: id => keys */
    public function forUser(int $userId): array
    {
        return array_replace(self::defaults(), $this->overrides($userId));
    }

    /**
     * Problems with a set of bindings (id => keys, missing ids keep their
     * defaults), as translated messages: id => message. Empty when they can
     * be saved.
     */
    public static function problems(array $bindings): array
    {
        $labels = self::labels();
        $problems = [];
        $owners = [];
        foreach (self::ACTIONS as $id => [$area, $defaults]) {
            $keys = $bindings[$id] ?? $defaults;
            if (!is_array($keys) || count($keys) > self::MAX_KEYS) {
                $problems[$id] = __('At most :n keys for one action', ['n' => self::MAX_KEYS]);
                continue;
            }
            foreach ($keys as $combo) {
                $usable = is_string($combo) && ($area === 'editor-drag'
                    ? in_array($combo, self::DRAG_KEYS[$id], true) && count($keys) === 1
                    : self::validCombo($combo) && !in_array($combo, self::RESERVED, true) && !in_array($combo, self::AREA_RESERVED[$area] ?? [], true));
                if (!$usable) {
                    $problems[$id] = __('This key cannot be used');
                    continue 2;
                }
                // the same key twice in an area; "Everywhere" counts in every area
                $scopes = $area === 'global' ? self::AREAS : [$area, 'global'];
                foreach ($scopes as $scope) {
                    $other = $owners[$scope][$combo] ?? null;
                    if ($other !== null && $other !== $id) {
                        $problems[$id] = __('Same key as: :what', ['what' => $labels[$other]]);
                        continue 3;
                    }
                }
                $owners[$area][$combo] = $id;
            }
        }
        return $problems;
    }

    /**
     * Save a user's bindings (id => keys, unknown ids ignored). Only the
     * actions that differ from the defaults are stored. Returns the
     * problems instead when there are some.
     */
    public function save(int $userId, array $bindings): array
    {
        $clean = [];
        foreach (self::ACTIONS as $id => $action) {
            if (array_key_exists($id, $bindings) && is_array($bindings[$id])) {
                $clean[$id] = array_values(array_unique(array_map('strval', $bindings[$id])));
            }
        }
        $problems = self::problems($clean);
        if ($problems) {
            return $problems;
        }
        $this->database->delete('user_shortcuts', 'user_id = ?', [$userId]);
        foreach ($clean as $id => $keys) {
            if ($keys !== self::ACTIONS[$id][1]) {
                $this->database->insert('user_shortcuts', [
                    'user_id' => $userId,
                    'action' => $id,
                    'binding' => json_encode($keys, JSON_UNESCAPED_UNICODE),
                ]);
            }
        }
        return [];
    }

    /** Forget every change of a user */
    public function reset(int $userId): void
    {
        $this->database->delete('user_shortcuts', 'user_id = ?', [$userId]);
    }

    /**
     * The inline script giving the browser a user's keys (window.WM_KEYS)
     * and the words of assets/js/shortcuts.js; the page loads that script
     * after it.
     */
    public function scriptFor(int $userId): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
        return '<script>window.WM_KEYS = ' . json_encode($this->forUser($userId), $flags) . ';'
            . 'window.WM_T = Object.assign(window.WM_T || {}, ' . json_encode((object) \App\Core\I18n::section('keys-js'), $flags) . ');</script>';
    }
}
