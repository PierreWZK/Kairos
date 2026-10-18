<?php

namespace App\Tests\Unit\Upload;

use App\Upload\SvgSanitizer;
use PHPUnit\Framework\TestCase;

final class SvgSanitizerTest extends TestCase
{
    public function testDrawingIsKeptAndScriptsAreRemoved(): void
    {
        $svg = (new SvgSanitizer())->sanitize(<<<'SVG'
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" onload="alert(1)">
              <script>alert(2)</script>
              <foreignObject><div>html</div></foreignObject>
              <a href="javascript:alert(3)"><rect width="1" height="1"/></a>
              <use href="https://exemple.test/x.svg#a"/>
              <circle cx="5" cy="5" r="4" fill="url(#g)" onclick="alert(4)"/>
              <rect width="2" height="2" fill="url(https://exemple.test/pixel)"/>
              <defs><linearGradient id="g"><stop offset="0" stop-color="#b4513a"/></linearGradient></defs>
            </svg>
            SVG);

        self::assertNotNull($svg);
        self::assertStringContainsString('<circle cx="5" cy="5" r="4" fill="url(#g)"/>', $svg);
        self::assertStringContainsString('<linearGradient id="g">', $svg);
        foreach (['script', 'onload', 'onclick', 'foreignObject', 'javascript', '<use', '<a ', 'https://exemple.test'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $svg, $forbidden);
        }
    }

    public function testWhatIsNotASafeSvgIsRefused(): void
    {
        $sanitizer = new SvgSanitizer();

        self::assertNull($sanitizer->sanitize('pas du XML'));
        self::assertNull($sanitizer->sanitize('<html><body>non</body></html>'));
        // Les entités (XXE, « billion laughs ») ne passent pas
        self::assertNull($sanitizer->sanitize('<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>'));
    }
}
