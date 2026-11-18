# 06 — Rapport de tests

**Pour :** équipe technique et direction de MK Délices.
**Date d'exécution :** 18/11/2026.

**Environnement :**
- poste de développement Windows (Laragon) ;
- PHP 8.5.12, PostgreSQL 18 (base `mk_delices_test` pour PHPUnit, base de démonstration `mk_delices` pour l'E2E) ;
- Node 22.23 ;
- Playwright avec Microsoft Edge, projets « mobile » (Pixel 7, 360 × 780) et « desktop ».

Tous les résultats ci-dessous ont été **réellement exécutés**.

## 1. Résultats globaux

| Suite | Commande | Avant l'audit | Après corrections |
|---|---|---|---|
| Backend PHPUnit | `php artisan test` | 173/173 | **179/179** (1 914 assertions, 205 s), après le périmètre desservi |
| dont `GeolocationTest` | `--filter=GeolocationTest` | 21/21 | **27/27** |
| Style PHP | `vendor/bin/pint --test` | non exécuté | Conforme, après correction de l'ordre des imports dans `routes/api.php` |
| Frontend Vitest | `npm test` | 19/19 | **20/20** (hors zone étendu, hors périmètre ajouté) |
| ESLint, TypeScript, build Vite | `npm run lint`, `tsc -b`, `npm run build` | OK | **OK** |
| E2E géolocalisation | `playwright test e2e/geolocalisation.spec.ts` | n'existait pas | **7/7**, quatre exécutions (dont une après le périmètre desservi) |
| E2E suite complète | `playwright test` | 49 (campagne précédente) | **57/57** (11,2 min), avant l'ajout du périmètre (non rejouée ensuite) |

## 2. Défaut découvert par les tests

**Constat.** Le premier passage du nouveau test E2E « position autorisée » a échoué 2 fois sur 6 exécutions. La réponse du serveur était :
`409 total_changed` (total attendu périmé ; serveur : 6 500 FCFA).

**Cause.** Le formulaire pouvait être validé pendant le recalcul du devis qui suit le choix de la zone. Le total envoyé était l'ancien.

**Correction (A-02).** Le bouton est désactivé et le total masqué pendant le recalcul. Ensuite : 7/7 sur trois exécutions, puis suite complète verte.

Le serveur avait, lui, correctement refusé de facturer un montant non vu par le client.

## 3. Matrice des scénarios

| Scénario | Résultat attendu | Preuve | Résultat |
|---|---|---|---|
| Client accepte la géolocalisation | Position détectée, confirmable | E2E « position détectée sur demande, confirmée, puis transmise » (mobile et bureau) ; Vitest « ne demande la position qu'au clic, puis exige une confirmation » | ✅ |
| Client refuse la géolocalisation | Saisie manuelle disponible | E2E « le refus est expliqué et la commande reste possible » ; Vitest « explique le refus » | ✅ |
| Adresse sélectionnée | Adresse et coordonnées cohérentes | E2E : la requête de commande contient `lat`, `lng`, `location_source=gps`, `accuracy_m` ; PHPUnit `test_position_determines_zone_and_fee…` (copie dans `address_snapshot`) | ✅ |
| Adresse dans une zone desservie | Livraison autorisée | PHPUnit `test_resolve_returns_zone…`, `test_quote_with_position_uses_computed_zone…` | ✅ |
| Adresse hors de la ville de Dakar (périmètre desservi) | Refus explicite | PHPUnit `test_service_area_of_dakar_refuses_positions_outside_the_city` (Pikine et Gorée refusés, Mermoz accepté, saisie manuelle sans position toujours possible) ; Vitest « hors du périmètre desservi » | ✅ |
| Adresse hors zone | Message explicite | PHPUnit `test_position_outside_every_drawn_zone_is_refused` (422 `out_of_zone`) ; Vitest « refuse une position hors zone » (message, lien WhatsApp, retrait) ; E2E « hors de la région de Dakar » (422 `location_out_of_region`) | ✅ |
| Modification de l'adresse | Frais recalculés | PHPUnit `test_quote_with_position…` ; interface : clé du devis liée à la zone et à la position, validation bloquée pendant le recalcul (E2E) | ✅ |
| Manipulation du tarif | Rejet côté serveur | PHPUnit `test_position_determines_zone_and_fee_whatever_the_declared_zone` (zone moins chère déclarée, ignorée) ; `test_changed_total_is_reported_instead_of_charged` (409) ; aucun montant accepté du client | ✅ |
| Service cartographique indisponible | Alternative fonctionnelle | PHPUnit `test_resolve_suggests_district_from_provider_and_degrades_on_failure` (quota 429 → `unavailable`, zone toujours rendue) ; mode sans clé : tous les E2E | ✅ |
| Client consulte sa commande | Informations exactes | E2E existants (suivi, paiement) ; la page publique n'expose que zone et quartier (`PublicOrderResource`) | ✅ |
| Administrateur modifie un tarif | Nouveau tarif appliqué | PHPUnit `test_zone_without_area_keeps_unchanged_behaviour_for_existing_orders` (anciennes commandes figées) ; suites existantes des zones et du devis | ✅ |
| Accès non autorisé aux positions | Refus | PHPUnit `test_courier_sees_position_only_after_acceptance` ; `test_zone_manager_saves_area…` (livreur : 403) ; `test_single_default_address…` (autre client : 404) ; `test_stats_by_zone_are_aggregated_without_positions` ; `test_public_config_exposes_map_settings_without_secret` | ✅ |
| Affichage smartphone | Responsive | E2E projet « mobile » 360 px, `noHorizontalOverflow` sur le checkout avec le sélecteur de position | ✅ |

## 4. Tests complémentaires

| Domaine | Tests |
|---|---|
| Unitaire géométrie | Point dans polygone (trous, multipolygone) ; normalisation (fermeture, formes invalides, hors Dakar) |
| Règles de zone | Chevauchement (plus petit ordre) ; zone déclarée sans contour ; sans aucun contour ; sans position |
| Carnet d'adresses | Position et zone recalculée ; hors zone refusée ; **adresse par défaut unique, relais à la suppression, modification avec position, isolement entre clients** |
| API | **Coordonnée incomplète → 422** ; **filtres admin `zone_id` (UUID, `none`, valeur invalide → 422) et `zone_source`** |
| Équipe | Modification par le commercial : position du client conservée |
| Conservation | **Purge désactivée par défaut ; `--dry-run` sans effet ; purge effective en gardant le texte de l'adresse ; statistiques inchangées** |
| Géocodage | Région limitée (résultat parisien filtré, `country=sn`, `bbox`) ; clé jamais exposée |

En gras : tests ajoutés par cet audit.

## 5. Non testé, et pourquoi

| Élément | Raison | Comment le vérifier |
|---|---|---|
| Fond de carte réel, éditeur de contour à la souris | Aucune clé de tuiles fournie | Après configuration (07 § 2.5), contrôle manuel en préproduction |
| Géocodage MapTiler réel | Aucune clé ; seules des réponses simulées (`Http::fake`) au format documenté ont été testées | 07 § 2.3 |
| GPS de vrais téléphones à Dakar | Position simulée par Playwright | Campagne terrain (04, actions restantes) |
| Préproduction | Rien n'est déployé ni commité | Après accord, rejouer les E2E sur la préproduction |
| Purge planifiée par cron | Commande testée directement ; planification déclarée dans `routes/console.php` | `php artisan schedule:list` sur le serveur |
