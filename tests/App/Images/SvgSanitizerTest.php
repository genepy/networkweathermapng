<?php

declare(strict_types=1);

namespace Tests\App\Images;

use App\Services\Images\SvgSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SvgSanitizerTest extends TestCase
{
    private const OPEN = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="48" height="48">';

    private function clean(string $body): string
    {
        return (new SvgSanitizer())->sanitize(self::OPEN . $body . '</svg>');
    }

    public function testDrawingIsKept(): void
    {
        $out = $this->clean('<defs><linearGradient id="g"><stop offset="0" stop-color="#fff"/></linearGradient></defs>'
            . '<rect x="1" y="2" width="10" height="10" fill="url(#g)" style="stroke:#000;fill:url(#g)"/><use xlink:href="#g"/><text x="1" y="9">Hi</text>');

        $this->assertStringContainsString('<rect x="1" y="2" width="10" height="10" fill="url(#g)" style="stroke:#000;fill:url(#g)"/>', $out);
        $this->assertStringContainsString('xlink:href="#g"', $out);
        $this->assertStringContainsString('>Hi</text>', $out);
    }

    /** @return array<string, array{string, string}> trap => [body, text that must be gone] */
    public static function traps(): array
    {
        return [
            'script' => ['<script>alert(1)</script><rect width="1" height="1"/>', 'alert'],
            'script with namespace prefix' => ['<svg:script xmlns:svg="http://www.w3.org/2000/svg">alert(1)</svg:script>', 'alert'],
            'onload' => ['<rect width="1" height="1" onload="alert(1)"/>', 'onload'],
            'onclick upper case' => ['<g ONCLICK="alert(1)"/>', 'alert'],
            'javascript href' => ['<a href="javascript:alert(1)"><rect width="1" height="1"/></a>', 'javascript'],
            'javascript xlink:href on use' => ['<use xlink:href="javascript:alert(1)"/>', 'javascript'],
            'external use' => ['<use href="http://evil.example/x.svg#a"/>', 'evil'],
            'external image' => ['<image href="https://evil.example/x.png" width="1" height="1"/>', 'evil'],
            'svg data image' => ['<image href="data:image/svg+xml;base64,PHN2Zz48c2NyaXB0PmFsZXJ0KDEpPC9zY3JpcHQ+PC9zdmc+"/>', 'svg+xml'],
            'foreignObject' => ['<foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><iframe src="x"/></body></foreignObject>', 'iframe'],
            'iframe embed object' => ['<iframe src="x"/><embed src="y"/><object data="z"/>', 'src='],
            'animate set to href' => ['<a><set attributeName="href" to="javascript:alert(1)"/></a><animate attributeName="href" values="javascript:x"/>', 'javascript'],
            'animate xlink:href' => ['<use xlink:href="#a"><animate attributeName="xlink:href" values="#b;#c" dur="1s"/></use>', 'attributeName'],
            'set an event attribute' => ['<rect width="1" height="1"><set attributeName="onclick" to="alert(1)"/></rect>', 'alert'],
            'animate a style' => ['<rect width="1" height="1"><animate attributeName="style" to="fill:url(#a)" dur="1s"/></rect>', 'attributeName'],
            'animate without a target' => ['<rect width="1" height="1"><animate to="1" dur="1s"/></rect>', '<animate'],
            'mpath outside the file' => ['<animateMotion dur="1s"><mpath href="http://evil.example/p.svg#a"/></animateMotion>', 'evil'],
            'style import' => ['<style>@import url(http://evil.example/a.css); rect{fill:red}</style>', 'evil'],
            'style external url' => ['<style>rect{background:url("http://evil.example/t")}</style>', 'evil'],
            'style attribute url' => ['<rect width="1" height="1" style="fill:url(http://evil.example/p)"/>', 'evil'],
            'fill external url' => ['<rect width="1" height="1" fill="url(http://evil.example/p#a)"/>', 'evil'],
            'style escaped url' => ['<style>rect{fill:u\\72l(http://evil.example/p)}</style>', 'evil.example/p)'],
            'processing instruction' => ['<?xml-stylesheet href="http://evil.example/s.xsl"?><rect width="1" height="1"/>', 'evil'],
        ];
    }

    #[DataProvider('traps')]
    public function testTrapsAreRemoved(string $body, string $gone): void
    {
        $out = $this->clean($body);

        $this->assertStringNotContainsStringIgnoringCase($gone, $out);
        $this->assertStringNotContainsStringIgnoringCase('<script', $out);
        $this->assertStringStartsWith('<svg', $out);
    }

    /** F43: an icon that moves by itself (SMIL or CSS keyframes) keeps its animation */
    public function testAnimationsAreKept(): void
    {
        $out = $this->clean('<defs><path id="p" d="M0 0L10 10"/></defs>'
            . '<g id="blades"><path d="M24 24L24 4"/>'
            . '<animateTransform attributeName="transform" attributeType="XML" type="rotate" from="0 24 24" to="360 24 24" dur="2s" repeatCount="indefinite"/></g>'
            . '<circle r="2"><animateMotion dur="3s" repeatCount="indefinite" rotate="auto" keyPoints="0;1" keyTimes="0;1" calcMode="linear"><mpath href="#p"/></animateMotion></circle>'
            . '<rect width="1" height="1"><animate attributeName="opacity" values="1;0.2;1" dur="1s" begin="0s" repeatCount="indefinite"/>'
            . '<set attributeName="fill" to="#f00" begin="1s"/></rect>'
            . '<style>@keyframes spin{to{transform:rotate(360deg)}} .r{animation:spin 2s linear infinite;transform-origin:center}</style>');

        $this->assertStringContainsString('<animateTransform attributeName="transform" attributeType="XML" type="rotate" from="0 24 24" to="360 24 24" dur="2s" repeatCount="indefinite"/>', $out);
        $this->assertStringContainsString('<animateMotion dur="3s" repeatCount="indefinite" rotate="auto" keyPoints="0;1" keyTimes="0;1" calcMode="linear"><mpath href="#p"/></animateMotion>', $out);
        $this->assertStringContainsString('<animate attributeName="opacity" values="1;0.2;1" dur="1s" begin="0s" repeatCount="indefinite"/>', $out);
        $this->assertStringContainsString('<set attributeName="fill" to="#f00" begin="1s"/>', $out);
        $this->assertStringContainsString('@keyframes spin{to{transform:rotate(360deg)}}', $out);
        $this->assertStringContainsString('animation:spin 2s linear infinite', $out);
    }

    public function testEmbeddedBitmapIsKept(): void
    {
        $out = $this->clean('<image href="data:image/png;base64,iVBORw0KGgo=" width="1" height="1"/>');

        $this->assertStringContainsString('data:image/png;base64,iVBORw0KGgo=', $out);
    }

    /** @return array<string, array{string}> */
    public static function refused(): array
    {
        return [
            'xxe' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>'],
            'billion laughs' => ['<!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;">]><svg xmlns="http://www.w3.org/2000/svg">&lol2;</svg>'],
            'external dtd' => ['<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg xmlns="http://www.w3.org/2000/svg"/>'],
            'html root' => ['<html><body><script>alert(1)</script></body></html>'],
            'other namespace root' => ['<svg xmlns="http://www.w3.org/1999/xhtml"/>'],
            'not xml' => ['GIF89a'],
            'too large' => [self::OPEN . str_repeat(' ', SvgSanitizer::MAX_BYTES) . '</svg>'],
        ];
    }

    #[DataProvider('refused')]
    public function testDangerousFilesAreRefused(string $svg): void
    {
        $this->expectException(\RuntimeException::class);
        (new SvgSanitizer())->sanitize($svg);
    }
}
