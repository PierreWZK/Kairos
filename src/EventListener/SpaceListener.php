<?php

namespace App\EventListener;

use App\Controller\Space\AbstractSpaceController;
use App\Controller\Space\DashboardController;
use App\Entity\User;
use App\Twig\DisplayTimezone;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Avant chaque page de l'espace entreprise :
 * - les dates s'affichent dans le fuseau de l'entreprise ;
 * - tant que l'adresse e-mail n'est pas confirmée, seule la page d'accueil est ouverte
 *   (pas de page de réservation publiée par un compte dont l'adresse n'est pas prouvée).
 */
#[AsEventListener]
final class SpaceListener
{
    public function __construct(
        private readonly Security $security,
        private readonly DisplayTimezone $displayTimezone,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        $controller = $event->getController();
        $controller = \is_array($controller) ? $controller[0] : $controller;
        if (!$controller instanceof AbstractSpaceController) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || null === $user->getCompany()) {
            return; // le contrôleur refusera l'accès
        }

        $this->displayTimezone->useCompany($user->getCompany());

        if (!$user->isVerified() && !$controller instanceof DashboardController) {
            $session = $event->getRequest()->getSession();
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('warning', 'Confirmez d\'abord votre adresse e-mail pour configurer votre entreprise.');
            }
            $url = $this->urlGenerator->generate('app_space');
            $event->setController(static fn (): RedirectResponse => new RedirectResponse($url));
        }
    }
}
