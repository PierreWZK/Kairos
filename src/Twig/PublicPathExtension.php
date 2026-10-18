<?php

namespace App\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Le parcours public existe en deux versions : la page Kairos (/salon-lumiere/julie) et la version intégrée
 * dans le site d'une entreprise (/integration/salon-lumiere/julie). Chaque route publique a sa jumelle « _embed » ;
 * les liens d'une page restent dans la version où se trouve le visiteur.
 */
final class PublicPathExtension
{
    public const string EMBED_SUFFIX = '_embed';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /** La page est-elle affichée dans l'iframe d'un site externe ? */
    #[AsTwigFunction('is_embed')]
    public function isEmbed(): bool
    {
        return $this->requestStack->getCurrentRequest()?->attributes->getBoolean('_embed') ?? false;
    }

    /** @param array<string, mixed> $parameters */
    #[AsTwigFunction('public_path')]
    public function path(string $route, array $parameters = []): string
    {
        return $this->urlGenerator->generate($this->route($route), $parameters);
    }

    /** Le nom de la route dans la version courante : « app_calendar » ou « app_calendar_embed » */
    public function route(string $route): string
    {
        return $this->isEmbed() ? $route.self::EMBED_SUFFIX : $route;
    }
}
