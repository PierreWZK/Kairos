<?php

namespace App\Entity;

use App\Enum\CompanyRole;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un compte : membre d'une entreprise (propriétaire ou membre), ou super-admin de la plateforme (sans entreprise).
 * Les clients qui réservent, eux, n'ont pas de compte.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[UniqueEntity('email', message: 'Un compte existe déjà avec cette adresse.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name = '';

    /** @var list<string> Rôles stockés (ex. ROLE_SUPER_ADMIN) ; le rôle d'entreprise s'y ajoute dans getRoles() */
    #[ORM\Column]
    private array $roles = [];

    /** Mot de passe haché (jamais en clair) */
    #[ORM\Column]
    private string $password = '';

    /** L'adresse e-mail a été confirmée par le lien de vérification */
    #[ORM\Column]
    private bool $isVerified = false;

    #[ORM\ManyToOne(inversedBy: 'users')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Company $company = null;

    #[ORM\Column(length: 20, nullable: true, enumType: CompanyRole::class)]
    private ?CompanyRole $companyRole = null;

    /** @var Collection<int, Calendar> Calendriers gérés par un membre (le propriétaire les gère tous) */
    #[ORM\ManyToMany(targetEntity: Calendar::class, inversedBy: 'members')]
    #[ORM\JoinTable(name: 'user_calendar')]
    private Collection $calendars;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->calendars = new ArrayCollection();
    }

    public function __toString(): string
    {
        return '' !== $this->name ? $this->name : $this->email;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = mb_strtolower(trim($email ?? ''));

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
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

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = [...$this->roles, 'ROLE_USER'];
        if (null !== $this->companyRole) {
            $roles[] = $this->companyRole->securityRole();
        }

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /** Invité dans l'équipe, sans avoir encore choisi son mot de passe : il ne peut pas se connecter */
    public function isInvitationPending(): bool
    {
        return '' === $this->password;
    }

    /** L'état du compte en une phrase (liste des comptes du super-admin) */
    public function getAccountState(): string
    {
        return match (true) {
            \in_array('ROLE_SUPER_ADMIN', $this->roles, true) => 'Super-admin',
            $this->isInvitationPending() => 'Invitation en attente',
            $this->isVerified => 'Adresse confirmée',
            default => 'Adresse à confirmer',
        };
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;

        return $this;
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

    public function getCompanyRole(): ?CompanyRole
    {
        return $this->companyRole;
    }

    public function setCompanyRole(?CompanyRole $companyRole): static
    {
        $this->companyRole = $companyRole;

        return $this;
    }

    public function isOwner(): bool
    {
        return CompanyRole::Owner === $this->companyRole;
    }

    /** @return Collection<int, Calendar> */
    public function getCalendars(): Collection
    {
        return $this->calendars;
    }

    public function addCalendar(Calendar $calendar): static
    {
        if (!$this->calendars->contains($calendar)) {
            $this->calendars->add($calendar);
        }

        return $this;
    }

    public function removeCalendar(Calendar $calendar): static
    {
        $this->calendars->removeElement($calendar);

        return $this;
    }

    /** Le propriétaire gère tous les calendriers de son entreprise ; un membre, ceux qui lui sont attribués */
    public function manages(Calendar $calendar): bool
    {
        if (null === $this->company || $calendar->getCompany() !== $this->company) {
            return false;
        }

        return $this->isOwner() || $this->calendars->contains($calendar);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Garde un hachage du mot de passe en session plutôt que le hachage lui-même
     * (comportement recommandé depuis Symfony 7.3).
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
    }
}
