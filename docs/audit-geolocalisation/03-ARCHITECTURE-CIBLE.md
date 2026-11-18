# 03 — Architecture cible et choix

**Pour :** équipe technique.
**Date :** 18/11/2026.

## 1. Principes

1. **La position aide, elle n'est jamais obligatoire.** Sans GPS, sans carte ou sans géocodage, la saisie manuelle (zone, quartier, rue, repère) suffit pour commander.
2. **Le serveur décide.** Zone, frais, minimum et total sont calculés par le backend. Le client transmet une position, jamais un prix.
3. **Un seul fournisseur cartographique, interchangeable.** Il est caché derrière l'interface `Geocoder`.
4. **Collecte minimale.** Position demandée sur action du client, confirmée par lui, conservée seulement avec la commande ou l'adresse qu'il valide.

## 2. Composants

| Couche | Composant | Rôle |
|---|---|---|
| Domaine | `GeoPoint` | Position validée (bornes `mkdelices.maps.bounds`), haversine |
| | `ZoneArea` | Validation et normalisation GeoJSON ; point dans polygone (trous, multipolygones) |
| | `ZoneLocator` | Règle de détermination de la zone (§ 3) |
| | `Geocoder` → `MapTilerGeocoder` / `NullGeocoder` | Géocodage côté serveur ; erreur → `GeocoderUnavailable` → repli |
| API | `POST /api/v1/geo/resolve` | Zone calculée et adresse suggérée pour un point (rien n'est stocké) |
| | `GET /api/v1/geo/search` | Suggestions limitées à la région |
| | `POST /checkout/quote` (`lat`, `lng`) | Devis avec la zone calculée |
| | `POST /checkout/orders` (`delivery.lat/lng/location_source/accuracy_m`, `expected_total_xof`) | Commande |
| | `/account/addresses` (+ `POST {id}/default`) | Carnet d'adresses avec position |
| | `GET /admin/orders?zone_id=&zone_source=` | Filtres logistiques |
| | `GET /admin/stats` → `by_zone` | Agrégats par zone |
| Frontend | `features/maps/LocationPicker` | GPS, recherche, carte (chargée à la demande), confirmation |
| | `features/maps/LeafletMaps` | `PinMap` (client) et `AreaEditor` (back-office), Leaflet 1.9.4, environ 45 Ko gzip, chargé à la demande |
| Exploitation | `mk:purge-delivery-positions` | Purge des positions des commandes terminées (90 jours) |
| | `mk:set-service-area {fichier}` / `--clear` | Charge le périmètre desservi depuis un GeoJSON (journalisé) |
| API admin | `GET`/`PUT /admin/delivery/service-area` | Périmètre desservi (`zones.view` / `zones.manage`), validé par `ZoneArea` |
| Données | `backend/database/data/ville-de-dakar.geojson` | Contour de la ville de Dakar, partie continentale (OSM, ODbL) |

## 3. Règle de détermination de la zone (`ZoneLocator`)

| Situation | Zone retenue | `zone_source` |
|---|---|---|
| **Périmètre desservi défini** (paramètre `delivery.service_area`, aujourd'hui la ville de Dakar) et position **hors** du périmètre | Refus `out_of_zone` (422), quelle que soit la zone choisie | — |
| Aucune zone active n'a de contour | Zone choisie par le client | `declared` |
| Pas de position | Zone choisie par le client | `declared` |
| Position dans un ou plusieurs contours | Plus petit « ordre d'affichage » (chevauchements, frontières) | `position` |
| Position hors de tout contour, zone choisie **sans** contour | Zone choisie, à vérifier par le commercial | `declared` |
| Position hors de tout contour, zone choisie **avec** contour | Refus `out_of_zone` (422) | — |
| Modification par l'équipe | Zone choisie par l'équipe (décision humaine, journalisée) | `staff` |

**Périmètre desservi et zones tarifaires.** Ce sont deux notions distinctes :
- le **périmètre** dit *où* MK Délices livre : un seul contour, décision du 18/11/2026, « nous débutons avec la ville de Dakar » ;
- les **zones** disent *à quel tarif*.

Tant que les 4 zones tarifaires n'ont pas de contour, une position dans Dakar garde la zone choisie par le client (`declared`, vérifiée par le commercial). Une position hors de Dakar est refusée en ligne.

`declared` signifie « non vérifié » : le back-office l'affiche en alerte et permet de filtrer ces commandes.

**Stockage :** jsonb plutôt que PostGIS.
- Le volume est faible (moins de 20 polygones de 500 sommets au plus).
- La CI n'a pas PostGIS ; la production non plus.
- Le test est déterministe et unitaire.

Revoir ce choix au-delà d'environ 200 zones, ou si des requêtes spatiales sur les commandes deviennent nécessaires (carte de chaleur, par exemple).

## 4. Frais : politique retenue

**Forfait par zone (DEC-GEO-02)** : frais, minimum, rémunération du livreur, délai supplémentaire. Tout est configurable dans le back-office.

Pas de tarif au kilomètre :
- il faudrait un service de routage (distance **routière**, payant) ;
- il faudrait la position de l'atelier, non fournie ;
- MK Délices n'a pas décidé de ce modèle.

La distance à vol d'oiseau (`GeoPoint::distanceTo`) n'est jamais utilisée pour un prix.

Pour ajouter plus tard une politique (kilométrique, gratuité au-delà d'un seuil) : l'intégrer dans `QuoteService`, seul point de calcul, et l'activer par configuration.

## 5. Suivi de livraison

- **Retenu :** suivi par statuts (commande et mission), notifications, lien d'itinéraire précis pour le livreur.
- **Non retenu :** suivi GPS en temps réel. Sur un site web, la position cesse d'être transmise téléphone verrouillé. Le suivi ne deviendrait fiable qu'avec une application native, et devrait alors respecter ces garanties :
  - activation seulement entre « enlevée » et « livrée » ;
  - une position toutes les 30 à 60 s ;
  - purge sous 24 h ;
  - client limité à « en approche » ;
  - consentement du livreur.

## 6. Fournisseurs cartographiques (DEC-GEO-01)

| Critère | MapTiler (OSM) — **retenu** | Google Maps Platform | Serveurs publics OSM / Nominatim |
|---|---|---|---|
| Coût | Offre gratuite limitée, puis abonnement mensuel | Paiement à l'usage, plafonds gratuits par produit | Gratuit, mais **interdit** pour un usage commercial intensif |
| Licence, cache | Données ODbL, attribution obligatoire ; cache à vérifier dans les conditions | Cache très restreint ; affichage sur carte Google souvent exigé | Politique d'usage stricte (1 requête/s, pas de saisie semi-automatique) |
| Couverture de Dakar | OSM : bonne sur les grands axes et quartiers, inégale sur les numéros | Meilleure recherche d'adresses et de lieux | Identique à OSM |
| Confidentialité | Appels de géocodage par **notre serveur** (aucune donnée client chez le fournisseur hors tuiles) | Idem si appel serveur | — |
| Intégration | Tuiles standard (Leaflet), API géocodage simple | SDK propriétaire | — |

**Décision :** MapTiler, un seul fournisseur pour les tuiles et le géocodage, derrière `Geocoder`. Changer de fournisseur :
- écrire une classe `Geocoder` ;
- changer `MAPS_TILES_URL` ;
- changer le domaine dans la CSP.

Les conditions et tarifs sont à **revérifier à la souscription** (voir 07).

## 7. Indisponibilité des services

| Panne | Comportement |
|---|---|
| GPS refusé, indisponible, trop lent | Message explicite, saisie manuelle |
| Fournisseur de géocodage (délai de 4 s, quota, clé refusée) | `geocoding: "unavailable"`, pas de suggestion ; zone et commande inchangées |
| Tuiles indisponibles | Carte grise ; GPS et saisie manuelle restent possibles |
| `/geo/resolve` en erreur ou limité | « Vérification impossible… saisissez votre zone » |
| Contours absents | Mode « zone déclarée », vérifiée par le commercial |
