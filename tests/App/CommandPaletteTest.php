<?php

declare(strict_types=1);

namespace Tests\App;

use App\Services\CommandPalette;
use PHPUnit\Framework\TestCase;

/**
 * The Ctrl+K command palette (UI redesign step 7): the nodes read from a
 * map config, the index sent to the browser, and the icons and routes the
 * script relies on.
 */
final class CommandPaletteTest extends TestCase
{
    public function testNodesOfAFixtureMap(): void
    {
        $config = (string) file_get_contents(TESTS_ROOT . '/fixtures/maps/basic.conf');
        $nodes = CommandPalette::nodesOf($config);

        $this->assertSame(['name' => 'core', 'label' => 'core-rtr1'], $nodes[0]);
        $this->assertSame(['core', 'paris', 'lyon', 'backup'], array_column($nodes, 'name'));
    }

    public function testTemplatesDefaultAndTokenLabelsAreHandled(): void
    {
        $config = <<<CONF
            NODE DEFAULT
                LABEL {node:this:name}
            NODE tpl
                LABEL template
            NODE r1
                USE tpl
                LABEL {node:this:bandwidth_in}
                POSITION 10 10
            # a comment
            NODE sw1
                LABEL Switch 1
                POSITION 50 50
            LINK r1-sw1
                NODES r1 sw1
                LABEL not a node
            ZONE z
                LABEL zone label
            CONF;

        $this->assertSame([
            ['name' => 'r1', 'label' => 'r1'],
            ['name' => 'sw1', 'label' => 'Switch 1'],
        ], CommandPalette::nodesOf($config));
    }

    public function testIndex(): void
    {
        $maps = [
            ['id' => 1, 'name' => 'Core', 'group_id' => 2, 'group_name' => 'Backbone', 'active' => 1],
            ['id' => 2, 'name' => 'Draft', 'group_id' => 3, 'group_name' => 'Lab', 'active' => 0],
        ];
        $configs = [1 => "NODE a\n LABEL Alpha\n POSITION 1 1\n", 2 => null];
        $statuses = [1 => ['max' => 91.4, 'level' => 'crit'], 2 => null];
        $can = ['edit' => true, 'admin' => false, 'account' => true];

        $index = CommandPalette::index($maps, $configs, $statuses, [2], $can);

        $this->assertSame([
            ['id' => 1, 'name' => 'Core', 'group' => 'Backbone', 'active' => true, 'favourite' => false, 'pct' => 91.4, 'level' => 'crit'],
            ['id' => 2, 'name' => 'Draft', 'group' => 'Lab', 'active' => false, 'favourite' => true, 'pct' => null, 'level' => ''],
        ], $index['maps']);
        $this->assertSame([['map' => 1, 'name' => 'a', 'label' => 'Alpha']], $index['nodes']);
        // the kiosk only shows active maps: no group made only of hidden ones
        $this->assertSame([['id' => 2, 'name' => 'Backbone']], $index['groups']);
        // Settings pages only for administrators
        $this->assertSame([], $index['settings']);
        $this->assertSame($can, $index['can']);

        $admin = CommandPalette::index($maps, $configs, $statuses, [], ['admin' => true] + $can);
        $this->assertNotEmpty($admin['settings']);
        foreach ($admin['settings'] as $page) {
            $this->assertStringStartsWith('/admin/', $page['href']);
        }
    }

    public function testScriptReliesOnExistingIconsAndRoutes(): void
    {
        $root = dirname(TESTS_ROOT);
        $script = (string) file_get_contents($root . '/src/public/assets/js/palette.js');
        $sprite = (string) file_get_contents($root . '/src/public/assets/icons/ui.svg');
        $routes = (string) file_get_contents($root . '/src/app/Core/Application.php');

        preg_match_all("/icon: '([a-z0-9-]+)'/", $script, $m);
        $this->assertNotEmpty($m[1]);
        foreach (array_unique([...$m[1], 'search']) as $icon) {
            $this->assertStringContainsString('id="' . $icon . '"', $sprite, $icon . ' is not in ui.svg');
        }
        foreach (["get('/palette'", "get('/cycle'", "get('/cycle/{group}'", "get('/editor/edit/{id}'", "get('/account/password'", "\$prefix . '/create'"] as $route) {
            $this->assertStringContainsString($route, $routes);
        }
    }
}
