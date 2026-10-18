<?php

namespace App\Controller\Admin;

use App\Entity\Calendar;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Les calendriers de toutes les entreprises (super-admin). Les entreprises les gèrent depuis leur espace.
 *
 * @extends AbstractCrudController<Calendar>
 */
final class CalendarCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Calendar::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Calendrier')
            ->setEntityLabelInPlural('Calendriers')
            ->setPageTitle(Crud::PAGE_INDEX, 'Calendriers')
            ->setDefaultSort(['company' => 'ASC', 'name' => 'ASC'])
            ->setSearchFields(['name', 'slug'])
            ->setDefaultRowAction(Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset('Calendrier');
        yield AssociationField::new('company', 'Entreprise');
        yield TextField::new('name', 'Nom');
        yield TextField::new('slug', 'Lien')->setHelp('Adresse publique : /{entreprise}/{lien}');
        yield TextareaField::new('description', 'Description')->hideOnIndex();
        yield AssociationField::new('services', 'Prestations proposées')->hideOnIndex();
        yield BooleanField::new('isActive', 'Ouvert')->renderAsSwitch();

        yield FormField::addFieldset('Règles')->hideOnIndex();
        yield IntegerField::new('slotStepMinutes', 'Pas des créneaux (min)')->hideOnIndex();
        yield IntegerField::new('minNoticeHours', 'Délai minimum (h)')->hideOnIndex();
        yield IntegerField::new('horizonWeeks', 'Horizon (semaines)')->hideOnIndex();
        yield IntegerField::new('cancelNoticeHours', 'Délai d\'annulation (h)')->hideOnIndex();
        yield BooleanField::new('requiresApproval', 'Validation manuelle');

        yield FormField::addFieldset('Formulaire')->hideOnIndex();
        yield BooleanField::new('phoneRequired', 'Téléphone obligatoire')->hideOnIndex();
        yield BooleanField::new('notesEnabled', 'Note autorisée')->hideOnIndex();
        yield TextareaField::new('formMessage', 'Message affiché')->hideOnIndex();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('company')->add('isActive');
    }
}
