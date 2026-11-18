# 09 — Rapport final : géolocalisation de MK Délices

**Pour :** direction de MK Délices et équipe technique.
**Date :** 18/11/2026.

## 1. En bref

La géolocalisation est intégrée **seulement là où elle sert** :
- trouver l'adresse du client ;
- vérifier la zone et facturer les bons frais ;
- guider le livreur ;
- piloter les livraisons par zone.

**Elle n'est jamais obligatoire.** Un client sans GPS commande comme avant.

**Le serveur reste seul juge des frais.** L'audit a trouvé et corrigé 12 anomalies, dont deux sérieuses :
- la carte serait restée grise en production (en-têtes de sécurité) ;
- un client pouvait valider un total en cours de recalcul.

**Décisions de MK Délices du 18/11/2026, mises en œuvre :**
- livraison limitée à la **ville de Dakar** (périmètre desservi, contour officiel OSM, sans Gorée) ;
- conservation des positions : **90 jours** ;
- installation de Laravel Boost ;
- commit et déploiement en préproduction.

**Reste attendu de MK Délices :** les **deux clés MapTiler** (le compte est ouvert) pour activer la carte et la recherche d'adresse.

## 2. Bilan

| | Contenu |
|---|---|
| **Existait initialement** | Socle livraison commité : zones forfaitaires, devis serveur, adresse figée, livreurs, code de remise. Plus une implémentation de géolocalisation **non commitée** de la veille : position client, zones dessinées, zone déduite côté serveur, géocodage MapTiler inactif sans clé, statistiques par zone |
| **Défectueux ou manquant** | A-01 à A-12 ([01](01-AUDIT-EXISTANT.md) § 4) : CSP des tuiles, total périmé, zone affichée ≠ facturée, adresse par défaut, modification d'adresse, filtres admin, libellé, bornes, rafales d'appels, message hors zone, conservation, documentation |
| **Corrigé** | A-01, A-02, A-03, A-07, A-08, A-09, A-10, A-12 |
| **Nouvellement implémenté** | Adresse par défaut (API et interface, présélection au checkout) ; modification d'adresse ; filtres admin « Zone » et « Zone vérifiée ? » ; purge des positions `mk:purge-delivery-positions` (désactivée par défaut) ; test E2E de géolocalisation |
| **Effectivement testé** | Voir § 3 et [06](06-RAPPORT-DE-TESTS.md) |
| **Reste à faire** | § 5 |

## 3. Tests exécutés le 18/11/2026

| Suite | Résultat |
|---|---|
| Backend PHPUnit (suite complète) | 179/179 (1 914 assertions) ; Pint conforme |
| dont géolocalisation (`GeolocationTest`) | 27/27 |
| Frontend Vitest | 20/20 |
| Lint ESLint, typage TypeScript, build | sans erreur |
| E2E Playwright, suite complète (Edge, mobile 360 px et bureau) | 57/57 |
| dont géolocalisation (`e2e/geolocalisation.spec.ts`) | 7/7, trois exécutions consécutives |

**Non testé** (impossible dans cet environnement) :
- carte et géocodage réels : pas de clé fournisseur ;
- GPS sur de vrais téléphones à Dakar ;
- préproduction : rien n'est déployé.

## 4. Conformité aux critères de réussite

| Critère | État |
|---|---|
| Géolocalisation seulement là où elle est justifiée | ✅ Checkout, carnet d'adresses, zones, mission du livreur, statistiques. Pas de suivi GPS ni de tarif kilométrique |
| Commande avec ou sans GPS | ✅ Testé en E2E (autorisé et refusé) |
| Adresses fiables et modifiables | ✅ Modification, adresse par défaut, position facultative |
| Zones et frais administrables | ✅ Back-office, sans code ; contour sur carte ou GeoJSON |
| Calculs financiers contrôlés côté serveur | ✅ Zone recalculée, montant attendu, rejet de la manipulation (testé) |
| Cohérence frontend, backend, API | ✅ Après A-02 et A-03 |
| Données personnelles protégées | ✅ Accès restreints testés ; 🟡 durée de conservation à fixer |
| Parcours de commande et de livraison | ✅ Suite E2E complète verte |
| Anomalies non résolues documentées | ✅ § 5 |
| Traçabilité | ✅ Journal d'audit, `DEV-JOURNAL.md`, ce dossier |

## 5. Limites et recommandations

1. **MapTiler** : créer dans le compte une clé navigateur restreinte au domaine et une clé serveur, et les transmettre de façon sécurisée. Puis vérifier les quartiers renvoyés à Dakar. Voir [07](07-GUIDE-CONFIGURATION.md).
2. **Zones tarifaires** : la livraison est limitée à Dakar. À l'intérieur, le client choisit sa zone (1 à 4) et le commercial la vérifie (filtre « Zone vérifiée ? : non »). Dessiner les 4 zones rendrait ce choix automatique.
3. **Politique de confidentialité** : y mentionner la position facultative et sa conservation de 90 jours, lors de la revue juridique déjà prévue.
4. **Étendre le périmètre** (Pikine, Guédiawaye…) : importer un nouveau contour dans Zones, sans code.
5. **Tester sur de vrais téléphones** : Android d'entrée de gamme, iPhone, en intérieur et en 3G.
6. **Non retenus, à rouvrir seulement sur décision** :
   - tarif kilométrique (routage payant, adresse de l'atelier) ;
   - livraison gratuite au-delà d'un seuil ;
   - suivi GPS du livreur (application native nécessaire) ;
   - PostGIS (au-delà d'environ 200 zones).
7. **Laravel Boost** : installé le 18/11/2026, en développement uniquement. Son serveur MCP lance `php` : sur ce poste, `php` désigne PHP 8.3, trop ancien. Il faut placer PHP 8.5 de Laragon en tête du PATH pour que le MCP démarre.

## 6. Cadre légal (vérifié le 18/11/2026)

La **loi n° 2008-12 du 25 janvier 2008** sur la protection des données à caractère personnel reste le texte en vigueur au Sénégal, sous le contrôle de la CDP. Un projet de remplacement existe depuis 2019 ; la CDP évoquait en 2025 une modernisation du cadre, mais aucune adoption n'a été trouvée. À reconfirmer auprès du conseil juridique.

Sources :
- [CIPESA : révision de la loi](https://cipesa.org/2020/01/le-senegal-va-reviser-la-loi-sur-la-protection-des-donnees-personnelles/)
- [CDP : avis trimestriel 01-2025](https://www.cdp.sn/actualites/d10d9333-cd65-4deb-bff6-c2b3b770661c)
- [Osiris : qui a le droit d'utiliser nos données](https://osiris.sn/cdp-qui-a-reellement-le-droit-d-utiliser-nos-donnees-au-senegal.html)
- [IFEX : remplacement de la loi annoncé](https://ifex.org/senegal-set-to-replace-data-protection-law/)

Fournisseurs consultés le 17/11/2026 :
- [tarifs MapTiler](https://www.maptiler.com/cloud/pricing/)
- [politique des tuiles OSM](https://operations.osmfoundation.org/policies/tiles/)
- [politique Nominatim](https://operations.osmfoundation.org/policies/nominatim/)
- [facturation Google Maps Platform](https://developers.google.com/maps/billing-and-pricing/faq?hl=en)
