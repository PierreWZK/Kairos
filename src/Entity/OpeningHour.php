<?php

namespace App\Entity;

use App\Repository\OpeningHourRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une plage d'ouverture hebdomadaire (ex. lundi 09:00 → 12:00).
 * Un jour peut en avoir plusieurs, pour couper la pause de midi.
 */
#[ORM\Entity(repositoryClass: OpeningHourRepository::class)]
class OpeningHour
{
    public const array DAY_NAMES = [
        1 => 'Lundi',
        2 => 'Mardi',
        3 => 'Mercredi',
        4 => 'Jeudi',
        5 => 'Vendredi',
        6 => 'Samedi',
        7 => 'Dimanche',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Jour ISO-8601 : 1 = lundi … 7 = dimanche (comme date('N')) */
    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\Range(min: 1, max: 7)]
    private int $dayOfWeek = 1;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $startTime = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    #[Assert\NotNull]
    #[Assert\GreaterThan(propertyPath: 'startTime', message: "L'heure de fin doit être après l'heure de début.")]
    private ?\DateTimeImmutable $endTime = null;

    public function __toString(): string
    {
        return sprintf('%s %s → %s', $this->getDayName(), $this->startTime?->format('H:i'), $this->endTime?->format('H:i'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDayOfWeek(): int
    {
        return $this->dayOfWeek;
    }

    public function getDayName(): string
    {
        return self::DAY_NAMES[$this->dayOfWeek];
    }

    public function setDayOfWeek(int $dayOfWeek): static
    {
        $this->dayOfWeek = $dayOfWeek;

        return $this;
    }

    public function getStartTime(): ?\DateTimeImmutable
    {
        return $this->startTime;
    }

    public function setStartTime(\DateTimeImmutable $startTime): static
    {
        $this->startTime = $startTime;

        return $this;
    }

    public function getEndTime(): ?\DateTimeImmutable
    {
        return $this->endTime;
    }

    public function setEndTime(\DateTimeImmutable $endTime): static
    {
        $this->endTime = $endTime;

        return $this;
    }
}
