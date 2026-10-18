<?php

namespace App\Controller\Admin;

use App\Entity\Service;
use App\Repository\BookingRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * @extends AbstractCrudController<Service>
 */
final class ServiceCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly BookingRepository $bookings,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Service::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Prestation')
            ->setEntityLabelInPlural('Prestations')
            ->setPageTitle(Crud::PAGE_INDEX, 'Prestations')
            ->setDefaultSort(['durationMinutes' => 'ASC'])
            ->setSearchFields(['name', 'description']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom');
        yield IntegerField::new('durationMinutes', 'Durée (minutes)')
            ->setHelp('Un créneau n\'est proposé que si la prestation y tient en entier.');
        yield MoneyField::new('priceCents', 'Prix')
            ->setCurrency('EUR')
            ->setStoredAsCents()
            ->setHelp('0 pour une prestation gratuite.');
        yield TextareaField::new('description', 'Description')->hideOnIndex();
        yield BooleanField::new('isActive', 'Proposée aux clients')->renderAsSwitch();
    }

    /**
     * Une prestation qui a des rendez-vous ne peut pas être supprimée (la base l'interdit) : on l'explique
     * et on propose de la désactiver, plutôt que de laisser la contrainte de clé étrangère lever une erreur.
     */
    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $count = $this->bookings->countForService($entityInstance);
        if ($count > 0) {
            $this->addFlash('danger', sprintf(
                '« %s » a %d rendez-vous et ne peut pas être supprimée. Désactivez-la pour ne plus la proposer aux clients.',
                $entityInstance->getName(),
                $count,
            ));

            return;
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }
}
