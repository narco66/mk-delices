# Accès à l'environnement de démonstration — MK Délices

**Pour :** l'équipe projet et MK Délices, pour découvrir l'application.
**Environnement :** poste de développement (Windows / Laragon). Comptes et données **fictifs** ; ils n'existent pas, et ne peuvent pas être créés, en production.

## 1. Démarrer l'application
```powershell
powershell -ExecutionPolicy Bypass -File scripts\start-dev.ps1
```
(ou : `php artisan serve`, `php artisan queue:work` dans `backend/`, et `npx vite` dans `frontend/`)

| Service | Adresse |
|---|---|
| Boutique | http://localhost:5173 |
| Back-office | http://localhost:5173/admin |
| Espace livreur (sur smartphone ou fenêtre étroite) | http://localhost:5173/livreur |
| Emails envoyés par l'application (Mailpit) | http://127.0.0.1:8025 |
| API (contrôle technique) | http://127.0.0.1:8000/api/v1/config |

Utiliser **`localhost`** (et non `127.0.0.1`) pour la boutique : c'est l'adresse configurée pour le retour de paiement.

**Application mobile :** la même API est accessible par jeton d'accès (`POST /api/v1/auth/token` avec email, mot de passe et nom de l'appareil, puis en-tête `Authorization: Bearer …`) ; les comptes ci-dessous fonctionnent. Guide d'intégration : [API-MOBILE.md](API-MOBILE.md).

## 2. Comptes

**Mot de passe commun à tous les comptes de démonstration : `Demo-MKD-2026`**

| Rôle | Email | Ce qu'on y voit |
|---|---|---|
| Administrateur | `admin@mk-delices.test` | Tout : utilisateurs, paramètres, journal d'audit, approbation des remboursements |
| Gestionnaire commercial | `commercial@mk-delices.test` | Commandes, brouillons WhatsApp, paiements, catalogue, promotions, avis, contenus, statistiques |
| Préparateur | `preparateur@mk-delices.test` | Tableau de préparation uniquement |
| Responsable des livraisons | `livraison@mk-delices.test` | Affectations, incidents, livreurs, zones, règlements |
| Livreur | `livreur1@mk-delices.test` | Ses missions (Livreur Démo A) |
| Livreur | `livreur2@mk-delices.test` | Ses missions (Livreur Démo B) |
| Client (compte) | `client1@mk-delices.test` | Historique de commandes, adresses, profil, consentement aux offres |
| Client (compte) | `client2@mk-delices.test`, `client3@mk-delices.test` | Idem |

Les **clients invités** (sans compte) suivent leur commande par le lien personnel reçu à la commande.

## 3. Paiement de test
Au moment de payer, choisir **« Paiement simulé (environnement de test) »** : un écran de prestataire fictif propose « paiement réussi » ou « échec ». Aucun débit réel. La confirmation passe par un webhook signé traité par la file d'attente : `php artisan queue:work` doit tourner.

## 4. Ce que contiennent les données de démonstration
Générées par `DemoActivitySeeder` à travers les vrais services de l'application (mêmes règles qu'en réel) :

| Module | Contenu |
|---|---|
| Catalogue | Les 8 produits validés (sans photos : illustrations en attendant la séance photo) |
| Commandes | 60 à 80 commandes sur 30 jours (hors lundis ; le nombre exact dépend des créneaux disponibles), dont des commandes du jour **dans chaque état** : à confirmer (payée et non payée), acceptée, en préparation, prête, confiée au livreur, en livraison, échec de livraison, ainsi que 2 brouillons WhatsApp |
| Paiements | Paiements confirmés par le prestataire simulé ; un code promo de démonstration (DEMO10) utilisé sur certaines commandes |
| Remboursements | Commandes refusées après paiement, remboursements approuvés par un second utilisateur (double contrôle) |
| Factures | Une facture par commande livrée (série `TEST-FA-…`, mention « sans valeur fiscale » hors production), PDF téléchargeable dans le back-office (Finances › Factures), le suivi de commande et l'espace client ; export CSV comptable |
| Livraisons | Missions livrées avec preuve, échecs « client absent » (course payée 50 %) et « produit abîmé » (non payée), relivraisons, règlements des livreurs (sauf la dernière semaine, laissée à régler) |
| Clients | ≈ 80 clients invités fictifs et 3 comptes clients avec adresse enregistrée |
| Avis | Avis approuvés (visibles sur les fiches produits) et avis en attente de modération |
| Tableaux de bord | Chiffre d'affaires, panier moyen, taux de livraison réussie, meilleures ventes… calculés sur ces données |
| Notifications | Notifications internes du back-office ; emails des clients avec compte visibles dans Mailpit |

| Géolocalisation | Livraison limitée à la **ville de Dakar** (périmètre desservi). Commandes dans les vrais quartiers des 4 zones : positions GPS (dont quelques-unes imprécises), repères placés sur la carte, adresses enregistrées, et un tiers saisies **sans position**. Positions **approximatives** (centre de quartier ± 300 m). Une commande du jour corrigée par le commercial (« Modifiée par l'équipe ») |
| Comptes clients | `client1` : Maison (position GPS, **par défaut**) et Bureau (sans position) ; `client2` : Maison (repère sur la carte) ; `client3` : Maison sans position |

**Renouveler le jeu** (les commandes « du jour » vieillissent) : `php artisan mk:demo-refresh`. La génération précédente est **archivée**, pas supprimée : ses commandes passent en « test » et disparaissent des statistiques, des écrans de préparation, de livraison et des livreurs, des créneaux et des avis publiés. Elles restent consultables dans la liste des commandes (« inclure les tests »), et leurs factures de test gardent leur numéro.

Avant de renouveler, sauvegarder la base :
- poste local : `pg_dump`, copie dans `backend/storage/app/backups/` (ignoré par Git) ;
- préproduction : `sudo /usr/local/sbin/mk-backup`.

**Identification des données fictives :** clients marqués « démo » (`is_demo`), téléphones en `+221 70 000 xx xx`, emails en `@mk-delices.test`, commandes annotées « Donnée de démonstration » dans les notes internes.

## 5. Parcours de découverte conseillé (≈ 20 minutes)
1. **Boutique** : parcourir le catalogue, ouvrir une fiche (allergènes, conservation), commander en invité avec le paiement simulé.
2. **Commercial** : voir la nouvelle commande dans « À confirmer », l'accepter ; ouvrir un brouillon WhatsApp et le compléter.
3. **Préparateur** : démarrer la préparation, puis « Marquer prête » (contrôle du conditionnement).
4. **Responsable des livraisons** : affecter la commande à « Livreur Démo A » ; consulter les incidents et les règlements.
5. **Livreur** (`livreur1`, fenêtre étroite) : accepter, enlever, livrer avec preuve — ou déclarer un échec.
6. **Administrateur** : tableau de bord, statistiques, approbation d'un remboursement, journal d'audit.

## 6. Recréer les données (nouvel environnement local)
```bash
cd backend
php artisan migrate
php artisan db:seed                                   # rôles, paramètres, contenus, comptes de démonstration
php artisan mk:import-catalogue ../docs/propositions/catalogue-propose.csv --publish
php artisan db:seed --class=LaunchConfigurationSeeder # zones, horaires, règles validées
php artisan db:seed --class=DemoActivitySeeder        # activité de démonstration (une seule fois)
```
Le seeder de démonstration refuse de s'exécuter hors des environnements `local` et `testing`.

## 7. Base de données (accès technique)
PostgreSQL 18 — hôte `127.0.0.1`, port `5433`, base `mk_delices` (tests : `mk_delices_test`), utilisateur `postgres`. Le mot de passe est celui de votre serveur PostgreSQL ; il figure uniquement dans `backend/.env` (jamais dans Git).
