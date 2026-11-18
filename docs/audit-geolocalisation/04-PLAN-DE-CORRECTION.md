# 04 — Plan de correction

**Pour :** équipe technique et direction de MK Délices.
**Date :** 18/11/2026.

Priorités :
- **P1** : bloque un usage correct ou expose un risque ;
- **P2** : ergonomie ou exploitation ;
- **P3** : évolution conditionnée à une décision.

## 1. Lot réalisé le 18/11/2026

| Ordre | Action | Anomalie | Priorité | Risque de régression | Dépendances | État |
|---|---|---|---|---|---|---|
| 1 | Ouvrir `img-src` au domaine des tuiles | A-01 | P1 | Faible (images seulement) | Déployer le snippet nginx | ✅ fait, **à déployer** |
| 2 | Bloquer « Confirmer » pendant le recalcul du devis | A-02 | P1 | Faible | — | ✅ |
| 3 | La zone affichée suit la zone calculée | A-03 | P1 | Moyen (checkout) | 2 | ✅ |
| 4 | Adresse par défaut unique, action, présélection | A-04 | P2 | Faible | — | ✅ |
| 5 | Modification d'adresse, champ rue | A-05 | P2 | Faible | 4 | ✅ |
| 6 | Filtres admin zone et origine de la zone | A-06 | P2 | Faible | — | ✅ |
| 7 | Libellé `staff` | A-07 | P2 | Nul | — | ✅ |
| 8 | Bornes uniques, coordonnée orpheline rejetée | A-08 | P2 | Faible (422 dans les deux cas) | — | ✅ |
| 9 | Anti-rafale, limiteur à 30 par minute | A-09 | P2 | Faible | — | ✅ |
| 10 | Lien WhatsApp et retrait hors zone | A-10 | P2 | Nul | — | ✅ |
| 11 | Purge des positions, configurable | A-11 | P2 | Moyen (suppression) → test à blanc (`--dry-run`), puis 90 jours décidés le 18/11 | Décision de durée | ✅ |
| 12 | Documentation, références, journal | A-12 | P2 | Nul | — | ✅ |

## 2. Décisions du 18/11/2026 et lot complémentaire

| Décision de MK Délices | Mise en œuvre | État |
|---|---|---|
| Commiter et déployer | Commits sur `main`, déploiement en préproduction | ✅ (voir 05 § 6) |
| Compte MapTiler ouvert | Clés API à transmettre par MK Délices (non communiquées) | ⏳ |
| « Nous débutons avec la ville de Dakar » | **Périmètre desservi** = limite administrative de la ville de Dakar (OSM), partie continentale ; écran d'édition dans Zones | ✅ |
| Durée de conservation « selon les meilleures pratiques » | **90 jours** après le créneau (voir 07 § 4) | ✅ |
| Installer Laravel Boost | `laravel/boost` en dépendance de développement, lignes directrices, skills et serveur MCP | ✅ |

## 3. Actions restantes

| Action | Priorité | Responsable | Dépendance | Effort |
|---|---|---|---|---|
| Créer dans le compte MapTiler une clé « navigateur » restreinte au domaine et une clé serveur, et les transmettre de façon sécurisée (jamais par email en clair ni dans Git) | P1 | MK Délices | Compte ouvert | 15 min |
| Vérifier le format réel des réponses de géocodage sur le compte (quartiers de Dakar) et ajuster `MapTilerGeocoder::DISTRICT_LEVELS` | P1 | Équipe technique | Compte | 2 h |
| Dessiner, si souhaité, les contours des **4 zones tarifaires** à l'intérieur de Dakar (sinon le client choisit sa zone, contrôlée par le commercial) | P2 | MK Délices (livraisons) | Fond de carte | 1 à 2 h |
| Mentionner la position dans la politique de confidentialité (finalité, durée, destinataires : livreur et fournisseur de tuiles) | P2 | Conseil juridique | — | — |
| Tester le GPS sur de vrais téléphones à Dakar (Android d'entrée de gamme, iPhone, en intérieur) | P2 | Équipe et MK Délices | Préproduction | 1/2 journée |
| Seuil de livraison gratuite (promotion de type « livraison offerte ») | P3 | Équipe technique | Décision commerciale | 1 jour |
| Tarif kilométrique ou distance maximale | P3 | Équipe technique | Décision commerciale, adresse de l'atelier, service de routage | 3 à 5 jours |
| Suivi GPS du livreur | P3 | — | Application native ; garanties du § 5 de 03 | Lot dédié |
