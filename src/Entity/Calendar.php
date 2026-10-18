<?php

namespace App\Entity;

use App\Repository\CalendarRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un agenda réservable d'une entreprise (une personne, une salle, un lieu…), avec ses propres
 * horaires, fermetures, prestations proposées et règles. Lien public : /{entreprise}/{slug}.
 */
#[ORM\Entity(repositoryClass: CalendarRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_calendar_company_slug', columns: ['company_id', 'slug'])]
#[UniqueEntity(fields: ['company', 'slug'], message: 'Un autre calendrier de l\'entreprise utilise déjà ce lien.', errorPath: 'slug')]
class Calendar
{
    /** Pas possibles entre deux débuts de créneau */
    public const array SLOT_STEPS = [10, 15, 20, 30, 45, 60];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'calendars')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Company $company = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name = '';

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 60)]
    #[Assert\Regex('/^'.Company::SLUG_PATTERN.'$/', message: 'Uniquement des minuscules, des chiffres et des tirets (ex. « julie »).')]
    private string $slug = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 1000)]
    private ?string $description = null;

    /** Fermé à la réservation (mais conservé, avec son historique) */
    #[ORM\Column]
    private bool $isActive = true;

    // Règles de l'agenda

    /** Un créneau peut commencer toutes les N minutes */
    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\Choice(choices: self::SLOT_STEPS)]
    private int $slotStepMinutes = 30;

    /** Délai minimum entre maintenant et le début d'un rendez-vous */
    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\Range(min: 0, max: 720)]
    private int $minNoticeHours = 2;

    /** Jusqu'où l'agenda est ouvert à la réservation */
    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\Range(min: 1, max: 52)]
    private int $horizonWeeks = 4;

    /** Le client peut annuler en ligne jusqu'à N heures avant le rendez-vous */
    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\Range(min: 0, max: 720)]
    private int $cancelNoticeHours = 2;

    /** Les demandes arrivent « en attente » et l'entreprise les accepte ou les refuse */
    #[ORM\Column]
    private bool $requiresApproval = false;

    // Formulaire de réservation

    #[ORM\Column]
    private bool $phoneRequired = false;

    #[ORM\Column]
    private bool $notesEnabled = true;

    /** Message affiché au client au-dessus du formulaire */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $formMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Service> Les prestations du catalogue proposées sur ce calendrier */
    #[ORM\ManyToMany(targetEntity: Service::class)]
    #[ORM\JoinTable(name: 'calendar_service')]
    private Collection $services;

    /** @var Collection<int, OpeningHour> */
    #[ORM\OneToMany(targetEntity: OpeningHour::class, mappedBy: 'calendar', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['dayOfWeek' => 'ASC', 'startTime' => 'ASC'])]
    private Collection $openingHours;

    /** @var Collection<int, User> Les membres de l'entreprise qui gèrent ce calendrier */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'calendars')]
    private Collection $members;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->services = new ArrayCollection();
        $this->openingHours = new ArrayCollection();
        $this->members = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): static
    {
        $this->company = $company;

        return $this;
    }

    /** Fuseau de l'entreprise : celui des horaires d'ouverture */
    public function getDateTimeZone(): \DateTimeZone
    {
        return $this->company?->getDateTimeZone() ?? new \DateTimeZone('UTC');
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name ?? '';

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = mb_strtolower(trim($slug ?? ''));

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(?bool $isActive): static
    {
        $this->isActive = (bool) $isActive;

        return $this;
    }

    public function getSlotStepMinutes(): int
    {
        return $this->slotStepMinutes;
    }

    public function setSlotStepMinutes(?int $slotStepMinutes): static
    {
        $this->slotStepMinutes = $slotStepMinutes ?? 0;

        return $this;
    }

    public function getMinNoticeHours(): int
    {
        return $this->minNoticeHours;
    }

    public function setMinNoticeHours(?int $minNoticeHours): static
    {
        $this->minNoticeHours = $minNoticeHours ?? 0;

        return $this;
    }

    public function getHorizonWeeks(): int
    {
        return $this->horizonWeeks;
    }

    public function setHorizonWeeks(?int $horizonWeeks): static
    {
        $this->horizonWeeks = $horizonWeeks ?? 0;

        return $this;
    }

    public function getCancelNoticeHours(): int
    {
        return $this->cancelNoticeHours;
    }

    public function setCancelNoticeHours(?int $cancelNoticeHours): static
    {
        $this->cancelNoticeHours = $cancelNoticeHours ?? 0;

        return $this;
    }

    public function requiresApproval(): bool
    {
        return $this->requiresApproval;
    }

    public function setRequiresApproval(?bool $requiresApproval): static
    {
        $this->requiresApproval = (bool) $requiresApproval;

        return $this;
    }

    public function isPhoneRequired(): bool
    {
        return $this->phoneRequired;
    }

    public function setPhoneRequired(?bool $phoneRequired): static
    {
        $this->phoneRequired = (bool) $phoneRequired;

        return $this;
    }

    public function areNotesEnabled(): bool
    {
        return $this->notesEnabled;
    }

    public function setNotesEnabled(?bool $notesEnabled): static
    {
        $this->notesEnabled = (bool) $notesEnabled;

        return $this;
    }

    public function getFormMessage(): ?string
    {
        return $this->formMessage;
    }

    public function setFormMessage(?string $formMessage): static
    {
        $this->formMessage = $formMessage;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Service> */
    public function getServices(): Collection
    {
        return $this->services;
    }

    /** @return list<Service> Les prestations réservables ici, de la plus courte à la plus longue */
    public function getOfferedServices(): array
    {
        $services = array_values($this->services->filter(static fn (Service $service): bool => $service->isActive())->toArray());
        usort($services, static fn (Service $a, Service $b): int => [$a->getDurationMinutes(), $a->getName()] <=> [$b->getDurationMinutes(), $b->getName()]);

        return $services;
    }

    public function offers(Service $service): bool
    {
        return $service->isActive() && $this->services->contains($service);
    }

    public function addService(Service $service): static
    {
        if (!$this->services->contains($service)) {
            $this->services->add($service);
        }

        return $this;
    }

    public function removeService(Service $service): static
    {
        $this->services->removeElement($service);

        return $this;
    }

    /** @return Collection<int, OpeningHour> */
    public function getOpeningHours(): Collection
    {
        return $this->openingHours;
    }

    public function addOpeningHour(OpeningHour $openingHour): static
    {
        if (!$this->openingHours->contains($openingHour)) {
            $this->openingHours->add($openingHour);
            $openingHour->setCalendar($this);
        }

        return $this;
    }

    public function removeOpeningHour(OpeningHour $openingHour): static
    {
        $this->openingHours->removeElement($openingHour);

        return $this;
    }

    /** @return Collection<int, User> */
    public function getMembers(): Collection
    {
        return $this->members;
    }
}
