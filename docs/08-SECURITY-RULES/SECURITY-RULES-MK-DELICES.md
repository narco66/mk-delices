# Sécurité et règles pour agents IA

**MK DÉLICES** — Dossier de cadrage fonctionnel et technique

**Document 08/12** · Version 1.0 · 14 novembre 2026

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


## 1. Modèle de menace et périmètre

Données sensibles : nom, téléphone, adresse de livraison, historique achat, sessions, clés PSP. Menaces : usurpation de rôle, consultation croisée de commandes, interception webhook, doublon paiement, vol session, injection, XSS, CSRF, téléversement malveillant, fuite de journaux et spam WhatsApp. Défense en profondeur, moindre privilège et politique deny-by-default.

## 2. Matrice RBAC

| Action | Visiteur | Client / invité propriétaire | Commercial | Préparateur | Resp. livraison | Livreur affecté | Admin |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Catalogue public | L | L | L | L | L | L | L |
| Créer commande | C | C | C | — | — | — | C |
| Consulter commande privée | — | P | L | L limité | L nécessaire | P mission | L |
| Valider / refuser commande | — | — | M | — | — | — | M |
| Changer préparation | — | — | M | M | — | — | M |
| Affecter livraison | — | — | — | — | M | — | M |
| Signer remise | — | — | — | — | Supervise | M propre | Supervise |
| Gestion catalogue | — | — | M | — | — | — | M |
| Gestion utilisateurs | — | — | — | — | — | — | M |
| Rapports financiers | — | — | L | — | — | — | L |

L=lecture, C=création, M=mutation, P=propriété seulement, —=interdit. Les rôles cumulés doivent être contrôlés pour éviter les escalades.

## 3. Exigences de sécurité vérifiables

| ID | Contrôle | Test de validation |
| --- | --- | --- |
| SEC-001 | HTTPS/HSTS, cookies Secure HttpOnly SameSite | Refus du trafic HTTP en prod |
| SEC-002 | Sanctum + CSRF pour sessions first-party | POST sans jeton CSRF rejeté |
| SEC-003 | Autorisation serveur sur chaque objet | Livreur A ne lit pas mission B |
| SEC-004 | Validation stricte des uploads, taille, MIME, re-encodage | Script déguisé en image rejeté |
| SEC-005 | Webhook signé et idempotent | Signature invalide 401, doublon inoffensif |
| SEC-006 | Secrets uniquement côté serveur/vault | Scan Git sans secret ni clé |
| SEC-007 | Rate limit login, panier, devis et suivi | Trop de requêtes → 429 |
| SEC-008 | Logs minimisés, expurgés | Aucun jeton ni carte en trace |
| SEC-009 | Sauvegardes chiffrées + restauration testée | Simulation restauration réussie |
| SEC-010 | Dépendances contrôlées | Audit composer/npm et correctifs |
| SEC-011 | Exposition PII minimale | Réponse API par rôle ne fuit pas adresse |
| SEC-012 | Headers de sécurité CSP, nosniff, Referrer-Policy | Vérification automatisée |
| SEC-013 | Consentement marketing explicite | Aucune inscription automatique |
| SEC-014 | Traitement remboursements sous double contrôle métier | Journaux de validation |

## 4. Cadre réglementaire Sénégal

Textes officiels identifiés : **loi n° 2008-12 du 25 janvier 2008** sur la protection des données personnelles et décret d application **n° 2008-721** ; **loi n° 2008-08 du 25 janvier 2008** sur les transactions électroniques. Sources institutionnelles : https://www.cdp.sn/legislation/textes-legislatifs et https://www.cdp.sn/legislation/textes-reglementaires.

**À faire vérifier juridiquement :** formalités CDP applicables au traitement concret, mentions d information, durée de conservation et base légale, hébergement/transfert hors Sénégal, conditions générales de vente, droit de réclamation et règles de vente alimentaire. Le dossier n émet pas d avis juridique définitif.

## 5. Exigences de confidentialité et rétention

Limiter la collecte à téléphone, prénom, instructions de livraison utiles et données de vente. Publier politique de confidentialité accessible avant paiement ; consentement marketing facultatif et non précoché. Les adresses de livraison ne sont dévoilées au livreur qu à l affectation ; masquer après clôture selon politique. Journaliser demandes d accès/correction/suppression et statuer selon conservation réglementaire des pièces comptables.

## 6. Règles impératives pour Claude Code / Cursor

| DOIT FAIRE | NE DOIT JAMAIS FAIRE |
| --- | --- |
| Lire PRD/TRD et tests avant code | Inventer catalogue/prix réels |
| Créer migrations transactionnelles et tests | Modifier base de production sans sauvegarde |
| Protéger endpoints par policies | Faire confiance aux prix du navigateur |
| Utiliser sandbox PSP et faux contacts en tests | Inclure une clé PSP dans source ou frontend |
| Gérer idempotence et concurrents | Marquer payé à partir d une page succès |
| Produire journal des changements | Masquer erreurs et supprimer logs de preuve |
| Tester refus d accès multirôle | Utiliser numéros clients réels dans seeders |
| Limiter les changements au périmètre validé | Implémenter GPS sans consentement explicite |

## 7. Incidents et réponse

Incident paiement : arrêter la confirmation automatique, conserver événements vérifiés, lancer rapprochement, informer responsable, documenter correction. Incident fuite : isoler ressource, révoquer secrets, préserver journaux, analyser notification réglementaire avec conseil juridique. Incident applicatif : rollback applicatif, pas de rollback de migrations destructives sans plan ; restauration testée et post-mortem sous 72h comme objectif interne proposé.

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
