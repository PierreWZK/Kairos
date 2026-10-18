/*
 * Petits comportements des formulaires, branchés par attributs data-* :
 * - [data-slug-source] / [data-slug-target] / [data-slug-preview] : propose un lien à partir d'un nom ;
 * - [data-collection] : ajoute et retire des lignes d'une collection Symfony (prototype) ;
 * - [data-copy] : copie le texte d'un élément dans le presse-papiers.
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

slugFields();
collections();
copyButtons();
