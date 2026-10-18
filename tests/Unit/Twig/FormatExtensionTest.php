<?php

namespace App\Tests\Unit\Twig;

use App\Twig\FormatExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormatExtensionTest extends TestCase
{
    #[DataProvider('durations')]
    public function testDuration(int $minutes, string $expected): void
    {
        self::assertSame($expected, (new FormatExtension())->duration($minutes));
    }

    /** @return iterable<array{int, string}> */
    public static function durations(): iterable
    {
        yield [30, '30 min'];
        yield [60, '1 h'];
        yield [90, '1 h 30'];
        yield [125, '2 h 05'];
    }

    #[DataProvider('prices')]
    public function testPrice(int $cents, string $expected): void
    {
        self::assertSame($expected, (new FormatExtension())->price($cents));
    }

    /** @return iterable<array{int, string}> */
    public static function prices(): iterable
    {
        yield [0, 'Gratuit'];
        yield [6000, '60 €'];
        yield [8550, '85,50 €'];
        yield [120000, "1\u{202F}200 €"];
    }
}
