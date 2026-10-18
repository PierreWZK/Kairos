/*
 * Petits comportements des formulaires, branchés par attributs data-* :
 * - [data-slug-source] / [data-slug-target] / [data-slug-preview] : propose un lien à partir d'un nom ;
 * - [data-collection] : ajoute et retire des lignes d'une collection Symfony (prototype) ;
 * - [data-copy] : copie le texte d'un élément dans le presse-papiers ;
 * - chaque champ mot de passe reçoit un bouton pour afficher ce qui est tapé.
 */

const slugify = (text) => text
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 60);

function slugFields() {
    const source = document.querySelector('[data-slug-source]');
    const target = document.querySelector('[data-slug-target]');
    if (!source || !target) {
        return;
    }
    const preview = document.querySelector('[data-slug-preview]');
    // Un lien déjà rempli (modification, ou erreur de saisie) n'est plus réécrit
    let touched = target.value !== '';
    const show = () => {
        if (preview) {
            preview.textContent = target.value || preview.dataset.slugPreview || '';
        }
    };

    source.addEventListener('input', () => {
        if (!touched) {
            target.value = slugify(source.value);
            show();
        }
    });
    target.addEventListener('input', () => {
        touched = target.value !== '';
        show();
    });
}

function collections() {
    document.querySelectorAll('[data-collection]').forEach((collection) => {
        const list = collection.querySelector('[data-collection-list]');
        let index = list.children.length;

        collection.addEventListener('click', (event) => {
            const remove = event.target.closest('[data-collection-remove]');
            if (remove) {
                remove.closest('[data-collection-item]').remove();
                return;
            }
            if (event.target.closest('[data-collection-add]')) {
                const html = collection.dataset.prototype.replace(/__name__/g, String(index++));
                list.insertAdjacentHTML('beforeend', html);
                list.lastElementChild.querySelector('select, input')?.focus();
            }
        });
    });
}

function copyButtons() {
    document.querySelectorAll('[data-copy]').forEach((button) => {
        button.addEventListener('click', async () => {
            const source = document.getElementById(button.dataset.copy);
            try {
                await navigator.clipboard.writeText(source.textContent.trim());
                const label = button.textContent;
                button.textContent = 'Copié ✓';
                setTimeout(() => { button.textContent = label; }, 2000);
            } catch {
                // Presse-papiers refusé : on sélectionne le texte, à copier au clavier
                window.getSelection().selectAllChildren(source);
            }
        });
    });
}

const EYE = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
const EYE_OFF = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.6 5.1A10.7 10.7 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-2.4 3.4M6.6 6.6C3.8 8.4 2 12 2 12s3.5 7 10 7c1.9 0 3.6-.6 5-1.4"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/><path d="m2 2 20 20"/></svg>';

/** Un bouton « œil » dans chaque champ mot de passe ; sans JavaScript, le champ reste masqué comme d'habitude */
function passwordToggles() {
    document.querySelectorAll('input[type="password"]').forEach((input) => {
        const wrapper = document.createElement('span');
        wrapper.className = 'password-field';
        input.before(wrapper);
        wrapper.append(input);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'password-toggle';
        button.disabled = input.disabled;
        const render = (visible) => {
            button.innerHTML = visible ? EYE_OFF : EYE;
            button.setAttribute('aria-pressed', String(visible));
            button.setAttribute('aria-label', visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
            button.title = button.getAttribute('aria-label');
        };
        render(false);
        wrapper.append(button);

        button.addEventListener('click', () => {
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            render(visible);
            input.focus();
        });
        // Remasqué à l'envoi : le navigateur ne le mémorise pas comme un simple texte
        input.form?.addEventListener('submit', () => {
            input.type = 'password';
            render(false);
        });
    });
}

slugFields();
collections();
copyButtons();
passwordToggles();
