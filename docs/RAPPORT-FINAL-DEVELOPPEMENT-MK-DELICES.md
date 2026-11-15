# Rapport final de développement — MK Délices

**Date :** 15/11/2026 (mis à jour après la 2e campagne de tests) · **Version :** MVP technique 0.1 · **Environnement de référence :** Windows 11 / Laragon, PHP 8.5.12, PostgreSQL 18.4, Node 22.

> **Conclusion :** l'application est **techniquement prête pour une recette fonctionnelle avec données fictives**. Elle n'est **pas prête pour la production** : catalogue, tarifs, zones, numéro WhatsApp, contrats de paiement, textes juridiques, hébergement et sauvegardes dépendent de décisions de MK Délices (28 points, `DEV-DECISIONS-A-VALIDER.md`).

## 1. Architecture implémentée
Monolithe modulaire : SPA React 19/TypeScript/Vite → API REST Laravel 13 (`/api/v1`, session Sanctum + CSRF) → PostgreSQL 18. Logique métier isolée dans `backend/app/Domain` (catalogue, panier, commandes, paiements, livraisons, promotions, reporting). File d'attente pour les webhooks et emails ; planificateur pour le rapprochement des paiements. Pré-rendu SEO des pages publiques par Laravel. Détails : `DEV-ARCHITECTURE.md`.

## 2. Modules développés
| Module | Contenu |
|---|---|
| 1. Site vitrine | Accueil (contenus éditables), Notre histoire, Nos tiramisus, Boutique, fiche produit, Promotions, Livraison, Contact, FAQ, CGV, confidentialité, mentions légales, bouton WhatsApp flottant |
| 2. Catalogue | Catégories, produits, formats/variantes, saveurs, prix, stock facultatif, ingrédients, allergènes, conservation, délai de préparation, produits associés, SEO ; recherche, filtres, tri ; administration complète, images ré-encodées WebP |
| 3. Panier & checkout | Panier invité serveur, code promo, devis serveur, zones, créneaux avec capacité, commande idempotente, référence non prédictible, lien de suivi secret |
| 4. WhatsApp | Brouillon référencé + message prérempli encodé, anti-doublon, conversion atomique en commande, confirmation commerciale |
| 5. Paiements | Interface d'adaptation, simulateur de test, adaptateur Wave (non exécuté), webhooks signés et idempotents, rapprochement, paiement tardif, encaissement manuel tracé, remboursements sous double contrôle |
| 6. Commandes | Machine à états complète, statuts commercial / financier / logistique distincts, historique immuable, filtres, recherche, version concurrente |
| 7. Préparation | Kanban, priorités par créneau, allergènes et conservation, contrôle du conditionnement obligatoire |
| 8. Livraisons | Livreurs, zones, affectation/réaffectation, missions, preuve de remise, incidents, montants dus et règlements ; espace livreur mobile |
| 9. Clients | Comptes facultatifs, profil, adresses, historique, préférences, consentement marketing, avis modérés, codes promo |
| 10. Back-office | Menus et API par rôle (admin, commercial, préparateur, resp. livraisons, livreur), utilisateurs, paramètres, contenus, journal d'audit, notifications internes |
| 11. Tableaux de bord | Compteurs opérationnels cliquables, KPI (CA net, panier moyen, livraison réussie, préparation, remboursements, abandon panier), graphiques, meilleures ventes, commandes récentes |

## 3. Fonctionnalités opérationnelles (vérifiées par tests exécutés)
Parcours complet visiteur → commande → paiement (simulé) → acceptation → préparation → affectation → enlèvement → livraison avec preuve ; incident et réaffectation ; brouillon WhatsApp ; contrôle des accès par rôle et par objet ; idempotence commandes et webhooks ; statistiques sur données PostgreSQL ; SEO. Preuves : `DEV-TESTS.md`.

## 4. Fonctionnalités partiellement réalisées
- **Wave** : adaptateur conforme à la documentation publique, jamais exécuté (pas de compte marchand).
- **Notifications client** : email uniquement ; SMS et WhatsApp Business Platform non branchés.
- **Chaîne du froid** : contrôles de conditionnement en place ; seuil sanitaire non défini.
- **Accessibilité** : audit automatique WCAG 2.2 AA conforme (0 violation serious/critical) ; test avec lecteur d'écran par une personne à prévoir.

## 5. Fonctionnalités bloquées
Orange Money et carte bancaire (contrats/spécifications), données commerciales officielles, numéro WhatsApp, textes juridiques validés, hébergement/sauvegardes/supervision (décisions D-…). Hors MVP non commencés : points de fidélité, GPS, commandes événementielles.

## 6. API disponibles
101 routes sous `/api/v1` : publiques (config, contenus, catalogue, zones, promotions), panier et checkout, suivi propriétaire (statut, paiement, rapprochement, annulation, WhatsApp, avis), authentification, compte client, livreur, back-office (commandes, préparation, paiements, remboursements, catalogue, promotions, avis, contenus, clients, utilisateurs, paramètres, journal, livraisons, statistiques), webhooks prestataires, simulateur (local uniquement). Liste : `php artisan route:list --path=api/v1`.

## 7. Tables et migrations
10 migrations, **42 tables** PostgreSQL (dont 25 tables métier) : identités/permissions, catalogue, paramètres, zones, clients/adresses, promotions, paniers, commandes/lignes/événements, paiements/événements/remboursements, livreurs/zones/missions, contenus, avis, notifications, audit, idempotence, files d'attente. UUID, montants entiers, contraintes CHECK, index unique partiel, triggers d'immuabilité.

## 8. Tests exécutés et résultats
PHPUnit **74/74** (658 assertions, PostgreSQL) · Vitest **9/9** · Playwright **47/47** (mobile 360 px + desktop : parcours client, workflows back-office multi-acteurs, écrans d'administration, accessibilité) · axe-core : 0 violation serious/critical · TypeScript 0 erreur · ESLint 0 erreur, 0 avertissement · emails vérifiés (Mailpit) · Pint conforme · build OK · audits : 0 vulnérabilité en production. Scénarios A à H : tous ✅ (B avec prestataire simulé).

## 9. Anomalies corrigées
16 anomalies détectées par les tests et corrigées (fuseau PostgreSQL, `MAX(uuid)`, déduplication webhook, session après connexion, créneau suivant, chargement paresseux, `robots.txt`, double React, Vitest sous Windows, dépendances de dev, contrastes WCAG, nom accessible du logo, bouton de commande actif malgré un blocage, page de retour après remboursement, débordement mobile de la fiche produit, effet de réinitialisation de l'éditeur) — détail `DEV-TESTS.md` §4.

## 10. Anomalies restantes / points de vigilance
- Aucune anomalie bloquante connue.
- 26 avertissements ESLint non bloquants (*fast refresh*, compatibilité react-hook-form/compilateur React).
- Données de démonstration insérées avant la correction du fuseau : horodatages décalés d'une heure (fictives).
- Les tests E2E écrivent dans la base de développement (commandes automatiquement marquées `is_test` en fin de campagne).
- 7 vulnérabilités « moderate » dans l'outillage ESLint (minimatch), sans impact en production.

## 11. Intégrations externes
| Service | État |
|---|---|
| Wave Checkout | Adaptateur prêt, désactivé (identifiants absents) |
| Orange Money | Emplacement prévu, non implémenté |
| WhatsApp | Lien `wa.me` prérempli opérationnel ; API officielle non intégrée |
| Email | Notifications Laravel (pilote `log` en dev) |
| Polices Google Fonts | Fraunces, Inter |

## 12. État de sécurité
Implémenté et testé : RBAC deny-by-default côté serveur, contrôle par objet, CSRF/session HttpOnly, limitation de débit, webhooks signés + vérification PSP, idempotence, journaux immuables et expurgés, masquage des données personnelles par rôle, en-têtes de sécurité, aucune élévation possible par l'inscription publique, secrets hors Git. À faire en production : HTTPS/HSTS et cookies `Secure`, CSP du SPA (Nginx), MFA administrateurs, sauvegardes chiffrées, revue juridique CDP.

## 13. État de déploiement
Non déployé. Build de production fonctionnel, CI GitHub Actions prête. Fichiers de production prêts dans `deploy/` (Nginx HTTPS + CSP, Supervisor, cron, sauvegarde chiffrée, test de restauration, script de déploiement, modèle `.env`). Guides utilisateurs des 5 rôles dans `docs/guides/`. Aucune ressource de production utilisée.

## 14. Procédure de démarrage
Voir `README.md` (installation, `scripts/start-dev.ps1`, comptes de démonstration, paiement simulé).

## 15. Recommandations avant production
1. Obtenir et saisir les décisions 🔴 du registre (catalogue, allergènes, zones/tarifs, WhatsApp, CGV, confidentialité, mentions légales).
2. Signer un contrat PSP, valider l'adaptateur Wave en sandbox (créer les tests d'intégration correspondants), activer le moyen de paiement.
3. Choisir l'hébergement ; configurer HTTPS, `SESSION_SECURE_COOKIE`, CSP, sauvegardes chiffrées et test de restauration (T-020), supervision et alertes.
4. Fournir les photographies et le logo ; remplacer les illustrations.
5. Lancer un test de charge (NFR-002) et un test avec lecteur d'écran ; exécuter `deploy/scripts/restore-test.sh` sur une sauvegarde réelle (T-020).
6. Vérifier que le dépôt GitHub est privé et que la CI s'exécute.
7. Former les équipes (commercial, préparation, livraisons) et réaliser une recette métier avec des commandes de test marquées.
