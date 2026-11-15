# Tests et cahier de recette

**MK DÉLICES** — Dossier de cadrage fonctionnel et technique

**Document 10/12** · Version 1.0 · 14 novembre 2026

---

## Gouvernance du document

| Champ | Valeur |
|---|---|
| Projet | MK Délices — Plateforme e-commerce pâtisserie |
| Version | 1.0 — cadrage initial du 14 novembre 2026 |
| Statut | Proposition de référence ; à valider par MK Délices |
| Localisation | Dakar, Sénégal |
| Monnaie / fuseau | XOF / Africa/Dakar (UTC+0) |
| Socle préconisé | React + TypeScript + Vite ; Laravel 13 / PHP ≥ 8.3 ; PostgreSQL |
| Hypothèses | Catalogue, prix, zones, frais, prestataires et identité visuelle à confirmer |

**Convention :** « DOIT » = exigence impérative ; « DEVRAIT » = souhaitable ; « option » = hors MVP ou à arbitrer. Les exemples de tarifs, d'adresses, de parfums et de durées ne constituent jamais des données commerciales officielles. Toutes les valeurs monétaires sont enregistrées en unités entières de XOF.


## 1. Stratégie et environnements

Pyramide : tests unitaires des services/calculs ; tests intégration Eloquent/PostgreSQL ; API contract tests ; E2E navigateur Playwright ; tests sécurité et performance. Aucune transaction de production pendant les tests. Environnements local, CI éphémère et préproduction reproduisant les réglages de sécurité réels.

## 2. Matrice de recette

| ID | Rôle | Scénario | Résultat attendu |
| --- | --- | --- | --- |
| T-001 | Visiteur | Voir catalogue | Produits actifs et allergènes présents |
| T-002 | Visiteur | Ajouter variante au panier | Total recalculé par serveur |
| T-003 | Visiteur | Adresse hors zone | Checkout bloqué avec explication |
| T-004 | Visiteur | Créer commande invitée | Référence unique et jeton suivi privé |
| T-005 | Client | Double cliquer payer | Une session logique par clé idempotente |
| T-006 | PSP | Webhook valide payé | Paiement devient paid une fois |
| T-007 | PSP | Webhook signature falsifiée | 401, aucun changement |
| T-008 | PSP | Webhook doublé | Aucune double écriture |
| T-009 | Client | Retour navigateur succès sans webhook | Reste pending |
| T-010 | Commercial | Refuser commande déjà payée | Dossier de remboursement ouvert |
| T-011 | Préparateur | Marquer prête sans acceptation | 409 et journal sans transition |
| T-012 | Responsable livraison | Affecter deux livreurs actifs | Deuxième affectation rejetée |
| T-013 | Livreur A | Consulter mission B | 403 |
| T-014 | Livreur | Confirmer livré avant enlèvement | 409 |
| T-015 | Livreur | Déclarer échec livraison | Incident horodaté |
| T-016 | Admin | Modifier prix produit | N affecte pas prix snapshot ancien |
| T-017 | Invité | Accès suivi sans jeton | 403 ou 404 indistinct |
| T-018 | Commercial | Envoyer brouillon WhatsApp | Aucun paiement validé automatiquement |
| T-019 | API | Appeler > limite requêtes | 429 puis récupération |
| T-020 | Admin | Restaurer sauvegarde en environnement test | RPO/RTO mesurés et requêtes fonctionnelles |
| T-021 | Utilisateur mobile | Commander à 360 px | Pas de débordement ni CTA invisible |
| T-022 | Clavier seul | Compléter checkout | Focus, labels et erreurs utilisables |
| T-023 | Client | Réduire dernière unité disponible en concurrence | Une seule réservation gagnante |
| T-024 | Commercial | Rechercher commande par période | Résultats cohérents avec états de paiement |

## 3. Cas détaillé paiement — T-006

**Préconditions :** commande existante `pending_confirmation`, tentative `pending`, sandbox PSP configurée. **Action :** envoyer un webhook conforme signé avec identifiant externe unique. **Attendu :** HTTP 2xx, event persisté, statut payé après vérification prestataire, audit transactionnel, notification une seule fois. **Contre-tests :** signature invalide ; ID déjà traité ; montant ou devise discordant ; paiement annulé ; réponse PSP indisponible ; événement reçu hors ordre. **Preuve :** journaux expurgés, état DB, capture back-office et trace test automatisé.

## 4. Cas détaillé livraison — T-012

**Préconditions :** commande `ready`, deux livreurs actifs, zone éligible. **Action :** deux affectations concurrentes. **Attendu :** contrainte unique et contrôle applicatif empêchent deux missions actives. Une affectation seulement est confirmée ; l autre reçoit 409 ; journal complet de l opération. Tester refus puis réaffectation, enlèvement, livraison et incident.

## 5. Seuils et gate de mise en production

| Porte de qualité | Seuil de décision proposé |
| --- | --- |
| Fonctionnel | 100 % des scénarios critiques paiement/commande/livraison réussis |
| Sécurité | 0 vulnérabilité critique ou haute non traitée |
| Conformité | Mentions légales, CGV et confidentialité validées |
| Accessibilité | Parcours commande au clavier et lecture mobile satisfaisants |
| Performance | Tests P95 réalisés sur profil charge défini |
| Sauvegardes | Test restauration documenté et accepté |
| Opérations | Procédures incident, astreinte et contact PSP établis |

## 6. Matrice traçabilité exigences/tests

| Exigence PRD | Tests liés | Statut initial |
| --- | --- | --- |
| CAT-001 | T-001 + tests unitaires et RBAC | À exécuter |
| CART-002 | T-002 + tests unitaires et RBAC | À exécuter |
| ORDER-003 | T-003 + tests unitaires et RBAC | À exécuter |
| WA-004 | T-004 + tests unitaires et RBAC | À exécuter |
| PAY-005 | T-005 + tests unitaires et RBAC | À exécuter |
| PREP-006 | T-006 + tests unitaires et RBAC | À exécuter |
| DEL-007 | T-007 + tests unitaires et RBAC | À exécuter |
| CMS-008 | T-008 + tests unitaires et RBAC | À exécuter |
| SEC-009 | T-009 + tests unitaires et RBAC | À exécuter |
| STATS-010 | T-010 + tests unitaires et RBAC | À exécuter |
| LOY-011 | T-011 + tests unitaires et RBAC | À exécuter |
| GPS-012 | T-012 + tests unitaires et RBAC | À exécuter |
| EVT-013 | T-013 + tests unitaires et RBAC | À exécuter |

## 7. Modèle fiche anomalie

Champs : ID, référence exigence, environnement, version, date, rôle, préconditions, étapes reproduction, attendu, observé, preuves, sévérité, priorité, responsable, correction, résultat re-test et validation métier. Bloquants : exposition de données, encaissement erroné, double commande, livraison sans preuve, impossibilité de commande.

## Références et réserves

**Sources externes de vérification (consultées le 14/11/2026) :**
- https://laravel.com/framework/docs/13.x/releases
- https://docs.wave.com/business
- https://docs.wave.com/checkout
- https://espaceclientv2.orange.sn/orange-money/api-internet
- https://www.cdp.sn/legislation/textes-legislatifs
- https://www.cdp.sn/legislation/textes-reglementaires

Le présent document distingue faits du projet communiqués, recommandations de conception et décisions commerciales à confirmer. Les paramètres externes (PSP, tarification, obligations légales, contrats) doivent faire l objet de vérifications au moment de l implémentation.

## Approbation et contrôle de changement

| Rôle | Visa / date |
|---|---|
| Direction MK Délices | À compléter |
| Responsable produit / opérations | À compléter |
| Responsable technique | À compléter |

Toute modification après validation doit créer un historique de version, une justification et une analyse d impact sur les autres documents.
