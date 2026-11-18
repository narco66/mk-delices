# 05 — Rapport d'implémentation

**Pour :** équipe technique.
**Date :** 18/11/2026.

Rien n'est encore commité ni déployé. Le commit et le déploiement attendent l'accord de MK Délices.

## 1. Implémentation trouvée et conservée (17/11/2026, revue dans cet audit)

L'implémentation de la session précédente a été **revue et conservée**. Elle était cohérente, et ses tests passaient.

**Backend**
- `app/Domain/Geo/` :
  - `GeoPoint`, `ZoneArea`, `ZoneLocator` ;
  - `Geocoder`, `MapTilerGeocoder`, `NullGeocoder` ;
  - `GeocodedPlace`, `GeocoderUnavailable`.
- `Http/Controllers/Api/V1/GeoController.php`, routes `geo/resolve` et `geo/search` (limiteur `geo`).
- `CheckoutService` : zone déduite de la position, `address_snapshot` enrichi, `expected_total_xof` (409 `total_changed`). Dans `updateDetails`, l'équipe conserve la position du client.
- `CheckoutController::quote` : `lat`/`lng`, issue `out_of_zone`.
- `AccountController` : position des adresses, zone recalculée.
- `ReferenceDataController::saveZone` : contour `area` validé.
- `ContentController` :
  - `config.maps` (sans secret) ;
  - `area` dans `/delivery/zones`.
- `StatsService::byZone`.
- `config/mkdelices.php` (`maps.*`) et `AppServiceProvider` (liaison `Geocoder`, limiteur `geo`).
- Migration `2026_11_18_090000_add_geolocation_to_zones_and_addresses` :
  - **additive et réversible** : `delivery_zones.area` jsonb, `addresses.location_source`, `addresses.location_accuracy_m` ;
  - `down()` supprime ces seules colonnes ;
  - aucune donnée existante n'est modifiée.

**Frontend**
- `features/maps/` : `geo.ts`, `LocationPicker.tsx` et son test, `LeafletMaps.tsx`.
- `checkout.tsx`, `account.tsx`, `ZonesPage.tsx` (éditeur de contour, import GeoJSON), `OrderDetailPage.tsx` (position, alerte « zone non vérifiée »).
- `ZoneTable.tsx`, `useCourier.ts` (lien d'itinéraire), `types/api.ts`.
- Dépendances `leaflet@1.9.4` et `@types/leaflet@1.9.22`.

## 2. Modifications réalisées dans cet audit

| Anomalie | Fichiers | Changement |
|---|---|---|
| A-01 CSP | `deploy/nginx/snippets/mk-security-headers.conf` | `img-src … https://api.maptiler.com` (tuiles). `connect-src` inchangé : le géocodage passe par l'API |
| A-02 Total périmé | `frontend/src/pages/checkout.tsx` | `quoteRefreshing` : bouton désactivé, total masqué pendant le recalcul |
| A-03 Zone affichée ≠ facturée | `checkout.tsx` | `serverZone` / `zoneLocked` dérivés du devis : la sélection suit la zone calculée et se verrouille |
| A-04 Adresse par défaut | `AccountController.php`, `routes/api.php`, `checkout.tsx`, `account.tsx` | `POST /account/addresses/{id}/default`. Défaut unique (`keepSingleDefault`, transaction). Première adresse par défaut ; relais à la suppression. Liste triée défaut en tête ; présélection au checkout |
| A-05 Modification | `account.tsx` | Mode édition (position comprise), champ « Rue / villa », libellés d'accessibilité nominatifs |
| A-06 Filtres admin | `Admin/OrderController.php`, `admin/orders/OrdersPage.tsx` | `zone_id` (UUID ou `none`, validé) ; `zone_source` (`position`, `declared`, `staff`). Liste des zones chargée seulement avec `zones.view` |
| A-07 Libellé | `admin/orders/labels.ts` | `staff` → « Position saisie par l'équipe » |
| A-08 Bornes | `Http/Requests/PlaceOrderRequest.php` | Bornes uniques (`GeoPoint`) ; `required_with` entre `lat` et `lng` |
| A-09 Rafales | `LocationPicker.tsx`, `AppServiceProvider.php` | Anti-rafale de 400 ms (repère) ; limiteur `geo` porté de 20 à 30 par minute |
| A-10 Hors zone | `LocationPicker.tsx` | Lien `wa.me` prérempli (paramètre `business.whatsapp_number`) ; retrait rappelé si `ordering.pickup_enabled` |
| A-11 Conservation | `config/mkdelices.php`, `routes/console.php`, `StatsService.php` | Voir ci-dessous |
| A-12 Documentation | `MapTilerGeocoder`, `ZoneLocator`, `CheckoutService`, `StatsService`, `ZoneTable`, `GeolocationTest` | Références mises à jour ; ancien constat déplacé en `annexes/A1-…` ; `docs/DEV-JOURNAL.md` complété |

**Détail de la purge (A-11)**
- Paramètre `maps.position_retention_days` (`MAPS_POSITION_RETENTION_DAYS`, **0 par défaut = désactivée**).
- Commande `mk:purge-delivery-positions [--dry-run]`, planifiée chaque nuit à 03:35 (heure de Dakar).
- Elle ne vise que les commandes livrées, annulées ou refusées dont le créneau date de plus de N jours.
- Elle efface `lat`, `lng` et `accuracy_m`, et ajoute `position_purged_at`. Quartier, rue et repère sont conservés.
- Chaque purge est journalisée (`geo.positions_purged`).
- Les statistiques « Avec position » restent stables.

**Tests ajoutés**
- `backend/tests/Feature/GeolocationTest.php` (+4) : adresse par défaut et droits d'un autre client, coordonnée incomplète, filtres admin, purge.
- `frontend/src/features/maps/LocationPicker.test.tsx` : lien WhatsApp et retrait hors zone.
- `frontend/e2e/geolocalisation.spec.ts` (nouveau, mobile et bureau).

## 3. Migrations

| Migration | Nature | Réversible | Jouée |
|---|---|---|---|
| `2026_11_18_090000_add_geolocation_to_zones_and_addresses` | 3 colonnes nullables | Oui (`down`) | Base locale de développement : oui. Préproduction : **non** |

Cet audit n'ajoute aucune migration.

## 4. Compatibilité de l'API

Tous les ajouts sont facultatifs : les clients existants (site, API mobile) ne sont pas cassés.

Deux changements de comportement :
1. `delivery.lat` sans `delivery.lng` (ou l'inverse) renvoie désormais 422 au lieu d'être ignoré.
2. Une position hors région reste refusée en 422 :
   - avant : `validation_failed` ;
   - maintenant : `location_out_of_region`, plus explicite.

## 5. Déploiement en préproduction

1. Commit, puis `deploy/scripts/deploy.sh <ref>`. La migration est additive.
2. Copier `deploy/nginx/snippets/mk-security-headers.conf` vers `/etc/nginx/snippets/`, puis `nginx -t` et `systemctl reload nginx`.
3. Facultatif : variables `MAPS_*` (voir 07), puis `php artisan config:cache`.

## 6. Lot du 18/11/2026 après les décisions de MK Délices

| Décision | Fichiers | Changement |
|---|---|---|
| Périmètre desservi = ville de Dakar | `Domain/Geo/ZoneLocator.php` | Règle « hors périmètre = refus », prioritaire sur les zones ; champ `in_service_area` dans `/geo/resolve` |
| | `config/mkdelices.php`, `Admin/BackOfficeController.php` | Paramètre `delivery.service_area`, exclu de la page Paramètres générique (édition dédiée) |
| | `Admin/ReferenceDataController.php`, `routes/api.php` | `GET`/`PUT /admin/delivery/service-area` (`zones.view` / `zones.manage`), validé par `ZoneArea` et journalisé |
| | `routes/console.php` | `mk:set-service-area {fichier}` / `--clear` |
| | `database/data/ville-de-dakar.geojson` | Contour OSM (relation 12982139), partie continentale, 284 points |
| | `ContentController.php`, `features/maps/LocationPicker.tsx` | Périmètre affiché sur la carte du client ; message « en dehors de notre périmètre de livraison » |
| | `pages/admin/operations/ServiceAreaCard.tsx`, `ZonesPage.tsx` | Carte « Périmètre desservi » : état, dessin, import GeoJSON, effacement |
| Conservation de 90 jours | `config/mkdelices.php`, `.env.example` | `MAPS_POSITION_RETENTION_DAYS=90` par défaut |
| Laravel Boost | `composer.json/lock` (`laravel/boost` en dépendance de développement), `CLAUDE.md`, `AGENTS.md`, `boost.json`, `.mcp.json`, `.agents/`, `.claude/`, `.cursor/` | Lignes directrices, skills et serveur MCP. Le déploiement installe sans les dépendances de développement : **Boost n'est pas présent sur le serveur** |

**Tests ajoutés**
- Backend : `test_service_area_of_dakar_refuses_positions_outside_the_city`, `test_service_area_is_managed_by_zone_managers_only`. Le test de purge vérifie la valeur de 90 jours et la conservation d'une livraison récente.
- Vitest : « explique une position hors du périmètre desservi ».

**Mise en préproduction (18/11/2026), vérifiée :**
- Déploiement de `89b138e` avec sauvegarde chiffrée préalable. Le `deploy.sh` du commit déployé a été exécuté (correctif `chgrp`).
- Migration `2026_11_18_090000` jouée.
- Snippet nginx installé ; `nginx -t` réussi, puis rechargement.
- `.env` (copie de sauvegarde dans `/root`) : `MAPS_TILES_URL`, attribution, `MAPS_GEOCODER=none`, `MAPS_POSITION_RETENTION_DAYS=90`.
- `mk:set-service-area database/data/ville-de-dakar.geojson`.

| Contrôle | Résultat |
|---|---|
| `/health`, accueil | 200 |
| `/api/v1/config` | tuiles configurées, périmètre présent, géocodage désactivé |
| `/geo/resolve` | Mermoz et Parcelles Assainies livrables ; Pikine et Gorée hors périmètre |
| CSP | `img-src` contient `https://api.maptiler.com` |
| Purge | `--dry-run` : 0 commande ; planifiée à 03:35 |
| Tuile MapTiler avec l'origine du site | **403** : origine à autoriser dans le compte MapTiler |

Le test de fumée du script (étape 9/9) a renvoyé 401, faute de `SMOKE_AUTH` (préproduction protégée par mot de passe). Les mêmes contrôles ont été refaits à la main avec les identifiants du serveur.

À l'avenir, lancer le script avec `SMOKE_AUTH="mkdelices:$(cat /etc/mk-delices/staging_access_password)"`.
