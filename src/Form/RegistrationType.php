<?php

namespace App\Form;

use App\Form\Model\Registration;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimezoneType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\String\Slugger\AsciiSlugger;

/** @extends AbstractType<Registration> */
final class RegistrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('companyName', TextType::class, [
                'label' => 'Nom de l\'entreprise',
                'empty_data' => '',
                'attr' => ['autocomplete' => 'organization', 'data-slug-source' => true],
            ])
            ->add('companySlug', TextType::class, [
                'label' => 'Lien de votre page',
                'empty_data' => '',
                'help' => 'Vos clients réserveront à cette adresse. Minuscules, chiffres et tirets.',
                'attr' => ['data-slug-target' => true, 'spellcheck' => 'false'],
            ])
            ->add('timezone', TimezoneType::class, [
                'label' => 'Fuseau horaire',
                'help' => 'Vos horaires et vos rendez-vous s\'affichent à cette heure.',
                'preferred_choices' => ['Europe/Paris', 'Europe/Brussels', 'Europe/Zurich', 'America/Toronto', 'Indian/Reunion'],
            ])
            ->add('name', TextType::class, [
                'label' => 'Votre nom',
                'empty_data' => '',
                'attr' => ['autocomplete' => 'name'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Votre adresse e-mail',
                'empty_data' => '',
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Mot de passe',
                'empty_data' => '',
                'help' => 'Au moins 8 caractères.',
                'attr' => ['autocomplete' => 'new-password'],
            ]);

        // Lien laissé vide : déduit du nom (« Salon Lumière » → « salon-lumiere »), en minuscules dans tous les cas
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();
            if (!\is_array($data)) {
                return;
            }
            $slug = trim((string) ($data['companySlug'] ?? ''));
            if ('' === $slug) {
                $slug = (string) (new AsciiSlugger('fr'))->slug((string) ($data['companyName'] ?? ''));
            }
            $data['companySlug'] = mb_strtolower($slug);
            $data['email'] = mb_strtolower(trim((string) ($data['email'] ?? '')));
            $event->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Registration::class]);
    }
}
