<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Company;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyTest extends TestCase
{
    #[DataProvider('colors')]
    public function testTheAccentColorMustStayReadableWithWhiteText(string $color, bool $readable): void
    {
        self::assertSame($readable, Company::contrastWithLight($color) >= Company::MIN_ACCENT_CONTRAST, $color);
    }

    /** @return iterable<array{string, bool}> */
    public static function colors(): iterable
    {
        yield 'vert Kairos' => ['#0f5c56', true];
        yield 'terracotta du salon' => ['#b4513a', true];
        yield 'noir' => ['#000000', true];
        yield 'jaune' => ['#f5c518', false];
        yield 'blanc' => ['#ffffff', false];
        yield 'gris clair' => ['#aaaaaa', false];
    }

    public function testBlackOnLightIsTheMaximumContrast(): void
    {
        self::assertEqualsWithDelta(20.6, Company::contrastWithLight('#000000'), 0.2);
    }
}
