<?php

namespace App\Controller\Admin;

use App\Entity\Company;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ColorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TimezoneField;

/**
 * Les entreprises inscrites (super-admin). Une entreprise abusive se suspend : sa page publique et son espace ferment.
 *
 * @extends AbstractCrudController<Company>
 */
final class CompanyCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Company::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Entreprise')
            ->setEntityLabelInPlural('Entreprises')
            ->setPageTitle(Crud::PAGE_INDEX, 'Entreprises')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['name', 'slug'])
            ->setDefaultRowAction(Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom');
        yield TextField::new('slug', 'Lien')->setHelp('Adresse publique : /{lien}');
        yield TimezoneField::new('timezone', 'Fuseau horaire')->hideOnIndex();
        yield ColorField::new('accentColor', 'Couleur')->hideOnIndex();
        yield TextareaField::new('description', 'Description')->hideOnIndex();
        yield TextField::new('address', 'Adresse')->hideOnIndex();
        yield AssociationField::new('calendars', 'Calendriers')->onlyOnIndex();
        yield AssociationField::new('users', 'Membres')->onlyOnIndex();
        yield BooleanField::new('isSuspended', 'Suspendue')->renderAsSwitch();
        yield DateTimeField::new('createdAt', 'Inscrite le')->setFormat('d MMM y')->hideOnForm();
    }

    public function configureActions(Actions $actions): Actions
    {
        // Une entreprise se crée par l'inscription, et ne se supprime pas d'ici (on la suspend)
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::DELETE);
    }
}
