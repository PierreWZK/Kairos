/*
 * Kairos : intègre un calendrier de réservation dans n'importe quel site.
 *
 *   <div data-kairos="https://…/integration/salon-lumiere"><a href="https://…/salon-lumiere">Prendre rendez-vous</a></div>
 *   <script src="https://…/embed.js" async></script>
 *
 * Chaque élément [data-kairos] est remplacé par une iframe vers cette adresse. La page intégrée envoie sa hauteur
 * (postMessage) : l'iframe s'ajuste, sans barre de défilement. Sans JavaScript, le lien de secours reste affiché.
 */
(function () {
    'use strict';

    var base = document.currentScript ? document.currentScript.src : window.location.href;

    function mount(element) {
        if (element.hasAttribute('data-kairos-ready')) {
            return;
        }

        var url;
        try {
            url = new URL(element.getAttribute('data-kairos'), base);
        } catch (error) {
            return;
        }
        if (url.protocol !== 'https:' && url.protocol !== 'http:') {
            return;
        }

        var iframe = document.createElement('iframe');
        iframe.src = url.href;
        iframe.title = element.getAttribute('data-kairos-title') || 'Réservation en ligne';
        iframe.style.cssText = 'display:block;width:100%;height:640px;border:0;border-radius:14px;color-scheme:light;';

        element.setAttribute('data-kairos-ready', '');
        element.replaceChildren(iframe);

        var loads = 0;
        window.addEventListener('message', function (event) {
            // Seuls les messages de cette iframe, venant de Kairos, sont pris en compte
            if (event.source !== iframe.contentWindow || event.origin !== url.origin) {
                return;
            }
            var data = event.data || {};
            if (typeof data.height === 'number' && data.height > 0) {
                iframe.style.height = data.height + 'px';
            }
            // Nouvelle page dans l'iframe (semaine suivante, formulaire…) : on ramène son haut à l'écran
            if (data.kairos === 'load' && ++loads > 1 && iframe.getBoundingClientRect().top < 0) {
                iframe.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }

    function mountAll() {
        document.querySelectorAll('[data-kairos]').forEach(mount);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mountAll);
    } else {
        mountAll();
    }
})();
