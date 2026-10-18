# Kairos · Réserver le bon moment

> Jour 05 du défi **30 jours, 30 projets** · Symfony 8, Twig, Doctrine, EasyAdmin 5, MySQL

Kairos est une plateforme de **réservation de créneaux en ligne** pour les entreprises de rendez-vous : salons de coiffure, cabinets, coachs, professeurs particuliers… Une entreprise s'inscrit, crée ses calendriers (une personne, une salle, un lieu), et ses clients réservent **sans créer de compte**, sur la page Kairos de l'entreprise ou **directement sur son propre site**.

Le nom vient du grec ancien *kairos*, « le moment opportun », par opposition à *chronos*, le temps qui s'écoule.

🔗 **Code :** [github.com/PierreWZK/Kairos](https://github.com/PierreWZK/Kairos)

## Trois espaces

| Pour qui | Où | Quoi |
| --- | --- | --- |
| **Les clients** | `/{entreprise}`, `/{entreprise}/{calendrier}` | Choisir un calendrier, une prestation, un créneau ; réserver, annuler |
| **Les entreprises** | `/espace` | Rendez-vous, calendriers, prestations, équipe, fiche de l'entreprise |
| **Le super-admin** | `/admin` (EasyAdmin) | Toute la plateforme : entreprises, comptes, réservations ; suspendre une entreprise |

## Pages publiques

| Adresse | Contenu |
| --- | --- |
| `/` | Vitrine : fonctionnement, fonctionnalités, intégration, démos |
| `/{entreprise}` | Page de l'entreprise : ses calendriers ouverts |
| `/{entreprise}/{calendrier}` | Les prestations proposées par ce calendrier |
| `/{entreprise}/{calendrier}/{prestation}?semaine=…` | Agenda de la semaine : créneaux libres, pris ou indisponibles |
| `/{entreprise}/{calendrier}/{prestation}/creneau?debut=…` | Coordonnées du client |
| `/rendez-vous/{jeton}` | Page privée du rendez-vous : récapitulatif et annulation |
| `/integration/…` | Les mêmes pages, pour l'iframe d'un site externe |
| `/inscription`, `/connexion`, `/mot-de-passe-oublie` | Comptes des entreprises |
| `/demo/integration` | Un faux site de salon de coiffure qui intègre le calendrier |

## Fonctionnalités

**👤 Côté client**

- Agenda à la semaine, navigable dans la période réservable, **à l'heure de l'entreprise**.
- Seuls les créneaux où la prestation **tient en entier** sont proposés (une séance de 1 h 30 ne commence pas à 11 h si on ferme à midi).
- Réservation avec un nom et un e-mail, sans inscription ; téléphone et note selon le calendrier.
- E-mail de confirmation avec une **invitation `.ics`** et un lien vers la page privée du rendez-vous.
- Calendrier en **validation manuelle** : le client envoie une demande, puis reçoit la réponse par e-mail.
- Annulation en ligne jusqu'au délai fixé par le calendrier.

**🏢 Côté entreprise** (`/espace`)

- **Inscription libre** : nom, lien de la page, fuseau horaire ; l'adresse e-mail se confirme par un lien signé.
- **Rendez-vous** : liste filtrable (période, statut, calendrier), fiche détaillée, **accepter ou refuser** une demande, **annuler** à tout moment (le client est prévenu).
- **Calendriers** : chacun a ses horaires (plusieurs plages par jour), ses fermetures, ses prestations et ses règles : pas des créneaux, délai minimum, horizon, délai d'annulation, validation manuelle, champs du formulaire.
- **Prestations** : le catalogue de l'entreprise (durée, prix) ; chaque calendrier coche celles qu'il propose.
- **Équipe** : le propriétaire invite des membres par e-mail et leur attribue des calendriers ; un membre ne voit et ne gère que les siens.
- **Fiche de l'entreprise** : présentation, adresse, **couleur** de la page (contraste vérifié), **logo** (PNG, JPEG, WebP ou SVG nettoyé).
- **Intégration** : un code à coller dans son site, pour toute l'entreprise ou un seul calendrier.
- Un e-mail à chaque nouvelle réservation ou annulation, envoyé à ceux qui gèrent le calendrier ; « Répondre » écrit directement au client.

**🛡️ Côté super-admin** (`/admin`)

- Tableau de bord : chiffres de la plateforme, dernières inscriptions, prochains rendez-vous à l'heure locale de chaque entreprise.
- Entreprises (suspension, lien vers leur page), comptes, calendriers, réservations (statuts, annulation), prestations, horaires, fermetures.

**📏 Règles**

- **Pas de double réservation** : l'enregistrement revérifie le créneau sous un verrou (`symfony/lock`) posé sur tout le calendrier, ce qui couvre aussi deux rendez-vous de durées différentes qui se chevauchent. Une demande en attente bloque déjà son créneau.
- Pas de créneau dans le passé, avant le délai minimum, au-delà de l'horizon, hors des horaires ou pendant une fermeture.
- Une annulation ou un refus libère aussitôt le créneau.
- Un calendrier ou une prestation qui a des rendez-vous ne se supprime pas : on le ferme, on la désactive.

## Intégrer le calendrier sur un site

Dans l'espace, l'onglet **Intégration** d'un calendrier donne ce code :

```html
<div data-kairos="https://kairos.exemple.fr/integration/salon-lumiere/julie">
  <a href="https://kairos.exemple.fr/salon-lumiere/julie">Prendre rendez-vous en ligne</a>
</div>
<script src="https://kairos.exemple.fr/embed.js" async></script>
```

`embed.js` remplace le lien par une iframe qui prend la hauteur de son contenu. Sans JavaScript, le lien reste. Tout le parcours (agenda, réservation, annulation) se fait dans l'iframe, sans cookie ; seules les pages `/integration/…` acceptent d'être affichées dans un autre site.

## Sécurité

- **Isolation des entreprises** : chaque page de l'espace vérifie que l'objet appartient à l'entreprise (voter `SpaceVoter`). Un test parcourt toutes les routes de l'espace pour s'en assurer.
- **Rôles** : propriétaire (toute l'entreprise), membre (ses calendriers), super-admin (`/admin`).
- **Liens par e-mail signés** (`UriSigner`), sans rien stocker en base : vérification d'adresse (48 h), mot de passe oublié (1 h, usage unique), invitation (7 jours, usage unique).
- **Limites** : 5 essais de connexion par minute ; 3 liens de vérification ou de mot de passe par quart d'heure ; 20 invitations par heure et par entreprise.
- **Logos** : type reconnu d'après le contenu ; un SVG ne garde que des éléments de dessin (ni script, ni lien externe, ni entité XML) ; nom de fichier aléatoire.
- **CSRF** sur tous les formulaires (le CSRF « stateless » de Symfony 8 vérifie l'origine de la requête).
- **Page de rendez-vous privée** : jeton aléatoire de 64 caractères, `noindex`, adresse non transmise aux autres sites.
- Identifiants et secrets hors du dépôt : `.env` ne contient que des valeurs d'exemple, les vraies vont dans `.env.local`.

## Lancer en local

Prérequis : PHP 8.4 ou plus (avec `intl`), Composer, MySQL 8, et la [CLI Symfony](https://symfony.com/download) (conseillée).

```bash
composer install

# Identifiants MySQL réels dans .env.local (jamais versionné)
echo 'DATABASE_URL="mysql://kairos:motdepasse@127.0.0.1:3306/kairos?serverVersion=8.4.0&charset=utf8mb4"' > .env.local

php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate
php bin/console doctrine:fixtures:load    # deux entreprises de démo et leurs comptes

symfony serve -d                          # http://127.0.0.1:8000
```

Sans la CLI Symfony : `php -S 127.0.0.1:8000 -t public`.

Comptes de démo (mot de passe `kairos-demo`) :

| Compte | Rôle |
| --- | --- |
| `admin@kairos.test` | Super-admin (`/admin`) |
| `julie@salon-lumiere.test` | Propriétaire de Salon Lumière (`/salon-lumiere`) |
| `marc@salon-lumiere.test` | Membre de Salon Lumière, calendrier « Marc » seulement |
| `contact@cabinet-equilibre.test` | Propriétaire de Cabinet Équilibre (`/cabinet-equilibre`) |

- E-mails : en dev, rien ne part (`MAILER_DSN=null://null`) ; ils sont visibles dans le profiler (`/_profiler`, onglet **E-mails**).
- Les fixtures calculent leurs dates à partir du jour : l'agenda de démo n'est jamais vide.

## Tests

PHPUnit, avec l'heure figée au lundi 19 octobre 2026 à 08:00 (heure de Paris) :

- **Unitaires** : le calcul des créneaux, le formatage des prix et durées, l'invitation `.ics`, le nettoyage des SVG.
- **Intégration** : la réservation et l'annulation avec la vraie base (verrou, chevauchements, délais).
- **Fonctionnels** : le parcours client (page Kairos et intégration), les comptes (inscription, vérification, mot de passe), l'espace entreprise (calendriers, rendez-vous, équipe, réglages), l'isolation entre entreprises et le super-admin.

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
| `APP_SECRET` | une chaîne aléatoire (elle signe aussi les liens envoyés par e-mail) |
| `DATABASE_URL` | `mysql://…` |
| `MAILER_DSN` | `smtp://utilisateur:motdepasse@smtp.exemple.fr:587` |
| `MAILER_FROM` | `Kairos <no-reply@exemple.fr>` |
| `DEFAULT_URI` | `https://kairos.exemple.fr` (liens des e-mails envoyés hors requête) |

```bash
composer install --no-dev --optimize-autoloader
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console asset-map:compile
php bin/console app:create-admin vous@exemple.fr   # super-admin ; mot de passe demandé de façon masquée
```

- Le dossier `public/uploads/logos` doit être accessible en écriture par PHP, et conservé d'un déploiement à l'autre.
- Le verrou anti-double réservation utilise des fichiers (`LOCK_DSN=flock`) : c'est suffisant sur un seul serveur. Avec plusieurs serveurs, il faudrait un verrou partagé (Redis, base de données).

## Structure

```
src/
├── Command/CreateAdminCommand.php     app:create-admin
├── Controller/
│   ├── HomeController.php             Vitrine, démo d'intégration
│   ├── BookingController.php          Parcours client (page Kairos et version intégrée)
│   ├── RegistrationController.php     Inscription, vérification de l'adresse
│   ├── PasswordController.php         Mot de passe oublié
│   ├── InvitationController.php       Accepter une invitation dans une équipe
│   ├── SecurityController.php         Connexion / déconnexion
│   ├── Space/                         Espace entreprise : accueil, rendez-vous, calendriers,
│   │                                  prestations, équipe, entreprise, compte
│   └── Admin/                         Super-admin EasyAdmin
├── Entity/                            Company, User, Calendar, Service, OpeningHour, TimeOff, Booking
├── Enum/                              BookingStatus, CompanyRole
├── EventListener/                     Fuseau de l'entreprise dans l'espace, en-têtes d'intégration
├── Form/                              Réservation, inscription, mots de passe, Space/ (espace)
├── Notification/                      E-mails de réservation et de compte, invitation .ics
├── Scheduling/                        Calcul des créneaux, réservation sous verrou
├── Security/                          Liens signés, contrôle de connexion, SpaceVoter
├── Twig/                              Filtres de format, liens publics, heure locale
├── Upload/                            Logos : contrôle, nettoyage des SVG
└── DataFixtures/AppFixtures.php       Données de démo
templates/
├── home/, booking/, integration/, security/, space/, admin/, emails/
└── _header, _footer, _flashes
public/embed.js                        Script d'intégration sur un site externe
tests/
├── Unit/, Integration/, Functional/
└── AgendaTestTrait.php                Données de test et heure figée
```
