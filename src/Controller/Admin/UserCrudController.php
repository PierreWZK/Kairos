<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\CompanyRole;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;

/**
 * Les comptes de la plateforme (super-admin), en consultation.
 *
 * Un compte se crée par l'inscription d'une entreprise ou par une invitation, et c'est le propriétaire
 * qui gère son équipe depuis son espace. Pour bloquer une entreprise, on la suspend.
 *
 * @extends AbstractCrudController<User>
 */
final class UserCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Compte')
            ->setEntityLabelInPlural('Comptes')
            ->setPageTitle(Crud::PAGE_INDEX, 'Comptes')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['name', 'email', 'company.name'])
            ->setDefaultRowAction(Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom');
        yield EmailField::new('email', 'E-mail');
        yield AssociationField::new('company', 'Entreprise');
        // Libellés fournis par CompanyRole::trans() ; vide pour le super-admin, signalé dans « État »
        yield ChoiceField::new('companyRole', 'Rôle');
        yield TextField::new('accountState', 'État');
        yield AssociationField::new('calendars', 'Calendriers attribués')
            ->onlyOnDetail()
            ->setHelp('Un propriétaire gère tous les calendriers de son entreprise.');
        yield DateTimeField::new('createdAt', 'Créé le')->setFormat('d MMM y');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('company')
            ->add(ChoiceFilter::new('companyRole', 'Rôle')->setChoices(array_combine(
                array_map(static fn (CompanyRole $role): string => $role->label(), CompanyRole::cases()),
                array_map(static fn (CompanyRole $role): string => $role->value, CompanyRole::cases()),
            )))
            ->add('isVerified');
    }
}
