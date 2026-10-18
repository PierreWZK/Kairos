<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Qui peut afficher Kairos dans une iframe.
 *
 * Les pages /integration/… sont faites pour être intégrées sur le site des entreprises : n'importe quel site peut
 * les afficher. Toutes les autres (connexion, espace entreprise, super-admin…) refusent d'être encadrées
 * par un autre site, contre le clickjacking.
 */
#[AsEventListener]
final class FramingListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        if ($headers->has('Content-Security-Policy')) {
            return;
        }

        if ($event->getRequest()->attributes->getBoolean('_embed')) {
            $headers->set('Content-Security-Policy', 'frame-ancestors *');

            return;
        }

        $headers->set('Content-Security-Policy', "frame-ancestors 'self'");
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
    }
}
