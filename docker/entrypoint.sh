#!/bin/sh
# ---------------------------------------------------------------------------
# Démarrage du conteneur Kairos : prépare l'appli puis lance le serveur web (CMD du Dockerfile).
# Une commande passée au conteneur (ex. « php bin/console app:demo:reset ») est exécutée telle quelle.
# ---------------------------------------------------------------------------
set -e

if [ "$1" = 'frankenphp' ]; then
    # Les volumes arrivent vides au premier démarrage
    mkdir -p public/uploads/logos var/share

    # MySQL peut démarrer après l'appli : on l'attend jusqu'à une minute
    tries=30
    until php bin/console dbal:run-sql -q 'SELECT 1' > /dev/null 2>&1; do
        tries=$((tries - 1))
        if [ "$tries" -le 0 ]; then
            echo 'MySQL ne répond pas : vérifiez DATABASE_URL.' >&2
            php bin/console dbal:run-sql 'SELECT 1'
            exit 1
        fi
        echo 'En attente de MySQL…'
        sleep 2
    done

    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    # Le cache est préparé au build ; on le complète avec les vraies variables d'environnement
    php bin/console cache:warmup
fi

exec "$@"
