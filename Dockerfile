# syntax=docker/dockerfile:1
# ---------------------------------------------------------------------------
# Kairos en production : FrankenPHP (Caddy + PHP 8.4) derrière le proxy de Coolify.
#
# Au démarrage (docker/entrypoint.sh) : attente de MySQL, migrations, puis serveur web sur le port 80.
# Les réglages viennent des variables d'environnement de Coolify (voir README, « Mise en ligne »).
# Deux dossiers sont à conserver dans des volumes :
#   /app/public/uploads   logos des entreprises
#   /app/var/share        sessions, cache de l'appli, compteurs des limites
# ---------------------------------------------------------------------------
# Version exacte de PHP : elle garantit que l'image corrigée est bien utilisée (PHP2Shell, corrigé en 8.4.27).
# À chaque correctif de sécurité de PHP 8.4, monter ce numéro.
FROM dunglas/frankenphp:1-php8.4.27

WORKDIR /app

# Extensions PHP : MySQL, dates et formats en français, cache d'opcodes
RUN install-php-extensions pdo_mysql intl opcache zip \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-kairos.ini"
COPY docker/Caddyfile /etc/caddy/Caddyfile

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1 \
    TRUSTED_PROXIES=private_ranges

# Dépendances d'abord : cette couche reste en cache tant que composer.lock ne change pas
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

COPY . .

# APP_SECRET factice le temps du build : le vrai n'est lu qu'à l'exécution, jamais écrit dans l'image
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && APP_SECRET=build composer run-script --no-dev post-install-cmd \
    && APP_SECRET=build php bin/console asset-map:compile \
    && mkdir -p public/uploads/logos var/share \
    && chmod +x docker/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
