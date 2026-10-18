# Kairos · Réserver le bon moment

> Jour 05 du défi **30 jours, 30 projets** · Symfony 8, Twig, EasyAdmin

Kairos est une application de **réservation de créneaux en ligne**. Un professionnel (coach, coiffeur, professeur particulier, consultant…) publie son agenda. Ses clients choisissent une prestation, prennent un créneau libre et réservent **sans créer de compte**. Le professionnel gère tout depuis un **back-office EasyAdmin**.

Le nom vient du grec ancien *kairos*, « le moment opportun », par opposition à *chronos*, le temps qui s'écoule.

🚧 **En cours :** l'application fonctionne de bout en bout ; restent les tests automatisés et la mise en ligne.

## Le projet

**👤 Côté client**

- Agenda à la semaine, avec les créneaux libres et réservés.
- Choix d'une prestation (durée, prix) : seuls les créneaux où elle tient en entier sont proposés.
- Réservation avec un nom et un e-mail, sans inscription.
- E-mail de confirmation avec un lien personnel pour annuler.

**🛠️ Côté professionnel** (EasyAdmin)

- Back-office protégé par connexion.
- Prestations : nom, durée, prix, activation.
- Horaires d'ouverture par jour de la semaine et périodes de fermeture.
- Liste des réservations avec filtres, annulation.

**📏 Règles**

- Pas de double réservation, même si deux personnes valident le même créneau au même instant.
- Pas de créneau dans le passé ni trop proche de l'heure actuelle.
- Aucun créneau hors des horaires d'ouverture ou pendant une fermeture.
- Une annulation libère le créneau pour les autres clients.

## Avancement

- [x] Installation de Symfony 8, page vitrine
- [x] Base de données et entités (prestations, horaires, réservations)
- [x] Agenda et réservation côté client
- [x] E-mails et annulation
- [x] Back-office EasyAdmin
- [ ] Tests automatisés
- [ ] Mise en ligne

## Prérequis

- PHP 8.4 ou plus, avec l'extension `intl`
- Composer
- MySQL 8
- [Symfony CLI](https://symfony.com/download) (conseillée)

## Lancer en local

```bash
composer install

# Identifiants MySQL réels dans .env.local (jamais versionné)
echo 'DATABASE_URL="mysql://kairos:motdepasse@127.0.0.1:3306/kairos?serverVersion=8.4.0&charset=utf8mb4"' > .env.local

php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate
php bin/console doctrine:fixtures:load    # données de démo + compte admin@kairos.test / kairos-demo

symfony serve -d          # http://127.0.0.1:8000
```

Sans la CLI Symfony : `php -S 127.0.0.1:8000 -t public`.

- Site : http://127.0.0.1:8000
- Back-office : http://127.0.0.1:8000/admin (compte de démo `admin@kairos.test` / `kairos-demo`)
- E-mails : en dev, rien ne part (`MAILER_DSN=null://null`) ; ils sont visibles dans le profiler, onglet **E-mails**.

En production, le compte du professionnel se crée avec `php bin/console app:create-admin vous@exemple.fr` (mot de passe demandé de façon masquée).

## Structure

```
src/Controller/HomeController.php   Page vitrine (route /)
templates/
├── base.html.twig                  Gabarit commun
└── home/index.html.twig            Page vitrine
assets/
├── app.js                          Point d'entrée AssetMapper
└── styles/app.css                  Styles
```
