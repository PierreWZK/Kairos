<?php

namespace App\Form\Space;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Nom et adresse e-mail du compte. Changer d'adresse demande le mot de passe actuel (vérifié par le contrôleur).
 *
 * @extends AbstractType<User>
 */
final class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nom', 'empty_data' => ''])
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'empty_data' => '',
                'help' => 'Une nouvelle adresse devra être confirmée par le lien envoyé.',
            ])
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Mot de passe actuel',
                'help' => 'Seulement pour changer d\'adresse e-mail.',
                'mapped' => false,
                'required' => false,
                'attr' => ['autocomplete' => 'current-password'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
