<?php

namespace App\Form\Model;

use App\Entity\Company;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Le formulaire d'inscription : une entreprise et son propriétaire, créés ensemble.
 */
#[UniqueEntity(fields: ['email'], entityClass: User::class, errorPath: 'email', message: 'Un compte existe déjà avec cette adresse.')]
#[UniqueEntity(fields: ['companySlug' => 'slug'], entityClass: Company::class, errorPath: 'companySlug', message: 'Ce lien est déjà utilisé par une autre entreprise.')]
final class Registration
{
    #[Assert\NotBlank(message: 'Indiquez le nom de votre entreprise.')]
    #[Assert\Length(max: 120)]
    public string $companyName = '';

    #[Assert\NotBlank(message: 'Choisissez le lien de votre page.')]
    #[Assert\Length(min: 3, max: 60)]
    #[Assert\Regex('/^'.Company::SLUG_PATTERN.'$/', message: 'Uniquement des minuscules, des chiffres et des tirets (ex. « salon-lumiere »).')]
    public string $companySlug = '';

    #[Assert\NotBlank]
    #[Assert\Timezone]
    public string $timezone = 'Europe/Paris';

    #[Assert\NotBlank(message: 'Indiquez votre nom.')]
    #[Assert\Length(max: 120)]
    public string $name = '';

    #[Assert\NotBlank(message: 'Indiquez votre adresse e-mail.')]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public string $email = '';

    #[Assert\NotBlank(message: 'Choisissez un mot de passe.')]
    #[Assert\Length(min: 8, max: 4096, minMessage: 'Au moins {{ limit }} caractères.')]
    #[Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_WEAK, message: 'Ce mot de passe est trop facile à deviner : allongez-le ou mélangez lettres, chiffres et symboles.')]
    public string $plainPassword = '';

    #[Assert\Callback]
    public function validateSlug(ExecutionContextInterface $context): void
    {
        if (\in_array($this->companySlug, Company::RESERVED_SLUGS, true)) {
            $context->buildViolation('Ce lien est réservé par Kairos, choisissez-en un autre.')
                ->atPath('companySlug')
                ->addViolation();
        }
    }
}
