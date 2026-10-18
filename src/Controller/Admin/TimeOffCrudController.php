<?php

namespace App\Controller\Admin;

use App\Entity\TimeOff;
use App\Twig\CompanyTime;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

/**
 * Les fermetures de toutes les entreprises (super-admin), en consultation.
 *
 * Chaque entreprise les saisit depuis son espace, dans son fuseau horaire : un formulaire ici les saisirait
 * à l'heure de Paris, décalées pour une entreprise d'un autre fuseau. On peut seulement en supprimer une.
 *
 * @extends AbstractCrudController<TimeOff>
 */
final class TimeOffCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return TimeOff::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Fermeture')
            ->setEntityLabelInPlural('Fermetures')
            ->setPageTitle(Crud::PAGE_INDEX, 'Fermetures')
            ->setHelp(Crud::PAGE_INDEX, 'Congés, jours fériés, formations… saisis par chaque entreprise dans son espace, et affichés à son heure locale.')
            ->setDefaultSort(['startsAt' => 'DESC'])
            ->setSearchFields(['reason', 'calendar.name', 'calendar.company.name']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('calendar', 'Entreprise · calendrier')
            ->formatValue(static fn ($value, TimeOff $timeOff): string => CompanyTime::calendarLabel($timeOff->getCalendar()));
        yield DateTimeField::new('startsAt', 'Début')
            ->formatValue(static fn ($value, TimeOff $timeOff): string => CompanyTime::format($value, $timeOff->getCalendar()));
        yield DateTimeField::new('endsAt', 'Fin')
            ->formatValue(static fn ($value, TimeOff $timeOff): string => CompanyTime::format($value, $timeOff->getCalendar()));
        yield TextField::new('reason', 'Motif');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('calendar', 'Calendrier')->setFormTypeOption('value_type_options.choice_label', CompanyTime::calendarLabel(...)))
            ->add('startsAt');
    }
}
