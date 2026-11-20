# Jeu d'essai — MK Délices

**Pour :** les personnes qui passent la recette (MK Délices) et l'équipe technique qui prépare l'environnement.
**Date :** 20/11/2026.

Le jeu d'essai fournit des **données fictives** pour exercer chaque scénario du [cahier de recette](CAHIER-DE-RECETTE-MK-DELICES.md). Il est produit par les **vrais services de l'application** (mêmes règles qu'en production) et se renouvelle en une commande, pour que les commandes « du jour » soient toujours datées du jour.

## 1. Générer ou renouveler le jeu d'essai

Sur le poste de développement ou la préproduction, jamais en production (la commande le refuse) :

```bash
# 1. Sauvegarde préalable
#    poste local   : pg_dump vers backend/storage/app/backups/
#    préproduction : sudo /usr/local/sbin/mk-backup
# 2. Renouvellement
php artisan mk:demo-refresh
```

**Ce que fait la commande :**
- la génération précédente est **archivée**, jamais supprimée : ses commandes passent « de test » et disparaissent des écrans de travail, des statistiques, des créneaux et des avis publiés ;
- une nouvelle génération est créée ;
- la commande affiche la **fiche du jeu d'essai** et l'enregistre dans `backend/storage/app/private/jeu-essai/JEU-D-ESSAI.md`.

**Prérequis** (déjà en place en préproduction) :
- catalogue publié ;
- configuration de lancement (zones, horaires, règles) ;
- comptes de démonstration ;
- périmètre de Dakar.

## 2. Contenu

| Domaine | Données |
|---|---|
| Historique | 60 à 80 commandes sur 30 jours (hors lundis) : livrées, annulées, refusées et remboursées, échecs puis relivraison ; avis ; factures et avoirs ; règlements des livreurs |
| Aujourd'hui | Une commande dans chaque état : à confirmer (payée et non payée), acceptée, en préparation, prête, confiée au livreur, en route, échec de livraison ; 2 brouillons WhatsApp |
| Cas ciblés (jeu d'essai) | Paiement échoué ; paiement « en vérification » ; commande prise par téléphone et encaissée au back-office ; remboursement partiel **en attente d'approbation** ; avis refusé ; mission refusée, rendue, restée sans réponse ; remise sans code ; dépassement de la chaîne du froid |
| Géolocalisation | Vrais quartiers des 4 zones, positions approximatives dans Dakar : GPS, carte, adresse enregistrée, saisie sans position ; une commande modifiée par le commercial |
| Comptes | Les 5 rôles de l'équipe, 2 livreurs, 3 clients avec compte (adresses par défaut, avec et sans position) |

## 3. Utiliser la fiche

La fiche relie chaque scénario du cahier à la donnée à utiliser :
- le compte avec lequel se connecter ;
- la référence de commande `MKD-…` à rechercher dans le back-office ;
- le code de remise ou le lien de suivi, si le scénario en a besoin.

Exemples :
- « C-09 / A-01 / A-02 » donne la commande dont le remboursement partiel attend l'approbation de l'administrateur ;
- « L-03 » donne la commande en route et son code de remise.

Les références changent à chaque renouvellement : utiliser **la fiche de la dernière génération**.

**Scénarios sans donnée préparée.** Pour les scénarios de la boutique (R-01 à R-06…), du catalogue, de la carte, des paramètres et des comptes, le testeur crée lui-même la donnée pendant le test.

**L-04 (paiement à la livraison).** Ce mode est désactivé par décision : l'activer d'abord dans Paramètres › Paiement à la livraison, puis le désactiver après le test.

## 4. Après la recette

- Les commandes créées pendant les tests restent dans la base. Pour les écarter, renouveler le jeu d'essai, ou utiliser `php artisan mk:flag-test-orders` pour les commandes au prénom « E2E ».
- Avant l'ouverture au public : aucune donnée de démonstration en production. Voir `php artisan mk:go-live-check` et `docs/11-DEPLOYMENT/GO-NO-GO-PRODUCTION.md`.
