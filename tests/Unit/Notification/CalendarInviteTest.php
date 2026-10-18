<?php

namespace App\Tests\Unit\Notification;

use App\Entity\Booking;
use App\Entity\Service;
use App\Notification\CalendarInvite;
use PHPUnit\Framework\TestCase;

final class CalendarInviteTest extends TestCase
{
    public function testTheInviteIsInUtcWithEscapedText(): void
    {
        $service = (new Service())->setName('Séance, version 1; test')->setDurationMinutes(60);
        // 22 octobre 2026 : heure d'été, Paris = UTC+2
        $booking = (new Booking())->setService($service)->setStartsAt(new \DateTimeImmutable('2026-10-22 10:00'));

        $ics = CalendarInvite::forBooking($booking, 'https://kairos.test/rendez-vous/abc');

        self::assertStringContainsString("DTSTART:20261022T080000Z\r\n", $ics);
        self::assertStringContainsString("DTEND:20261022T090000Z\r\n", $ics);
        self::assertStringContainsString("SUMMARY:Séance\\, version 1\\; test\r\n", $ics);
        self::assertStringContainsString("URL:https://kairos.test/rendez-vous/abc\r\n", $ics);
        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
    }
}
