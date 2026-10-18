<?php

namespace App\Entity;

use App\Repository\TimeOffRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une fermeture ponctuelle (congés, jour férié, formation…) : aucun créneau n'est proposé pendant cette période.
 */
#[ORM\Entity(repositoryClass: TimeOffRepository::class)]
#[ORM\Index(name: 'idx_time_off_period', columns: ['starts_at', 'ends_at'])]
class TimeOff
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull]
    #[Assert\GreaterThan(propertyPath: 'startsAt', message: 'La fin de la fermeture doit être après son début.')]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $reason = null;

    public function __toString(): string
    {
        return $this->reason ?? 'Fermeture';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(\DateTimeImmutable $startsAt): static
    {
        $this->startsAt = $startsAt;

        return $this;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(\DateTimeImmutable $endsAt): static
    {
        $this->endsAt = $endsAt;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }
}
