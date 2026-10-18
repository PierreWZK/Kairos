<?php

namespace App\Entity;

use App\Enum\CompanyRole;
use App\Repository\CompanyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Une entreprise inscrite sur Kairos : ses calendriers, son catalogue de prestations, ses membres.
 * Ses clients la trouvent à l'adresse /{slug}.
 */
#[ORM\Entity(repositoryClass: CompanyRepository::class)]
#[UniqueEntity('slug', message: 'Ce lien est déjà utilisé par une autre entreprise.')]
class Company
{
    /** Format d'un lien : minuscules, chiffres et tirets (ex. « salon-lumiere ») */
    public const string SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    /** Adresses de l'appli, interdites comme lien d'entreprise */
    public const array RESERVED_SLUGS = [
        'admin', 'api', 'assets', 'bundles', 'connexion', 'deconnexion', 'demo', 'espace', 'inscription',
        'integration', 'kairos', 'mot-de-passe-oublie', 'rendez-vous', 'reserver', 'uploads', 'verification',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name = '';

    #[ORM\Column(length: 60, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 60)]
    #[Assert\Regex('/^'.self::SLUG_PATTERN.'$/', message: 'Uniquement des minuscules, des chiffres et des tirets (ex. « salon-lumiere »).')]
    private string $slug = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 1000)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $address = null;

    /** Contraste minimal de la couleur d'accent avec le fond clair, pour que boutons et liens restent lisibles */
    public const float MIN_ACCENT_CONTRAST = 3.0;

    /** Couleur d'accent de la page publique */
    #[ORM\Column(length: 7)]
    #[Assert\Regex('/^#[0-9a-fA-F]{6}$/', message: 'Une couleur au format #RRGGBB.')]
    private string $accentColor = '#0f5c56';

    /** Nom du fichier du logo dans public/uploads/logos */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoFilename = null;

    /** Fuseau des horaires et de l'affichage : les dates sont stockées en UTC */
    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Assert\Timezone]
    private string $timezone = 'Europe/Paris';

    /** Suspendue par le super-admin : la page publique et l'espace sont fermés */
    #[ORM\Column]
    private bool $isSuspended = false;

    /**
     * Entreprise de démonstration (App\Demo\DemoData), recréée chaque nuit par app:demo:reset.
     * Ses comptes sont publics : ce qui bloquerait les visiteurs suivants ou enverrait des e-mails y est désactivé.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $isDemo = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Calendar> */
    #[ORM\OneToMany(targetEntity: Calendar::class, mappedBy: 'company', cascade: ['persist'])]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $calendars;

    /** @var Collection<int, Service> */
    #[ORM\OneToMany(targetEntity: Service::class, mappedBy: 'company', cascade: ['persist'])]
    #[ORM\OrderBy(['durationMinutes' => 'ASC', 'name' => 'ASC'])]
    private Collection $services;

    /** @var Collection<int, User> */
    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'company')]
    private Collection $users;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->calendars = new ArrayCollection();
        $this->services = new ArrayCollection();
        $this->users = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    #[Assert\Callback]
    public function validateSlug(ExecutionContextInterface $context): void
    {
        if (\in_array($this->slug, self::RESERVED_SLUGS, true)) {
            $context->buildViolation('Ce lien est réservé par Kairos, choisissez-en un autre.')
                ->atPath('slug')
                ->addViolation();
        }
    }

    #[Assert\Callback]
    public function validateAccentColor(ExecutionContextInterface $context): void
    {
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $this->accentColor) && self::contrastWithLight($this->accentColor) < self::MIN_ACCENT_CONTRAST) {
            $context->buildViolation('Cette couleur est trop claire : le texte blanc des boutons ne serait pas lisible. Choisissez une teinte plus foncée.')
                ->atPath('accentColor')
                ->addViolation();
        }
    }

    /** Rapport de contraste WCAG entre une couleur #RRGGBB et le fond clair des pages (#fffdf8) */
    public static function contrastWithLight(string $hex): float
    {
        $channel = static function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $luminance = 0.2126 * $channel(substr($hex, 1, 2)) + 0.7152 * $channel(substr($hex, 3, 2)) + 0.0722 * $channel(substr($hex, 5, 2));
        $light = 0.2126 * $channel('ff') + 0.7152 * $channel('fd') + 0.0722 * $channel('f8');

        return ($light + 0.05) / ($luminance + 0.05);
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getAccentColor(): string
    {
        return $this->accentColor;
    }

    public function setAccentColor(?string $accentColor): static
    {
        $this->accentColor = $accentColor ?? '#0f5c56';

        return $this;
    }

    public function getLogoFilename(): ?string
    {
        return $this->logoFilename;
    }

    public function setLogoFilename(?string $logoFilename): static
    {
        $this->logoFilename = $logoFilename;

        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function getDateTimeZone(): \DateTimeZone
    {
        return new \DateTimeZone($this->timezone);
    }

    public function setTimezone(?string $timezone): static
    {
        $this->timezone = $timezone ?? '';

        return $this;
    }

    public function isDemo(): bool
    {
        return $this->isDemo;
    }

    public function setIsDemo(bool $isDemo): static
    {
        $this->isDemo = $isDemo;

        return $this;
    }

    public function isSuspended(): bool
    {
        return $this->isSuspended;
    }

    public function setIsSuspended(?bool $isSuspended): static
    {
        $this->isSuspended = (bool) $isSuspended;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Calendar> */
    public function getCalendars(): Collection
    {
        return $this->calendars;
    }

    /** @return list<Calendar> Les calendriers ouverts à la réservation */
    public function getActiveCalendars(): array
    {
        return array_values($this->calendars->filter(static fn (Calendar $calendar): bool => $calendar->isActive())->toArray());
    }

    public function addCalendar(Calendar $calendar): static
    {
        if (!$this->calendars->contains($calendar)) {
            $this->calendars->add($calendar);
            $calendar->setCompany($this);
        }

        return $this;
    }

    /** @return Collection<int, Service> */
    public function getServices(): Collection
    {
        return $this->services;
    }

    public function addService(Service $service): static
    {
        if (!$this->services->contains($service)) {
            $this->services->add($service);
            $service->setCompany($this);
        }

        return $this;
    }

    /** @return Collection<int, User> */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    /** @return list<User> */
    public function getOwners(): array
    {
        return array_values($this->users->filter(static fn (User $user): bool => CompanyRole::Owner === $user->getCompanyRole())->toArray());
    }
}
