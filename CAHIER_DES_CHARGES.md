# Jour 5/30 — Kairos, réserver le bon moment

> Stack : **Symfony 8**, Twig, Doctrine, **EasyAdmin 5**, **MySQL 8.4**, AssetMapper · hébergement **Coolify** (image Docker FrankenPHP), sous-domaine `kairos.projectp.fr`.
> Rédigé après la livraison, à partir du code : ce document décrit ce qui a été construit et sert de référence pour les évolutions.

## 1. L'idée

Une plateforme de **réservation de créneaux** pour les entreprises de rendez-vous (salons, cabinets, coachs…). L'entreprise crée ses calendriers ; ses clients réservent **sans créer de compte**, sur la page Kairos de l'entreprise ou **directement sur son site**, grâce à un code d'intégration.

## 2. MVP — ce qui doit fonctionner

1. Une **vitrine** publique et des **comptes d'entreprise** (inscription, confirmation de l'adresse, mot de passe oublié).
2. Un **espace entreprise** : calendriers, horaires, fermetures, prestations, rendez-vous, équipe, fiche de l'entreprise.
3. Le **parcours client** : calendrier → prestation → créneau → coordonnées → confirmation par e-mail.
4. **Aucune double réservation**, même avec deux clients au même instant.
5. Un **code d'intégration** pour afficher le calendrier dans un autre site.
6. Un **super-admin** (EasyAdmin) qui voit toute la plateforme.
7. Une **démo publique** utilisable sans inscription.

### Périmètre volontairement limité

- Pas de paiement en ligne ni de rappel SMS.
- Pas de compte client : un rendez-vous se gère par un lien privé reçu par e-mail.

## 3. Cahier des charges fonctionnel

### A. Côté client

- Agenda de la semaine **à l'heure de l'entreprise**, navigable dans la période réservable.
- Seuls les créneaux où la prestation **tient en entier** sont proposés.
- Réservation avec un nom et un e-mail ; téléphone et note selon le calendrier.
- E-mail de confirmation avec une **invitation `.ics`** et le lien de la page privée du rendez-vous.
- Calendrier en **validation manuelle** : le client envoie une demande et reçoit la réponse par e-mail.
- Annulation en ligne jusqu'au délai fixé par le calendrier.

### B. Côté entreprise (`/espace`)

- **Rendez-vous** : liste filtrable, fiche, accepter, refuser, annuler (le client est prévenu).
- **Calendriers** : horaires (plusieurs plages par jour), fermetures, prestations proposées, règles (pas des créneaux, délai minimum, horizon, délai d'annulation, validation manuelle, champs du formulaire).
- **Prestations** : catalogue de l'entreprise (durée, prix).
- **Équipe** : le propriétaire invite des membres et leur attribue des calendriers ; un membre ne gère que les siens.
- **Fiche** : présentation, adresse, couleur de la page (contraste vérifié), logo.
- **Intégration** : code à coller, pour toute l'entreprise ou un seul calendrier.
- Un e-mail à chaque nouvelle réservation ou annulation.

### C. Côté super-admin (`/admin`)

- Tableau de bord, entreprises (suspension), comptes, calendriers, réservations, prestations, horaires, fermetures.

### D. Règles de réservation

- **Pas de double réservation** : l'enregistrement revérifie le créneau sous un verrou (`symfony/lock`) posé sur tout le calendrier ; une demande en attente bloque déjà son créneau.
- Pas de créneau passé, avant le délai minimum, au-delà de l'horizon, hors des horaires ou pendant une fermeture.
- Une annulation ou un refus libère le créneau.
- Un calendrier ou une prestation qui a des rendez-vous ne se supprime pas : on le ferme, on la désactive.

## 4. Cahier des charges technique

### Modèle de données

| Entité | Rôle |
| --- | --- |
| `Company` | L'entreprise : lien de sa page, fuseau horaire, couleur, logo, démo ou non |
| `User` | Compte d'entreprise : propriétaire ou membre (`CompanyRole`), calendriers attribués |
| `Calendar` | Une personne, une salle, un lieu : règles de réservation et prestations proposées |
| `OpeningHour`, `TimeOff` | Horaires d'ouverture et fermetures d'un calendrier |
| `Service` | Une prestation : durée, prix |
| `Booking` | Un rendez-vous : créneau, client, statut (`BookingStatus`), jeton privé |

### Architecture

- `src/Scheduling/` : le cœur métier, sans dépendance à l'affichage. `SlotFinder` calcule les créneaux d'une semaine, `BookingManager` enregistre et annule sous verrou.
- `src/Controller/` : pages publiques, comptes, espace (`Space/`), administration (`Admin/`).
- `src/Notification/` : e-mails (`BookingMailer`, `AccountMailer`) et invitation `.ics` (`CalendarInvite`).
- `src/Demo/` et la commande `app:demo:reset` : la démo publique, recréée chaque nuit.
- `public/embed.js` : remplace le lien d'intégration par une iframe qui prend la hauteur de son contenu.

### Sécurité

- Isolation des entreprises par un voter (`SpaceVoter`), vérifiée par un test qui parcourt toutes les routes de l'espace.
- Liens par e-mail signés (`UriSigner`) : vérification (48 h), mot de passe (1 h, usage unique), invitation (7 jours).
- Limites de débit sur la connexion, les e-mails et les invitations.
- Logos : type vérifié d'après le contenu, SVG nettoyé, nom de fichier aléatoire.
- CSRF sur tous les formulaires ; page de rendez-vous privée (`noindex`, adresse non transmise aux autres sites).

### Tests

PHPUnit, heure figée au lundi 19 octobre 2026 à 08:00 (Paris) : unitaires (créneaux, formats, `.ics`, SVG), intégration (réservation sous verrou), fonctionnels (parcours client, comptes, espace, isolation, admin). Chaque test est annulé à la fin (DAMA DoctrineTestBundle).

### Mise en ligne

À chaque push sur `main`, GitHub Actions lance les tests et construit l'image Docker ; si tout passe, un webhook demande à Coolify de redéployer. Au démarrage, le conteneur lance les migrations. L'aperçu du lien vient des balises Open Graph et de `public/og.png`.

## 5. Critères de réussite

- [x] Deux clients qui réservent le même créneau au même instant : un seul rendez-vous est créé.
- [x] Un membre ne voit aucune donnée d'un calendrier qui ne lui est pas attribué, ni d'une autre entreprise.
- [x] Le calendrier intégré dans un autre site permet de réserver et d'annuler.
- [x] Le client reçoit l'e-mail de confirmation avec l'invitation `.ics`.
- [x] La démo publique est remise à zéro chaque nuit sans toucher aux vraies entreprises.

## 6. Pistes d'évolution

- Rappel par e-mail la veille du rendez-vous.
- Paiement d'un acompte à la réservation.
- Synchronisation avec Google Agenda.

## 7. Définition du « terminé »

Les critères de la section 5 sont remplis, les tests passent dans la CI, le site est en ligne sur `kairos.projectp.fr` et le README décrit l'installation et la mise en ligne.
