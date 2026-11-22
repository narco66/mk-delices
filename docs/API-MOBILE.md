# API pour l'application mobile MK Délices

**Pour :** les développeurs de l'application mobile (iOS / Android, native ou multiplateforme).
**Contrat machine :** [`06-API-CONTRACT/openapi.yaml`](06-API-CONTRACT/openapi.yaml) (OpenAPI 3.1, étiquette **Mobile** pour l'authentification) ; liste exhaustive des routes : `php artisan route:list --path=api/v1`.

L'application mobile utilise **la même API REST que le site** (`/api/v1`) : mêmes données, mêmes règles métier (prix, créneaux, stock, paiements), mêmes droits par rôle. Seule l'authentification diffère : **jeton d'accès par appareil** au lieu du cookie de session.

## 1. Accès

| Environnement | URL de base |
|---|---|
| Préproduction | `https://preprod.mk-delices.tech/api/v1` (depuis le 20/11/2026 ; l'ancienne adresse `srv2163043.hstgr.cloud` redirige) — le site est protégé par un mot de passe d'accès qui utilise lui aussi l'en-tête `Authorization`, incompatible avec le jeton `Bearer`. L'équipe mobile communique donc **l'adresse IP publique** de ses postes / réseau de test, ajoutée à la liste blanche Nginx (`allow …;` dans `mk-delices-staging.conf`) |
| Production | `https://mk-delices.com/api/v1` (domaine retenu ; pas encore ouvert) |
| Poste de développement | Émulateur Android : `http://10.0.2.2:8000/api/v1`. Téléphone physique : `http://<IP du poste>:8000/api/v1`, avec l'API lancée par `php artisan serve --host=0.0.0.0` (le script `start-dev.ps1` l'écoute seulement sur `127.0.0.1`) et le port 8000 ouvert dans le pare-feu Windows |

**Images** : les adresses d'image (`images[].url`, `srcset`) sont absolues et construites sur l'hôte appelé par l'application. Elles sont donc joignables depuis le téléphone, quelle que soit l'adresse utilisée (correction du 21/11/2026 : elles valaient auparavant `http://localhost:8000/…`). En préproduction, les images sont protégées par le même mot de passe d'accès que l'API : elles ne s'affichent que si l'adresse IP de l'application est dans la liste blanche.

En-têtes de chaque requête : `Accept: application/json`, `Content-Type: application/json` (sauf envoi de fichier), et `Authorization: Bearer <jeton>` une fois connecté.
**Ne jamais envoyer d'en-tête `Origin`/`Referer` du site** : l'API traiterait la requête comme venant du site web (session + CSRF).

## 2. Authentification

### Connexion (tout compte : client, livreur, équipe)
```http
POST /api/v1/auth/token
{ "login": "77 123 45 67", "password": "…", "device_name": "iPhone de Fatou" }
```
`login` accepte **l'email ou le numéro de téléphone** (formats `77 123 45 67`, `771234567`, `+221 77 123 45 67` ; connexion par téléphone de la maquette, ajoutée le 22/11/2026). L'ancien champ `email` reste accepté. Depuis le 22/11/2026, un numéro de téléphone ne peut appartenir qu'à un seul compte (`422 phone_taken` à l'inscription ou au changement de profil).

Réponse `201` :
```json
{ "data": {
  "token": "12|kq3…",
  "token_type": "Bearer",
  "expires_at": "2026-12-15T10:00:00Z",
  "user": { "id": "…", "roles": ["client"], "permissions": [], "is_staff": false, "is_courier": false, "customer": { "first_name": "Fatou", … } }
} }
```
- Le jeton n'est renvoyé **qu'une fois** : le conserver dans le **stockage sécurisé** de l'appareil (Keychain iOS, Android Keystore / EncryptedSharedPreferences), jamais en clair ni dans les journaux.
- Il expire au bout de **30 jours** (`expires_at`) : à l'expiration ou sur toute réponse `401`, renvoyer l'utilisateur vers l'écran de connexion.
- `user.roles` / `user.permissions` servent à **afficher** les bons écrans ; le serveur revérifie toujours les droits.

### Inscription d'un client
`POST /api/v1/auth/register` avec `first_name`, `last_name?`, `email`, `phone` (sénégalais), `password` (≥ 10 caractères, lettres et chiffres), `password_confirmation`, `accepted_privacy: true`, `marketing_consent?` **et `device_name`** → même réponse que la connexion (compte client + jeton).

### Mot de passe oublié
| Action | Requête |
|---|---|
| Demander un lien | `POST /auth/forgot-password` `{ email }` → `202` avec un message identique que le compte existe ou non |
| Choisir le nouveau mot de passe | Le lien reçu par email ouvre la page du site `/mot-de-passe/nouveau?token=…&email=…` (valable 60 min, usage unique) ; l'API équivalente est `POST /auth/reset-password` `{ token, email, password, password_confirmation }` |

Le changement de mot de passe **déconnecte tous les appareils** (jetons révoqués). Limite : 5 demandes par minute et par adresse.

### Suppression du compte (exigence Google Play / App Store)
`DELETE /account` `{ password }` → `204`. Identité, coordonnées, adresses, favoris, appareils et jetons sont effacés ; les commandes et factures sont conservées sans lien avec la personne (obligations comptables) ; les avis sont signés « Client MK Délices ».
- `422 invalid_password` : mot de passe incorrect ;
- `409 active_orders` : une commande est en cours (à livrer ou annuler d'abord) ;
- `403 staff_account` : comptes du personnel et des livreurs, supprimés par un administrateur.

Pour la fiche Google Play (« URL de suppression du compte ») : la page `/compte` du site (onglet **Profil & préférences** → **Supprimer mon compte**), accessible après connexion.

### Session et appareils
| Action | Requête |
|---|---|
| Profil, rôles et permissions | `GET /auth/me` |
| Déconnexion de cet appareil | `POST /auth/logout` (révoque uniquement ce jeton) |
| Appareils connectés | `GET /auth/tokens` (`current: true` = cet appareil) |
| Déconnecter un autre appareil (téléphone perdu) | `DELETE /auth/tokens/{id}` |

Sécurité côté serveur : 5 tentatives de connexion par minute et par email ; au plus 10 appareils par compte (les plus anciens sont déconnectés) ; **tous les jetons sont révoqués** si le compte est désactivé ou si son mot de passe est changé ; un compte désactivé reçoit `403 account_disabled`.

## 3. Parcours client

| Étape | Requêtes |
|---|---|
| Accueil, contenus, réglages | `GET /config` (moyens de paiement, WhatsApp, horaires), `GET /content/home`, `GET /content/pages/{slug}` |
| Catalogue | `GET /catalog/categories`, `GET /catalog/products?category=&q=&featured=1&new=1&page=`, `GET /catalog/products/{slug}`, `GET /promotions`, `GET /delivery/zones` |
| Panier | `POST /cart` → `cart_id` + `cart_token` à conserver ; puis en-têtes `X-Cart-Id` / `X-Cart-Token` sur `GET /cart`, `POST /cart/items`, `PATCH|DELETE /cart/items/{id}`, `PUT /cart/promotion` |
| Devis et créneaux | `POST /checkout/quote` `{ zone_id }` → montants calculés par le serveur + `slots` disponibles |
| Commande | `POST /checkout/orders` avec en-têtes panier + **`Idempotency-Key`** (UUID généré une fois par tentative, réutilisé en cas de nouvel essai réseau) → `reference` + `tracking_token` |
| Paiement | `POST /orders/{ref}/payments` `{ method }` + `Idempotency-Key` → `checkout_url` à ouvrir dans un **navigateur intégré** (SFSafariViewController / Custom Tabs) ; au retour : `POST /orders/{ref}/payments/refresh`, puis `GET /orders/{ref}/status` |
| Suivi | `GET /orders/{ref}/status` ; annulation avant acceptation `POST /orders/{ref}/cancel` ; avis après livraison `POST /orders/{ref}/reviews` ; message WhatsApp prérempli `POST /orders/{ref}/whatsapp-draft` |
| Factures | `GET /orders/{ref}/invoices/{numéro}` → PDF (liste dans `invoices` de la commande) |
| Espace client | `GET /account/orders`, `PUT /account/profile`, `GET|POST /account/addresses`, `PUT|DELETE /account/addresses/{id}` |
| Favoris | `GET /account/favorites` (produits, même format que le catalogue ; un produit retiré disparaît de la liste), `POST /account/favorites` `{ product_id }`, `DELETE /account/favorites/{product_id}` |
| Recommander | `POST /account/orders/{ref}/reorder` (en-têtes `X-Cart-Id`/`X-Cart-Token` facultatifs) → panier aux **prix du jour** ; `cart_token` renvoyé seulement si un nouveau panier a été créé ; `skipped[]` = articles devenus indisponibles ; `422 reorder_unavailable` si aucun ne l'est |
| Club MK | `GET /account/loyalty` → `{ enabled, delivered_orders, orders_per_reward, stamps, remaining, rewards_earned, reward_label }` (ou `{ enabled: false }`) ; carte « 04/06 » de la maquette = `stamps`/`orders_per_reward` |

**Client connecté :** avec le jeton `Bearer`, panier et commande sont rattachés à son compte, et ses commandes sont accessibles **sans** `X-Tracking-Token`.
**Invité (sans compte) :** l'application conserve le `tracking_token` reçu à la commande et l'envoie dans l'en-tête `X-Tracking-Token` pour suivre, payer ou télécharger la facture.

**Paiement réel suspendu (22/11/2026) :** tant qu'aucun prestataire n'est contractualisé, `GET /config` renvoie `online_payment.enabled: false` et un message `online_payment.notice` à afficher ; Wave, Orange Money et carte n'apparaissent jamais dans `payment_methods`. Seuls le paiement à la livraison (s'il est activé) et, en préproduction, le paiement simulé restent proposés.

**Règle impérative :** une commande n'est payée que lorsque **le serveur** l'indique (`payment_status: "paid"`). Ne jamais considérer un paiement réussi sur la seule base du retour du navigateur ou du prestataire.

## 4. Application livreur

Compte avec le rôle `livreur` (`user.is_courier: true`) :

| Action | Requête |
|---|---|
| Disponibilité | `PUT /courier/availability` `{ available }` |
| Missions | `GET /courier/missions`, `GET /courier/missions/{id}` (adresse et téléphone du client visibles seulement après acceptation) |
| Actions | `POST /courier/missions/{id}/actions` : `accept`, `refuse` (`reason`), `pick_up` (`packaging_checked: true`), `deliver` (`proof_type`, `proof_reference`, `product_condition`, `cash_collected_xof` si paiement à la livraison), `fail` (`incident_reason`, `incident_note`, `contact_attempted`) |

## 5. Notifications push (clients et livreurs)

Firebase Cloud Messaging (Android, et iOS via APNs). `GET /config` → `push.mobile: true` quand le serveur est configuré : l'application ne demande l'autorisation de notifier que dans ce cas.

| Action | Requête |
|---|---|
| Enregistrer le téléphone (après connexion, et à chaque nouveau jeton FCM) | `POST /account/devices` `{ token, platform: "android"|"ios", app_version? }` → `201` (ou `200` si déjà connu) avec `id` |
| Désactiver les notifications sur ce téléphone | `DELETE /account/devices/{id}` |

- L'enregistrement est lié au **jeton de connexion** : déconnexion, mot de passe changé ou compte désactivé le suppriment automatiquement. Un même téléphone qui change de compte est réattribué au dernier compte connecté. Au plus 10 téléphones par compte.
- Le jeton FCM est chiffré en base et jamais renvoyé par l'API ; un jeton refusé par Firebase (application désinstallée) est supprimé.
- **Contenu** : titre + texte sans donnée personnelle (ni nom, ni téléphone, ni adresse), et des données de navigation :
  - client : `{ type: "order_update", event: "order.accepted", reference, route: "/orders/{ref}" }` (événements : reçue, confirmée, refusée, prête, en route, livrée, annulée, livraison non aboutie, paiement confirmé) ;
  - livreur : `{ type: "courier_mission", mission_id, route: "/courier/missions/{id}" }` (nouvelle mission proposée).
- À l'ouverture d'une notification, relire l'état par l'API (`GET /orders/{ref}/status`, `GET /courier/missions/{id}`) : la notification n'est jamais une source de vérité.
- Canal Android : `orders` (à créer dans l'application).

Configuration serveur (`.env`, jamais dans le dépôt) : `FCM_PROJECT_ID` et `FCM_CREDENTIALS` (chemin du fichier JSON du compte de service Firebase, ex. `/etc/mk-delices/firebase-service-account.json`, lisible par PHP uniquement). Sans ces valeurs, aucune notification n'est envoyée et le reste fonctionne.

## 6. Équipe (back-office mobile, optionnel)

Les routes `/admin/*` acceptent le même jeton, avec les permissions du rôle (`user.permissions`) : commandes, préparation, livraisons, factures… Elles sont décrites dans le contrat OpenAPI.

## 7. Conventions

- **Erreurs** : `{ "error": { "code", "message", "details" }, "request_id" }`. Afficher `message` (en français, destiné à l'utilisateur). `422 validation_failed` : `details` contient les erreurs par champ. Communiquer `request_id` au support.
- **Codes utiles** : `401` jeton absent/expiré/révoqué → reconnexion ; `403` droit manquant ou compte désactivé ; `404` ressource introuvable ou non autorisée (volontairement indistinct) ; `409` conflit (état changé entre-temps, créneau pris) → recharger ; `429` trop de requêtes → réessayer plus tard (en-tête `Retry-After`).
- **Pagination** : `?page=` ; réponse `data` + `meta.current_page`, `meta.last_page`, `meta.total`.
- **Montants** : entiers en **XOF** (FCFA), sans décimales (`total_xof`). **Dates** : ISO 8601 en UTC ; afficher en heure de Dakar (`Africa/Dakar`).
- **Booléens en paramètre d'URL** : `1` / `0`.
- **Limitation de débit** : 120 requêtes/min par utilisateur, 60/min pour le panier, 20/min pour la commande, 30/min pour le suivi.
- **Version** : préfixe `/api/v1` ; toute évolution incompatible passera par `/api/v2`.

## 8. Comptes de test (préproduction)

Voir [ACCES-DEMONSTRATION.md](ACCES-DEMONSTRATION.md) : client `client1@mk-delices.test`, livreur `livreur1@mk-delices.test`, etc. Paiement : méthode `simulator` (aucun débit réel).
