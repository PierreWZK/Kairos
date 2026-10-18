<?php

namespace App\Scheduling;

/**
 * Les créneaux d'une journée. Une journée sans créneau est fermée : jour sans horaires d'ouverture,
 * ou fermeture qui couvre toute la journée (closedReason donne alors son motif).
 */
final readonly class DaySchedule
{
    /**
     * @param list<Slot> $slots
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public array $slots,
        public ?string $closedReason = null,
    ) {
    }

    public function isClosed(): bool
    {
        return [] === $this->slots;
    }

    public function hasFreeSlot(): bool
    {
        foreach ($this->slots as $slot) {
            if ($slot->isFree()) {
                return true;
            }
        }

        return false;
    }
}
