<?php

namespace App\Entity;

use App\Enum\BookingStatus;
use App\Repository\BookingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un rendez-vous pris par un client.
 *
 * La fin (endsAt) est enregistrée au moment de la réservation : si la durée de la prestation change
 * plus tard, les rendez-vous déjà pris ne bougent pas.
 */
#[ORM\Entity(repositoryClass: BookingRepository::class)]
#[ORM\Index(name: 'idx_booking_period', columns: ['starts_at', 'ends_at'])]
class Booking
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // RESTRICT : on ne peut pas supprimer une prestation qui a des rendez-vous (on la désactive)
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    private ?Service $service = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $customerName = '';

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $customerEmail = '';

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $customerPhone = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 1000)]
    private ?string $note = null;

    #[ORM\Column(length: 20, enumType: BookingStatus::class)]
    private BookingStatus $status = BookingStatus::Confirmed;

    /** Jeton secret du lien d'annulation envoyé au client */
    #[ORM\Column(length: 64, unique: true)]
    private string $cancelToken;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    public function __construct()
    {
        $this->cancelToken = bin2hex(random_bytes(32));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('%s · %s', $this->customerName, $this->startsAt?->format('d/m/Y H:i'));
    }

    public function cancel(): void
    {
        if ($this->isCancelled()) {
            return;
        }

        $this->status = BookingStatus::Cancelled;
        $this->cancelledAt = new \DateTimeImmutable();
    }

    public function isCancelled(): bool
    {
        return BookingStatus::Cancelled === $this->status;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getService(): ?Service
    {
        return $this->service;
    }

    public function setService(?Service $service): static
    {
        $this->service = $service;
        $this->refreshEndsAt();

        return $this;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(?\DateTimeImmutable $startsAt): static
    {
        $this->startsAt = $startsAt;
        $this->refreshEndsAt();

        return $this;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getCustomerName(): string
    {
        return $this->customerName;
    }

    public function setCustomerName(?string $customerName): static
    {
        $this->customerName = $customerName ?? '';

        return $this;
    }

    public function getCustomerEmail(): string
    {
        return $this->customerEmail;
    }

    public function setCustomerEmail(?string $customerEmail): static
    {
        $this->customerEmail = $customerEmail ?? '';

        return $this;
    }

    public function getCustomerPhone(): ?string
    {
        return $this->customerPhone;
    }

    public function setCustomerPhone(?string $customerPhone): static
    {
        $this->customerPhone = $customerPhone;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getStatus(): BookingStatus
    {
        return $this->status;
    }

    public function getCancelToken(): string
    {
        return $this->cancelToken;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    /** La fin découle toujours du début et de la durée de la prestation */
    private function refreshEndsAt(): void
    {
        if (null !== $this->startsAt && null !== $this->service) {
            $this->endsAt = $this->startsAt->modify(sprintf('+%d minutes', $this->service->getDurationMinutes()));
        }
    }
}
