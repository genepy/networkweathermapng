<?php

declare(strict_types=1);

namespace Tests\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Existing .conf files must survive a read/write cycle through the engine:
 * the editor rewrites them on every change.
 */
final class ConfigRoundTripTest extends TestCase
{
    public static function mapProvider(): array
    {
        return RenderTest::mapProvider();
    }

    #[DataProvider('mapProvider')]
    public function testWriteThenReadKeepsTheMap(string $configFile): void
    {
        $original = $this->load($configFile);

        $first = TESTS_OUTPUT . '/' . basename($configFile) . '.1';
        $second = TESTS_OUTPUT . '/' . basename($configFile) . '.2';
        $original->WriteConfig($first);
        $reloaded = $this->load($first);
        $reloaded->WriteConfig($second);

        $this->assertEquals($original->width, $reloaded->width);
        $this->assertEquals($original->height, $reloaded->height);
        $this->assertSame(array_keys($original->nodes), array_keys($reloaded->nodes));
        $this->assertSame(array_keys($original->links), array_keys($reloaded->links));

        foreach ($original->nodes as $name => $node) {
            $this->assertEquals([$node->x, $node->y], [$reloaded->nodes[$name]->x, $reloaded->nodes[$name]->y], "Node {$name} moved");
        }
        foreach ($original->links as $name => $link) {
            $this->assertEquals($link->vialist, $reloaded->links[$name]->vialist, "Link {$name} lost its VIA points");
            $this->assertSame(
                array_column($link->targets, 4),
                array_column($reloaded->links[$name]->targets, 4),
                "Link {$name} lost its TARGET"
            );
        }

        $this->assertFileEquals($first, $second, 'Writing the same map twice must give the same file');
    }

    private function load(string $file): \WeatherMap
    {
        $map = new \WeatherMap();
        $map->context = 'cli';
        $map->ReadConfig($file);
        return $map;
    }
}
