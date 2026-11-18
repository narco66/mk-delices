# 02 — Analyse des écarts

**Pour :** équipe technique et direction de MK Délices.
**Date :** 18/11/2026.
**Référence :** exigences du cahier de mission (§ 3 à 5), comparées à l'inventaire [01-AUDIT-EXISTANT.md](01-AUDIT-EXISTANT.md).

## 1. Écarts corrigés ou comblés pendant cet audit

| ID | Exigence | Écart | Traitement |
|---|---|---|---|
| A-01 | Carte utilisable | CSP bloquant les tuiles | `img-src` ouvert au **seul** domaine `https://api.maptiler.com` |
| A-02 | Frais affichés = frais facturés | Validation possible pendant le recalcul | Bouton « Confirmer » bloqué tant que le devis n'est pas à jour ; total masqué (« … ») |
| A-03 | Cohérence adresse ↔ zone ↔ montant | Zone affichée ≠ zone calculée | La zone affichée suit toujours celle que le serveur calcule depuis la position |
| A-04 | Adresse par défaut | Absente | Une seule par client ; action « Définir par défaut » ; la première adresse l'est d'office ; relais si elle est supprimée ; présélection au checkout |
| A-05 | Adresses modifiables | Ajout et suppression seulement | Modification (position comprise) et champ « Rue / villa » |
| A-06 | Commandes par zone, hors zone | Aucun filtre | Filtres « Zone » et « Zone vérifiée ? » (API et back-office) |
| A-07 | Traçabilité | Libellé faux | « Position saisie par l'équipe » |
| A-08 | Contrôle serveur | Bornes en double, coordonnée orpheline ignorée | Bornes uniques (`GeoPoint`) ; `lat` sans `lng` rejetée (422) |
| A-09 | Coût maîtrisé | Un appel par clic | Anti-rafale de 400 ms sur les déplacements du repère ; limiteur porté à 30 par minute |
| A-10 | Message hors zone explicite | Pas d'alternative | Lien WhatsApp prérempli et rappel du retrait (si activé) |
| A-11 | Conservation limitée | Aucune | Commande `mk:purge-delivery-positions`, planifiée chaque nuit, **désactivée par défaut** (durée à fixer par MK Délices) |
| A-12 | Documentation | Références mortes | Commentaires repointés vers ce dossier ; journal mis à jour |

## 2. Écarts conservés, de façon justifiée

| Exigence | Décision | Justification |
|---|---|---|
| Distance routière, tarif kilométrique, distance maximale | **Non implémenté** | MK Délices facture au forfait par zone (DEC-GEO-02). Un tarif au kilomètre exige un service de routage payant et la position de l'atelier, non fournie. Le contour d'une zone fait office de distance maximale |
| Suivi GPS du livreur en temps réel | **Non implémenté** | Un site web perd la position dès que le téléphone se verrouille : le suivi serait faux. Le suivi par statuts est plus fiable. Pas de surveillance permanente (exigence § 3.5) |
| PostGIS | **Non activé** | Moins de 20 zones ; le test en PHP prend moins d'une milliseconde. PostGIS imposerait une extension en CI et en production sans gain mesurable |
| Créneaux par zone | Non implémenté | Aucun besoin métier exprimé ; délai supplémentaire et minimum par zone existent déjà |
| Livraison gratuite (seuil) | Non implémenté | Politique commerciale non décidée. À ajouter dans les promotions **si** MK Délices le décide (voir 04, lot P3) |
| Carte globale des zones (vue d'ensemble) | Partielle | Les autres zones s'affichent en pointillés dans l'éditeur. Une carte de synthèse n'apporterait de valeur qu'avec des contours réels |

## 3. Écarts bloqués par une dépendance externe

| Écart | Dépendance | Effet tant qu'elle manque |
|---|---|---|
| Carte interactive (client et back-office) | Compte MapTiler et clé « navigateur » restreinte au domaine (`MAPS_TILES_URL`) | « Utiliser ma position » et saisie manuelle ; contours importables en GeoJSON |
| Recherche d'adresse, quartier suggéré | Clé serveur MapTiler (`MAPS_GEOCODER_KEY`), format de réponse à vérifier sur le compte réel | Pas de recherche ; saisie manuelle |
| Zone déduite de la position | **Contours des zones dessinés par MK Délices** | Le client choisit sa zone ; le commercial la vérifie (alerte et filtre « zone non vérifiée ») |
| Durée de conservation des positions | Décision MK Délices, après la revue juridique ou CDP déjà prévue | Positions conservées avec la commande |

## 4. Conformité : sécurité et données personnelles

| Exigence (§ 5) | État |
|---|---|
| Consentement | ✅ Demande au clic, finalité affichée (« sert uniquement à cette livraison ») |
| Collecte minimale | ✅ Une position confirmée par commande ou par adresse ; rien n'est stocké par `/geo/resolve` ; précision limitée à 6 décimales |
| HTTPS | ✅ HSTS, et l'API Geolocation exige un contexte sécurisé |
| Clés d'API | ✅ Clé serveur jamais renvoyée (testé) ; la clé de tuiles est publique **par nature**, à restreindre au domaine |
| Contrôle d'accès serveur | ✅ Coordonnées visibles par le livreur seulement pendant sa mission ; agrégats sans position (testé) |
| Fraude sur les coordonnées ou le tarif | ✅ Zone recalculée par le serveur ; montant attendu vérifié ; bornes de région |
| Conservation | 🟡 Mécanisme livré ; durée à décider |
| Traçabilité admin | ✅ Journal d'audit des zones (contour compris) et des modifications de commande ; purge journalisée (`geo.positions_purged`) |
| Cadre sénégalais | La **loi n° 2008-12 du 25 janvier 2008** reste le texte en vigueur (CDP). Une réforme est en préparation, sans adoption trouvée au 18/11/2026. Sources dans [09-RAPPORT-FINAL.md](09-RAPPORT-FINAL.md). La mention de la position dans la politique de confidentialité relève de la revue juridique déjà prévue |
