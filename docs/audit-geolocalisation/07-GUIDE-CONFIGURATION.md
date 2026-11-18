# 07 — Guide de configuration : cartes et géolocalisation

**Pour :** l'administrateur technique du serveur.
**Date :** 18/11/2026.

Aucune clé n'est fournie ni inventée ici. Sans configuration, l'application fonctionne en **mode minimal** :
- bouton « Utiliser ma position » ;
- saisie manuelle ;
- import de contours en GeoJSON.

## 1. Variables d'environnement (`backend/.env`)

| Variable | Défaut | Rôle |
|---|---|---|
| `MAPS_TILES_URL` | vide | Modèle d'URL des tuiles raster `{z}/{x}/{y}` avec la clé **navigateur**. Vide = pas de carte |
| `MAPS_TILES_ATTRIBUTION` | `© OpenStreetMap contributors` | Mention légale affichée sur la carte, **obligatoire** (ODbL). Avec MapTiler : `© MapTiler © OpenStreetMap contributors` |
| `MAPS_GEOCODER` | `none` | `maptiler` pour activer la recherche et le quartier suggéré |
| `MAPS_GEOCODER_KEY` | vide | Clé **serveur** (jamais envoyée au navigateur) |
| `MAPS_GEOCODER_TIMEOUT` | `4` | Délai maximal en secondes, puis repli sur la saisie manuelle |
| `MAPS_GEOCODER_CACHE_SECONDS` | `0` | Cache des réponses. Laisser à 0 tant que les conditions du fournisseur n'autorisent pas le stockage |
| `MAPS_POSITION_RETENTION_DAYS` | `90` | Durée de conservation des positions dans les commandes terminées, en jours après le créneau. 0 = jamais purgées |

Les bornes de la région desservie et le centre de la carte se règlent dans `config/mkdelices.php`, clés `maps.bounds` et `maps.default_center`. Région actuelle : lat 14,0 à 15,2 ; lng −17,8 à −16,8.

Après toute modification : `php artisan config:cache`.

## 2. Mise en service de MapTiler

1. Créer un compte au nom de MK Délices. Choisir l'offre selon le trafic attendu, et **relire les tarifs et conditions le jour de la souscription**.
2. Créer **deux clés** :
   - **Clé navigateur** : restreinte au domaine du site (« Allowed HTTP origins » : domaine de production et de préproduction). Elle sert dans `MAPS_TILES_URL`. Exemple de forme : `https://api.maptiler.com/maps/streets-v2/256/{z}/{x}/{y}.png?key=<CLE_NAVIGATEUR>`.
   - **Clé serveur** : sans restriction d'origine, gardée secrète. Elle sert dans `MAPS_GEOCODER_KEY`, avec `MAPS_GEOCODER=maptiler`.
3. Vérifier le format des réponses de géocodage sur 5 adresses connues de Dakar (Mermoz, Sacré-Cœur 3, Plateau, Ouakam, Parcelles Assainies). Si le quartier n'est pas extrait, ajuster `MapTilerGeocoder::DISTRICT_LEVELS`.
4. **CSP nginx** :
   - le snippet `deploy/nginx/snippets/mk-security-headers.conf` autorise déjà `https://api.maptiler.com` dans `img-src` ;
   - le copier dans `/etc/nginx/snippets/`, puis `nginx -t && systemctl reload nginx` ;
   - autre fournisseur : remplacer ce domaine.
5. Contrôle : ouvrir `/commander`, puis « Choisir sur la carte ». Les tuiles s'affichent, sans erreur CSP dans la console du navigateur.

**Interdit en production :** les serveurs publics `tile.openstreetmap.org` et `nominatim.openstreetmap.org`. Leurs politiques d'usage l'excluent.

## 3. Contours des zones

Dans le back-office : **Opérations, Zones, Modifier**.
- Cliquer sur la carte pour placer les points ; glisser un point pour le déplacer.
- Ou importer un `Polygon` GeoJSON en coordonnées `[longitude, latitude]`.

Contraintes vérifiées par le serveur :
- tous les points dans la région de Dakar ;
- au moins 3 points distincts ;
- 500 points au plus.

Effets du contour :
- dès qu'**une** zone a un contour, une position hors de tous les contours ne peut plus commander en ligne, sauf si le client choisit une zone encore sans contour ;
- **dessiner toutes les zones** avant d'annoncer la vérification automatique ;
- en cas de chevauchement, le plus petit « Ordre d'affichage » l'emporte.

## 3 bis. Périmètre desservi (ville de Dakar)

Le périmètre dit où MK Délices livre. Une position hors du contour ne peut pas commander en ligne.

**Définition actuelle (18/11/2026)** : `backend/database/data/ville-de-dakar.geojson`.
- Source : limite administrative de la ville de Dakar, relation OpenStreetMap 12982139.
- Partie continentale seule : Gorée et les îles de la Madeleine sont exclues, faute de livraison par la route.
- 284 points, précision d'environ 11 m.
- Contrôlé sur 13 lieux : Plateau, Médina, Mermoz, Ouakam, Yoff, Parcelles Assainies, Grand Yoff et HLM sont dedans ; Pikine, Guédiawaye, Rufisque, Thiaroye et Gorée sont dehors.

```bash
php artisan mk:set-service-area database/data/ville-de-dakar.geojson   # charger (journalisé)
php artisan mk:set-service-area --clear                                # supprimer
```

Il se modifie aussi dans le back-office : **Opérations, Zones, carte « Périmètre desservi »** (dessin sur la carte ou import GeoJSON). Pour étendre la livraison à Pikine ou Guédiawaye, importer un nouveau contour.

**Licence** : données © contributeurs d'OpenStreetMap, sous licence ODbL. L'attribution est déjà portée par `MAPS_TILES_ATTRIBUTION` sur la carte.

## 4. Purge des positions

```bash
php artisan mk:purge-delivery-positions --dry-run   # combien de commandes seraient concernées
php artisan mk:purge-delivery-positions             # effacement (irréversible, journalisé)
```

La purge est planifiée chaque nuit à 03:35 (heure de Dakar), avec une conservation de **90 jours** après le créneau (décision du 18/11/2026).

**Pourquoi 90 jours :**
- la position ne sert qu'à livrer, puis éventuellement à traiter une réclamation ou un remboursement ;
- la CNIL, autorité de référence souvent suivie en Afrique francophone, limite à **2 mois** l'historique des données de localisation, sauf finalité justifiée ;
- 90 jours couvrent ce délai et une marge raisonnable de réclamation.

Après la purge, l'adresse textuelle (quartier, rue, repère) reste attachée à la commande et à la facture. Les positions des adresses du carnet restent, elles, sous le contrôle du client, qui peut les modifier ou les supprimer.

## 5. Limites de débit

Limiteur `geo` : **30 requêtes par minute** par session ou adresse IP, pour `/geo/resolve` et `/geo/search`. Il protège le quota du fournisseur. À ajuster dans `AppServiceProvider` si les journaux montrent des refus 429 légitimes.

## 6. Données personnelles

- **Politique de confidentialité, à faire valider par le conseil juridique.** Elle doit indiquer :
  - la collecte facultative de la position de livraison ;
  - sa finalité (trouver l'adresse) ;
  - ses destinataires (livreur affecté, pendant la mission) ;
  - la durée de conservation.
- Seules les tuiles de carte sont demandées au fournisseur **par le navigateur** : le fournisseur voit la zone affichée, pas l'identité du client.
- Le texte de référence au Sénégal reste la loi n° 2008-12. Déclaration ou autorisation auprès de la CDP selon l'avis du conseil.
