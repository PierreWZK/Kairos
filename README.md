# Kairos · Réserver le bon moment

> Jour 05 du défi **30 jours, 30 projets** · Symfony 8, Twig, Doctrine, EasyAdmin 5, MySQL

Kairos est une application de **réservation de créneaux en ligne**. Un professionnel (coach, coiffeur, professeur particulier, consultant…) publie son agenda. Ses clients choisissent une prestation, prennent un créneau libre et réservent **sans créer de compte**. Le professionnel gère tout depuis un **back-office EasyAdmin**.

Le nom vient du grec ancien *kairos*, « le moment opportun », par opposition à *chronos*, le temps qui s'écoule.

🔗 **Code :** [github.com/PierreWZK/Kairos](https://github.com/PierreWZK/Kairos)

## Pages

| Adresse | Contenu |
| --- | --- |
| `/` | Vitrine : présentation, fonctionnement, règles |
| `/reserver` | Étape 1 : choix de la prestation |
| `/reserver/{id}?semaine=…` | Étape 2 : agenda de la semaine, créneaux libres, pris ou indisponibles |
| `/reserver/{id}/creneau?debut=…` | Étape 3 : coordonnées du client |
| `/rendez-vous/{jeton}` | Page privée du rendez-vous : récapitulatif et annulation |
| `/connexion` | Connexion du professionnel |
| `/admin` | Back-office |

## Fonctionnalités

**👤 Côté client**

- Agenda à la semaine, navigable dans la période réservable.
- Seuls les créneaux où la prestation **tient en entier** sont proposés (une séance de 1 h 30 ne commence pas à 11 h si on ferme à midi).
- Réservation avec un nom et un e-mail, sans inscription.
- E-mail de confirmation avec une **invitation `.ics`** pour l'ajouter à son agenda, et un lien vers la page privée du rendez-vous.
- Annulation en ligne **jusqu'à 2 h avant**, avec e-mail de confirmation.

**🛠️ Côté professionnel** (`/admin`)

- **Tableau de bord** : les rendez-vous du jour et les suivants.
- **Réservations** : recherche, filtres (date, prestation, statut), fiche détaillée, correction des coordonnées, **annulation à tout moment** (le client est prévenu par e-mail).
- **Fermetures** (congés, formations…), **prestations** (durée, prix, activation) et **horaires d'ouverture** (plusieurs plages par jour).
- Un e-mail à chaque nouvelle réservation ou annulation ; « Répondre » écrit directement au client.

**📏 Règles**

- **Pas de double réservation** : l'enregistrement revérifie le créneau sous un verrou (`symfony/lock`) posé sur tout l'agenda, ce qui couvre aussi deux rendez-vous de durées différentes qui se chevauchent.
- Pas de créneau dans le passé, à moins de 2 h, ni à plus de 4 semaines.
- Aucun créneau hors des horaires d'ouverture ou pendant une fermeture.
- Une annulation libère aussitôt le créneau.
- Une prestation qui a des rendez-vous ne se supprime pas : on la désactive.

Les réglages sont dans `config/services.yaml` :

| Paramètre | Valeur | Rôle |
| --- | --- | --- |
| `app.timezone` | `Europe/Paris` | Fuseau de l'agenda |
| `app.booking.slot_step_minutes` | `30` | Un créneau commence toutes les N minutes |
| `app.booking.min_notice_hours` | `2` | Délai minimum pour réserver |
| `app.booking.horizon_weeks` | `4` | Jusqu'où l'agenda est ouvert |
| `app.booking.cancel_notice_hours` | `2` | Délai minimum pour annuler en ligne |

## Sécurité

- **Page de rendez-vous privée** : son adresse contient un jeton aléatoire de 64 caractères ; elle n'est pas indexée (`noindex`) et ne transmet pas son adresse aux autres sites (`no-referrer`).
- **CSRF** sur tous les formulaires (le CSRF « stateless » de Symfony 8 vérifie l'origine de la requête).
- **Back-office** réservé au rôle `ROLE_ADMIN`, connexion limitée à 5 essais par minute.
- Identifiants et secrets hors du dépôt : `.env` ne contient que des valeurs d'exemple, les vraies vont dans `.env.local`.

## Lancer en local

Prérequis : PHP 8.4 ou plus (avec `intl`), Composer, MySQL 8, et la [CLI Symfony](https://symfony.com/download) (conseillée).

```bash
composer install

# Identifiants MySQL réels dans .env.local (jamais versionné)
echo 'DATABASE_URL="mysql://kairos:motdepasse@127.0.0.1:3306/kairos?serverVersion=8.4.0&charset=utf8mb4"' > .env.local

php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate
php bin/console doctrine:fixtures:load    # données de démo + compte admin@kairos.test / kairos-demo

symfony serve -d                          # http://127.0.0.1:8000
```

Sans la CLI Symfony : `php -S 127.0.0.1:8000 -t public`.

- Back-office : http://127.0.0.1:8000/admin, compte de démo `admin@kairos.test` / `kairos-demo`.
- E-mails : en dev, rien ne part (`MAILER_DSN=null://null`) ; ils sont visibles dans le profiler (`/_profiler`, onglet **E-mails**).
- Les fixtures calculent leurs dates à partir du jour : l'agenda de démo n'est jamais vide.

## Tests

46 tests PHPUnit, avec l'heure figée au lundi 19 octobre 2026 à 08:00 :

- **Unitaires** : le calcul des créneaux (grille, chevauchements, délai, horizon, fermetures), le formatage des prix et durées, l'invitation `.ics`.
- **Intégration** : la réservation et l'annulation avec la vraie base (verrou, chevauchements, délai d'annulation).
- **Fonctionnels** : le parcours client complet (réservation, e-mails, CSRF, créneau déjà pris, annulation) et le back-office (accès, pages, annulation par le pro, suppression protégée).

Ils utilisent la base `kairos_test` (même `DATABASE_URL`, suffixe `_test` ajouté par Doctrine). Chaque test est annulé à la fin par [DAMA DoctrineTestBundle](https://github.com/dmaicher/doctrine-test-bundle) : la base reste vide.

```bash
cp .env.local .env.test.local             # les identifiants MySQL, pour l'environnement de test
php bin/console doctrine:database:create --env=test --if-not-exists
php bin/console doctrine:migrations:migrate --env=test --no-interaction
php bin/phpunit
```

## Mise en ligne

Variables d'environnement à définir en production :

| Variable | Exemple |
| --- | --- |
| `APP_ENV` | `prod` |
| `APP_SECRET` | une chaîne aléatoire |
| `DATABASE_URL` | `mysql://…` |
| `MAILER_DSN` | `smtp://utilisateur:motdepasse@smtp.exemple.fr:587` |
| `MAILER_FROM` | `Kairos <no-reply@exemple.fr>` |
| `OWNER_EMAIL` | l'adresse du professionnel |
| `DEFAULT_URI` | `https://kairos.exemple.fr` (liens des e-mails envoyés hors requête) |

```bash
composer install --no-dev --optimize-autoloader
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console asset-map:compile
php bin/console app:create-admin vous@exemple.fr   # mot de passe demandé de façon masquée
```

Le verrou anti-double réservation utilise des fichiers (`LOCK_DSN=flock`) : c'est suffisant sur un seul serveur. Avec plusieurs serveurs, il faudrait un verrou partagé (Redis, base de données).

## Structure

```
src/
├── Command/CreateAdminCommand.php     app:create-admin
├── Controller/
│   ├── HomeController.php             Vitrine
│   ├── BookingController.php          Prestations, agenda, réservation, page privée, annulation
│   ├── SecurityController.php         Connexion / déconnexion
│   └── Admin/                         Back-office EasyAdmin (tableau de bord + 4 CRUD)
├── Entity/                            Service, OpeningHour, TimeOff, Booking, User
├── Enum/BookingStatus.php
├── Form/BookingType.php               Coordonnées du client
├── Notification/
│   ├── BookingMailer.php              E-mails client et professionnel
│   └── CalendarInvite.php             Invitation .ics
├── Scheduling/
│   ├── SlotFinder.php                 Calcul des créneaux d'une semaine
│   ├── BookingManager.php             Réservation sous verrou, annulation
│   └── Slot, DaySchedule, SlotStatus  Objets de l'agenda
├── Twig/FormatExtension.php           Filtres |duration et |price
└── DataFixtures/AppFixtures.php       Données de démo
templates/
├── home/, booking/, admin/, emails/
└── _header, _footer, _flashes
tests/
├── Unit/, Integration/, Functional/
└── AgendaTestTrait.php                Données de test et heure figée
```
