<?php

namespace App\Form\Space;

use App\Form\NewPasswordType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;

/** Nouveau mot de passe, après le mot de passe actuel */
final class ChangePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('currentPassword', PasswordType::class, [
            'label' => 'Mot de passe actuel',
            'attr' => ['autocomplete' => 'current-password'],
            'constraints' => [new UserPassword(message: 'Ce n\'est pas votre mot de passe actuel.')],
        ]);
    }

    public function getParent(): string
    {
        // Le champ « nouveau mot de passe » répété, avec ses règles
        return NewPasswordType::class;
    }
}
