<?php

namespace App\Scheduling;

final readonly class Slot
{
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        public SlotStatus $status,
    ) {
    }

    public function isFree(): bool
    {
        return SlotStatus::Free === $this->status;
    }
}
