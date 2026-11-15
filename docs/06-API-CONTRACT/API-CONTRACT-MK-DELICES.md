# Contrat API REST

**MK DÉLICES** — Dossier de cadrage fonctionnel et technique

**Document 06/12** · Version 1.0 · 14 novembre 2026

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


## 1. Convention générale

Base URL `/api/v1`, JSON UTF-8, HTTPS. Dates en ISO 8601 UTC, montants entiers XOF, pagination page/per_page (max 100) ou curseur stable. Auth : cookies Sanctum CSRF first party pour staff ; jeton de suivi haché et limité pour invité. `Idempotency-Key` requis sur création de commande/session paiement. Réponses d erreur : `{ "error": {"code": "...", "message": "...", "details": {}}, "request_id": "..." }`. Aucun numéro de téléphone personnel dans une URL.

## 2. Inventaire des endpoints

| HTTP | Endpoint | Accès | Traitement | Codes |
| --- | --- | --- | --- | --- |
| GET | /catalog/categories | Public | Liste catégories actives | 200 |
| GET | /catalog/products | Public | Recherche/filtre produits disponibles | 200 |
| GET | /catalog/products/{slug} | Public | Fiche détaillée | 200/404 |
| POST | /cart | Invité/client | Création panier | 201/422 |
| PATCH | /cart/items/{id} | Propriétaire | Mettre à jour quantité | 200/409 |
| POST | /checkout/quote | Invité/client | Devis, zone, créneau | 200/422 |
| POST | /checkout/orders | Invité/client | Confirmer commande idempotente | 201/409/422 |
| POST | /orders/{ref}/payments | Propriétaire | Initier PSP | 201/409 |
| GET | /orders/{ref}/status | Jeton secret | Suivi public restreint | 200/403 |
| POST | /orders/{ref}/whatsapp-draft | Propriétaire | Préparer message WhatsApp | 201 |
| POST | /webhooks/payments/{provider} | Prestataire signé | Recevoir événement vérifié | 200/400/401 |
| POST | /auth/login | Staff/livreur | Créer session | 200/401/429 |
| POST | /auth/logout | Authentifié | Fermer session | 204 |
| GET | /admin/orders | Commercial/admin | Liste paginée | 200/403 |
| GET | /admin/orders/{id} | Commercial/admin | Détail et journal | 200/403/404 |
| POST | /admin/orders/{id}/transitions | Acteur autorisé | Transition validée | 200/409/422 |
| GET | /admin/products | Commercial/admin | Lister y compris inactifs | 200 |
| POST | /admin/products | Commercial/admin | Créer produit | 201/422 |
| PATCH | /admin/products/{id} | Commercial/admin | Modifier produit | 200/409 |
| GET | /admin/delivery/zones | Responsable/admin | Zones et frais | 200 |
| POST | /admin/delivery/assignments | Responsable/admin | Affecter mission | 201/409 |
| GET | /courier/missions | Livreur | Mes missions uniquement | 200/403 |
| POST | /courier/missions/{id}/actions | Livreur affecté | Accepter, enlever, livrer, échec | 200/409 |
| GET | /admin/stats | Commercial/admin | Agrégats filtrés | 200/403 |
| GET | /admin/payments | Commercial/admin | Rapprochement paiement | 200/403 |

## 3. Contrats et validations exemplaires

### POST /api/v1/checkout/orders

Requête JSON (exemple fictif) :
```json
{"cart_id":"d3e5efc9-6a40-4b27-96b7-9a0c935d0a3b","customer":{"first_name":"Exemple","phone":"+221700000000"},"delivery":{"zone_id":"00000000-0000-4000-8000-000000000001","district":"Quartier de démonstration","landmark":"Près de la place"},"slot_start_at":"2026-11-20T14:00:00Z","payment_method":"wave","accepted_terms":true}
```
Réponse `201` :
```json
{"data":{"reference":"MKD-DEMO-0001","status":"pending_confirmation","payment_status":"unpaid","total_xof":0,"currency":"XOF"},"meta":{"request_id":"demo"}}
```
Le `total_xof: 0` est purement illustratif et doit être remplacé par le montant calculé du devis serveur ; une commande réelle peut être refusée si la politique de montant minimum l interdit.

### POST /api/v1/admin/orders/{id}/transitions
```json
{"action":"accept","reason":null,"expected_version":3}
```
Réponse `409` si version changée ou transition impossible ; `403` si acteur non autorisé.

### POST /api/v1/courier/missions/{id}/actions
```json
{"action":"delivered","proof_type":"recipient_confirmation","proof_reference":"confirmation-client"}
```
La réponse refuse une livraison avant l enlèvement et contrôle l affectation au livreur.

## 4. Règles transversales des endpoints

| Règle | Obligation |
| --- | --- |
| Auth | Policy et ownership vérifiés sur chaque ressource par ID |
| Validation | FormRequest et messages d erreur localisés |
| Idempotence | Même clé + même payload = même résultat ; payload différent = 409 |
| Confidentialité | Masquer téléphone/adresse si non nécessaire au rôle |
| Versions | ETag/row_version pour transitions concurrentes |
| Pagination | Filtres whitelist, tri contrôlé, page max |
| Webhooks | Signature/déduplication/horodatage + réponse rapide |
| Observabilité | request_id et corrélation événementielle |

## 5. Spécification OpenAPI

Le fichier `openapi.yaml` adjacent fournit une spécification OpenAPI 3.1 initiale, importable dans Swagger UI/Redoc et extensible. Ce contrat initial couvre les endpoints critiques et les schémas partagés. La version de référence doit être enrichie et validée avant gel de l API.

## 6. Cas de tests contractuels

- Créer commande invité valide et relecture par jeton.
- Refuser création sans téléphone ou créneau valide : 422.
- Renvoyer même commande sur double clic avec même clé : 201/200 documenté.
- Refuser réutilisation de clé avec payload différent : 409.
- Refuser liste admin en tant que livreur : 403.
- Ne jamais considérer la redirection navigateur comme succès de paiement.
- Accepter deux livraisons du même webhook sans doubler l écriture comptable.

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
