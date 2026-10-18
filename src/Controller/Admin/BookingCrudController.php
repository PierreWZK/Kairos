<?php

namespace App\Controller\Admin;

use App\Entity\Booking;
use App\Enum\BookingStatus;
use App\Notification\BookingMailer;
use App\Twig\CompanyTime;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Toutes les réservations de la plateforme (super-admin). Elles ne se créent que côté client et ne se suppriment
 * jamais (on garde l'historique) : on consulte, on corrige les coordonnées, on annule.
 *
 * @extends AbstractCrudController<Booking>
 */
final class BookingCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Booking::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Réservation')
            ->setEntityLabelInPlural('Réservations')
            ->setPageTitle(Crud::PAGE_INDEX, 'Réservations')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn (Booking $booking): string => (string) $booking)
            ->setDefaultSort(['startsAt' => 'DESC'])
            ->setSearchFields(['customerName', 'customerEmail', 'customerPhone', 'calendar.name', 'calendar.company.name'])
            ->setDefaultRowAction(Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset('Rendez-vous')->hideOnForm();
        // À l'heure locale de l'entreprise (CompanyTime), pas à celle de Paris
        yield DateTimeField::new('startsAt', 'Début')
            ->formatValue(static fn ($value, Booking $booking): string => CompanyTime::format($value, $booking->getCalendar()))
            ->hideOnForm();
        yield DateTimeField::new('endsAt', 'Fin')
            ->formatValue(static fn ($value, Booking $booking): string => CompanyTime::format($value, $booking->getCalendar(), 'HH:mm'))
            ->onlyOnDetail();
        yield AssociationField::new('calendar', 'Entreprise · calendrier')
            ->formatValue(static fn ($value, Booking $booking): string => CompanyTime::calendarLabel($booking->getCalendar()))
            ->hideOnForm();
        yield AssociationField::new('service', 'Prestation')->hideOnForm();
        // Choix déduits de l'enum (mapping Doctrine) ; libellés fournis par BookingStatus::trans()
        yield ChoiceField::new('status', 'Statut')
            // Avec un enum traduisible, EasyAdmin indexe les badges par le nom du cas
            ->renderAsBadges([
                BookingStatus::Pending->name => 'warning',
                BookingStatus::Confirmed->name => 'success',
                BookingStatus::Rejected->name => 'danger',
                BookingStatus::Cancelled->name => 'secondary',
            ])
            ->hideOnForm();

        yield FormField::addFieldset('Client');
        yield TextField::new('customerName', 'Nom');
        yield EmailField::new('customerEmail', 'E-mail');
        yield TelephoneField::new('customerPhone', 'Téléphone')->hideOnIndex();
        yield TextareaField::new('note', 'Note du client')->hideOnIndex();

        yield FormField::addFieldset('Historique')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Réservé le')->setFormat('d MMM y, HH:mm')->onlyOnDetail();
        yield DateTimeField::new('cancelledAt', 'Annulé le')->setFormat('d MMM y, HH:mm')->onlyOnDetail();
    }

    public function configureActions(Actions $actions): Actions
    {
        $cancel = Action::new('cancelBooking', 'Annuler', 'fa fa-ban')
            ->linkToRoute('admin_booking_cancelBooking', fn (Booking $booking): array => [
                'id' => $booking->getId(),
                'token' => $this->csrfTokenManager->getToken('admin-cancel'.$booking->getId())->getValue(),
            ])
            ->renderAsForm()
            ->asDangerAction()
            ->askConfirmation('Annuler ce rendez-vous ? Le client sera prévenu par e-mail et le créneau sera libéré.', 'Oui, annuler')
            // En attente ou confirmé : un rendez-vous refusé ou annulé n'occupe déjà plus son créneau
            ->displayIf(static fn (Booking $booking): bool => $booking->getStatus()->blocksSlot());

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $cancel)
            ->add(Crud::PAGE_DETAIL, $cancel)
            ->disable(Action::NEW, Action::DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(DateTimeFilter::new('startsAt', 'Date'))
            ->add(EntityFilter::new('calendar', 'Calendrier')->setFormTypeOption('value_type_options.choice_label', CompanyTime::calendarLabel(...)))
            ->add(EntityFilter::new('service', 'Prestation'))
            ->add(ChoiceFilter::new('status', 'Statut')->setChoices(array_combine(
                array_map(static fn (BookingStatus $status): string => $status->label(), BookingStatus::cases()),
                array_map(static fn (BookingStatus $status): string => $status->value, BookingStatus::cases()),
            )));
    }

    #[AdminRoute('/{id}/cancel', name: 'cancelBooking', options: ['methods' => ['POST']])]
    public function cancelBooking(Booking $booking, Request $request, EntityManagerInterface $entityManager, BookingMailer $mailer): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');
        if (!$this->isCsrfTokenValid('admin-cancel'.$booking->getId(), $request->query->getString('token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        // Le super-admin peut annuler à tout moment, sans le délai imposé aux clients
        if ($booking->getStatus()->blocksSlot()) {
            $booking->cancel();
            $entityManager->flush();
            $mailer->sendCancellation($booking, byCompany: true);
            $this->addFlash('success', sprintf('Le rendez-vous de %s est annulé et le client a été prévenu par e-mail.', $booking->getCustomerName()));
        }

        return $this->redirectToRoute('admin_booking_detail', ['entityId' => $booking->getId()]);
    }
}
