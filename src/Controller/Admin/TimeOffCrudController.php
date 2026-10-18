<?php

namespace App\Controller\Admin;

use App\Entity\TimeOff;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
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
            ->setHelp(Crud::PAGE_INDEX, 'Congés, jours fériés, formations… Aucun créneau n\'est proposé pendant une fermeture. Les rendez-vous déjà pris ne sont pas annulés automatiquement.')
            ->setDefaultSort(['startsAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('startsAt', 'Début')->setFormat('EEE d MMM y, HH:mm');
        yield DateTimeField::new('endsAt', 'Fin')
            ->setFormat('EEE d MMM y, HH:mm')
            ->setHelp('Pour fermer une journée entière : de 00:00 au lendemain 00:00.');
        yield TextField::new('reason', 'Motif')->setHelp('Affiché aux clients à la place des créneaux (ex. « Congés »).');
    }
}
