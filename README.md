# Kairos · Réserver le bon moment

> Jour 05 du défi **30 jours, 30 projets** · Symfony 8, Twig, EasyAdmin

Kairos est une application de **réservation de créneaux en ligne**. Un professionnel (coach, coiffeur, professeur particulier, consultant…) publie son agenda. Ses clients choisissent une prestation, prennent un créneau libre et réservent **sans créer de compte**. Le professionnel gère tout depuis un **back-office EasyAdmin**.

Le nom vient du grec ancien *kairos*, « le moment opportun », par opposition à *chronos*, le temps qui s'écoule.

🚧 **En cours :** pour l'instant, seule la page vitrine existe.

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
- [ ] Base de données et entités (prestations, horaires, réservations)
- [ ] Agenda et réservation côté client
- [ ] Back-office EasyAdmin
- [ ] E-mails et annulation
- [ ] Mise en ligne

## Prérequis

- PHP 8.4 ou plus, avec l'extension `intl`
- Composer
- [Symfony CLI](https://symfony.com/download) (conseillée)

## Lancer en local

```bash
composer install
symfony serve -d          # http://127.0.0.1:8000
```

Sans la CLI Symfony : `php -S 127.0.0.1:8000 -t public`.

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
