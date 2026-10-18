<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** Accepter une invitation : vérifier son nom, choisir son mot de passe */
final class InvitationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Votre nom',
            'empty_data' => '',
            'attr' => ['autocomplete' => 'name'],
            'constraints' => [new Assert\NotBlank(message: 'Indiquez votre nom.'), new Assert\Length(max: 120)],
        ]);
    }

    public function getParent(): string
    {
        // Le champ « nouveau mot de passe » répété, avec ses règles
        return NewPasswordType::class;
    }
}
