<?php

declare(strict_types=1);

namespace Tests\App;

use App\Core\Application;
use App\Core\Config;
use App\Core\Database;
use App\Services\DisplayLinks;
use PHPUnit\Framework\TestCase;

final class DisplayLinksTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Database $db;
    private DisplayLinks $links;

    protected function setUp(): void
    {
        $data = TESTS_OUTPUT . '/display-links';
        @unlink($data . '/test.db');
        @mkdir($data, 0775, true);

        $config = new Config();
        $config->set('APP_ROOT', dirname(TESTS_ROOT) . '/src');
        $config->set('DATA_ROOT', $data);
        $config->set('DB_TYPE', 'sqlite');
        $config->set('DB_PATH', $data . '/test.db');
        $this->db = new Database($config);
        $this->links = new DisplayLinks($this->db);
    }

    public function testATokenOpensItsMapOnly(): void
    {
        $made = $this->links->create(3, '  NOC   wall ', null, 1, self::NOW);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $made['token']);
        $this->assertSame('NOC wall', $made['link']['label']);
        $this->assertSame('active', $made['link']['state']);
        $this->assertSame('/display/' . $made['token'], DisplayLinks::path($made['token']));

        $this->assertSame([DisplayLinks::OK, 3], $this->links->resolve($made['token'], self::NOW));
        $this->assertSame([DisplayLinks::UNKNOWN, 0], $this->links->resolve(DisplayLinks::newToken(), self::NOW));
        $this->assertSame([DisplayLinks::UNKNOWN, 0], $this->links->resolve('../../etc', self::NOW));
    }

    public function testOnlyTheHashOfTheTokenIsKept(): void
    {
        $made = $this->links->create(3, '', null, null, self::NOW);
        $row = (array) $this->db->queryOne('SELECT * FROM display_links WHERE id = ?', [$made['link']['id']]);
        $this->assertSame(hash('sha256', $made['token']), $row['token_hash']);
        $this->assertNotContains($made['token'], array_map('strval', $row));
        $this->assertArrayNotHasKey('token_hash', $this->links->forMap(3, self::NOW)[0]);
    }

    public function testRevokedAndExpiredLinksOpenNothing(): void
    {
        $revoked = $this->links->create(3, 'a', null, null, self::NOW);
        $expiring = $this->links->create(3, 'b', self::NOW + 3600, null, self::NOW);

        $this->assertFalse($this->links->revoke(4, $revoked['link']['id']), 'a link of another map');
        $this->assertTrue($this->links->revoke(3, $revoked['link']['id']));
        $this->assertSame([DisplayLinks::UNKNOWN, 0], $this->links->resolve($revoked['token'], self::NOW));

        $this->assertSame([DisplayLinks::OK, 3], $this->links->resolve($expiring['token'], self::NOW + 3599));
        $this->assertSame([DisplayLinks::UNKNOWN, 0], $this->links->resolve($expiring['token'], self::NOW + 3600));

        $states = array_column($this->links->forMap(3, self::NOW + 3600), 'state', 'label');
        $this->assertSame(['a' => 'revoked', 'b' => 'expired'], $states);
    }

    public function testAnExpiryInThePastIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->links->create(3, '', self::NOW, null, self::NOW);
    }

    public function testRequestsAreLimitedPerWindow(): void
    {
        $made = $this->links->create(3, '', null, null, self::NOW);
        for ($i = 0; $i < DisplayLinks::RATE_LIMIT; $i++) {
            $this->assertSame(DisplayLinks::OK, $this->links->resolve($made['token'], self::NOW + 1)[0]);
        }
        $this->assertSame([DisplayLinks::LIMITED, 0], $this->links->resolve($made['token'], self::NOW + 2));
        // a new window opens again
        $this->assertSame([DisplayLinks::OK, 3], $this->links->resolve($made['token'], self::NOW + 1 + DisplayLinks::RATE_WINDOW));
    }

    public function testLastUseIsRemembered(): void
    {
        $made = $this->links->create(3, '', null, null, self::NOW);
        $this->assertNull($this->links->forMap(3, self::NOW)[0]['last_used_at']);
        $this->links->resolve($made['token'], self::NOW + 10);
        $this->links->resolve($made['token'], self::NOW + 20);
        $this->assertSame(self::NOW + 10, $this->links->forMap(3, self::NOW)[0]['last_used_at'], 'written at most once a minute');
        $this->links->resolve($made['token'], self::NOW + 80);
        $this->assertSame(self::NOW + 80, $this->links->forMap(3, self::NOW)[0]['last_used_at']);
    }

    public function testADeletedMapForgetsItsLinks(): void
    {
        $made = $this->links->create(3, '', null, null, self::NOW);
        $this->links->create(4, '', null, null, self::NOW);
        $this->links->forgetMap(3);
        $this->assertSame([], $this->links->forMap(3, self::NOW));
        $this->assertCount(1, $this->links->forMap(4, self::NOW));
        $this->assertSame([DisplayLinks::UNKNOWN, 0], $this->links->resolve($made['token'], self::NOW));
    }

    public function testFrameAncestors(): void
    {
        $this->assertSame("'self'", Application::frameAncestors(null));
        $this->assertSame("'self'", Application::frameAncestors(''));
        $this->assertSame("'self' https://centreon.example.org http://grafana.lan:3000",
            Application::frameAncestors('https://centreon.example.org, http://grafana.lan:3000'));
        $this->assertSame("'self'", Application::frameAncestors("javascript:alert(1) 'unsafe-inline' https://a.example/path"));
        $this->assertSame('*', Application::frameAncestors('*'));
    }
}
