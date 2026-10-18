<?php

namespace App\Controller\Admin;

use App\Entity\OpeningHour;
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
            ->setHelp(Crud::PAGE_INDEX, 'Une ligne par plage. Pour couper la pause de midi, créez deux plages le même jour (9:00 → 12:00 et 14:00 → 18:00).')
            ->setDefaultSort(['calendar' => 'ASC', 'dayOfWeek' => 'ASC', 'startTime' => 'ASC'])
            ->setPaginatorPageSize(50);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('calendar', 'Calendrier');
        yield ChoiceField::new('dayOfWeek', 'Jour')
            ->setChoices(array_flip(OpeningHour::DAY_NAMES));
        yield TimeField::new('startTime', 'Début')->setFormat('HH:mm');
        yield TimeField::new('endTime', 'Fin')->setFormat('HH:mm');
    }
}
