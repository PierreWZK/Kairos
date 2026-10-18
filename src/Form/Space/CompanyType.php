<?php

namespace App\Form\Space;

use App\Entity\Company;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimezoneType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * La fiche de l'entreprise et sa page publique. Le logo est traité à part (LogoStorage).
 *
 * @extends AbstractType<Company>
 */
final class CompanyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nom de l\'entreprise', 'empty_data' => ''])
            ->add('slug', TextType::class, [
                'label' => 'Lien de votre page',
                'empty_data' => '',
                'help' => 'Attention : le changer rend l\'ancien lien inutilisable, y compris dans le code intégré à votre site.',
                'attr' => ['spellcheck' => 'false'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Présentation',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('address', TextType::class, ['label' => 'Adresse', 'required' => false])
            ->add('accentColor', ColorType::class, [
                'label' => 'Couleur',
                'help' => 'Boutons, liens et créneaux libres de votre page. Une teinte assez foncée pour du texte blanc.',
            ])
            ->add('logo', FileType::class, [
                'label' => 'Logo',
                'mapped' => false,
                'required' => false,
                'help' => 'PNG, JPEG, WebP ou SVG, 1 Mo au plus. Carré de préférence.',
                'attr' => ['accept' => 'image/png,image/jpeg,image/webp,image/svg+xml'],
            ])
            ->add('removeLogo', CheckboxType::class, [
                'label' => 'Retirer le logo (l\'initiale de l\'entreprise s\'affichera)',
                'mapped' => false,
                'required' => false,
            ])
            ->add('timezone', TimezoneType::class, [
                'label' => 'Fuseau horaire',
                'help' => 'Les horaires d\'ouverture restent à la même heure locale ; les rendez-vous déjà pris gardent leur instant.',
                'preferred_choices' => ['Europe/Paris', 'Europe/Brussels', 'Europe/Zurich', 'America/Toronto', 'Indian/Reunion'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Company::class]);
    }
}
