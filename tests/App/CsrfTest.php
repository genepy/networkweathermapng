<?php

declare(strict_types=1);

namespace Tests\App;

use App\Core\Csrf;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_POST = [];
        unset($_SERVER[Csrf::HEADER], $_SERVER['HTTP_ACCEPT']);
    }

    public function testTokenIsStableForTheSession(): void
    {
        $token = Csrf::token();

        $this->assertSame(64, strlen($token));
        $this->assertSame($token, Csrf::token());
    }

    public function testOnlyTheSessionTokenIsAccepted(): void
    {
        $token = Csrf::token();

        $this->assertTrue(Csrf::isValid($token));
        $this->assertFalse(Csrf::isValid(null));
        $this->assertFalse(Csrf::isValid(''));
        $this->assertFalse(Csrf::isValid(str_repeat('0', 64)));
    }

    public function testNothingIsValidBeforeATokenExists(): void
    {
        $this->assertFalse(Csrf::isValid(''));
        $this->assertFalse(Csrf::isValid(null));
    }

    public function testTokenIsReadFromFormFieldOrHeader(): void
    {
        $_POST['_token'] = 'from-form';
        $this->assertSame('from-form', Csrf::fromRequest());

        $_POST = [];
        $_SERVER[Csrf::HEADER] = 'from-header';
        $this->assertSame('from-header', Csrf::fromRequest());
    }

    public function testRouterRejectsPostWithoutToken(): void
    {
        Csrf::token();
        [$called, $output] = $this->dispatch('POST');

        $this->assertFalse($called);
        $this->assertStringContainsString('Session expired', $output);
    }

    public function testRouterAcceptsPostWithToken(): void
    {
        $_POST['_token'] = Csrf::token();
        [$called] = $this->dispatch('POST');

        $this->assertTrue($called);
    }

    public function testRouterAnswersJsonToScripts(): void
    {
        Csrf::token();
        $_SERVER[Csrf::HEADER] = 'wrong';
        [$called, $output] = $this->dispatch('POST');

        $this->assertFalse($called);
        $this->assertSame(false, json_decode($output, true)['success'] ?? null);
    }

    public function testGetNeedsNoToken(): void
    {
        [$called] = $this->dispatch('GET');

        $this->assertTrue($called);
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function dispatch(string $method): array
    {
        $called = false;
        $router = new Router();
        $handler = function () use (&$called): void {
            $called = true;
        };
        $router->get('/thing', $handler);
        $router->post('/thing', $handler);

        ob_start();
        $router->dispatch($method, '/thing');
        $output = (string) ob_get_clean();

        return [$called, $output];
    }
}
