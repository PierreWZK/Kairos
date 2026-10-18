<?php

namespace App\Controller\Admin;

use App\Entity\OpeningHour;
use App\Twig\CompanyTime;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TimeField;

/**
 * @extends AbstractCrudController<OpeningHour>
 */
final class OpeningHourCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return OpeningHour::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Plage d\'ouverture')
            ->setEntityLabelInPlural('Horaires d\'ouverture')
            ->setPageTitle(Crud::PAGE_INDEX, 'Horaires d\'ouverture')
            ->setHelp(Crud::PAGE_INDEX, 'Une ligne par plage, à l\'heure locale de l\'entreprise. Pour couper la pause de midi, deux plages le même jour (9:00 → 12:00 et 14:00 → 18:00).')
            ->setSearchFields(['calendar.name', 'calendar.company.name'])
            ->setDefaultSort(['calendar' => 'ASC', 'dayOfWeek' => 'ASC', 'startTime' => 'ASC'])
            ->setPaginatorPageSize(50);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('calendar', 'Entreprise · calendrier')
            ->formatValue(static fn ($value, OpeningHour $hour): string => CompanyTime::calendarLabel($hour->getCalendar()))
            ->setFormTypeOption('choice_label', CompanyTime::calendarLabel(...));
        yield ChoiceField::new('dayOfWeek', 'Jour')
            ->setChoices(array_flip(OpeningHour::DAY_NAMES));
        yield TimeField::new('startTime', 'Début')->setFormat('HH:mm');
        yield TimeField::new('endTime', 'Fin')->setFormat('HH:mm');
    }
}
