<?php

namespace App\Upload;

/**
 * Nettoie un SVG envoyé comme logo : un SVG est un document qui peut contenir du script.
 *
 * Liste blanche stricte : seuls des éléments de dessin et des attributs de présentation sont gardés.
 * Disparaissent notamment <script>, <foreignObject>, <use>, <image>, les liens et tout attribut on*.
 */
final class SvgSanitizer
{
    private const array ELEMENTS = [
        'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon', 'text', 'tspan',
        'defs', 'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask', 'title', 'desc',
    ];

    private const array ATTRIBUTES = [
        'id', 'class', 'viewbox', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
        'd', 'points', 'transform', 'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-linecap',
        'stroke-linejoin', 'stroke-opacity', 'stroke-dasharray', 'stroke-miterlimit', 'opacity', 'clip-path',
        'clip-rule', 'mask', 'offset', 'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform',
        'font-family', 'font-size', 'font-weight', 'text-anchor', 'dominant-baseline', 'letter-spacing',
        'preserveaspectratio', 'xmlns', 'version',
    ];

    /** @return string|null le SVG nettoyé, ou null si ce n'est pas un SVG lisible */
    public function sanitize(string $content): ?string
    {
        // Pas de DTD : ni entités externes, ni « billion laughs »
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $content)) {
            return null;
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($content, \LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || 'svg' !== $document->documentElement?->localName) {
            return null;
        }

        $this->clean($document->documentElement);

        return $document->saveXML($document->documentElement) ?: null;
    }

    private function clean(\DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = $attribute->nodeValue ?? '';
            // url(...) seulement vers un élément du document (#id), jamais vers l'extérieur
            $externalUrl = preg_match('/url\s*\(\s*[\'"]?\s*(?!#)/i', $value);
            if (!\in_array($name, self::ATTRIBUTES, true) || $externalUrl || str_contains(strtolower($value), 'javascript:')) {
                $element->removeAttributeNode($attribute);
            }
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                if (\in_array(strtolower($child->localName), self::ELEMENTS, true)) {
                    $this->clean($child);
                } else {
                    $element->removeChild($child);
                }
            } elseif (!$child instanceof \DOMText) {
                // Commentaires, instructions de traitement, CDATA (feuilles de style) : retirés
                $element->removeChild($child);
            }
        }
    }
}
