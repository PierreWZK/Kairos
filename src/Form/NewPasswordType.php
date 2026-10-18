<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** Choix d'un nouveau mot de passe (lien « mot de passe oublié ») */
final class NewPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
            'first_options' => ['label' => 'Nouveau mot de passe', 'help' => 'Au moins 8 caractères.', 'attr' => ['autocomplete' => 'new-password']],
            'second_options' => ['label' => 'Confirmez-le', 'attr' => ['autocomplete' => 'new-password']],
            'constraints' => [
                new Assert\NotBlank(message: 'Choisissez un mot de passe.'),
                new Assert\Length(min: 8, max: 4096, minMessage: 'Au moins {{ limit }} caractères.'),
                new Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_WEAK, message: 'Ce mot de passe est trop facile à deviner : allongez-le ou mélangez lettres, chiffres et symboles.'),
            ],
        ]);
    }
}
