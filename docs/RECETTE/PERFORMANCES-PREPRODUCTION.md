# Mesures de performance — préproduction (17/11/2026)

**Pour :** l'équipe projet. **Exigence :** NFR-002 et PRD — pages catalogue affichées en moins de 3 s (95e percentile) sur un réseau mobile représentatif ; disponibilité 99,5 %.
**Serveur :** VPS Hostinger, 1 processeur, 3,8 Go de mémoire, Ubuntu 26.04, PHP 8.5 (OPcache), PostgreSQL 18, Nginx (HTTP/2, gzip).

## 1. Capacité du serveur (mesurée sur le serveur, `ab`)

| Point d'accès | Rythme d'un utilisateur : médiane / p95 | 10 visiteurs simultanés | 25 visiteurs simultanés |
|---|---|---|---|
| API catalogue (12 produits) | 30 ms / 37 ms | — | — |
| API fiche produit | 31 ms / 41 ms | — | — |
| API paramètres, contenus, zones | 22–39 ms / 31–67 ms | — | — |
| Page d'accueil (HTML pré-rendu SEO) | — | 50 pages/s, médiane 198 ms, p95 227 ms | 49 pages/s, médiane 508 ms, p95 563 ms |
| Page fiche produit (HTML pré-rendu SEO) | — | 40 pages/s, médiane 252 ms, p95 291 ms | 39 pages/s, médiane 640 ms, p95 688 ms |

**Lecture :** aucune erreur ; le serveur absorbe environ **40 à 50 pages par seconde**, soit plusieurs centaines de visiteurs actifs en même temps, très au-delà du trafic attendu au lancement. Les tests massifs sur l'API depuis une seule adresse ont été (volontairement) bloqués par la limitation de débit : comportement attendu, chaque client réel disposant de son propre quota.

## 2. Poids des pages

Accueil, boutique et fiche produit : **≈ 335 Ko transférés** au premier chargement (dont ≈ 160 Ko de JavaScript et CSS compressés, chargés en parallèle via HTTP/2), puis cache navigateur d'un an pour les fichiers JS/CSS.

## 3. Affichage sur téléphone : mesure à refaire dans de bonnes conditions

Les mesures d'affichage faites depuis le poste de développement **ne sont pas fiables** et ne sont donc pas retenues :
- l'antivirus du poste (Kaspersky) **intercepte les connexions HTTPS** et injecte ses propres scripts dans chaque page ;
- la protection par mot de passe de la préproduction ajoute un aller-retour réseau supplémentaire à chaque fichier (refus 401 puis nouvel envoi avec les identifiants), ce qui pénalise fortement les mesures sur réseau à forte latence.

**Méthode retenue :**
1. **Pendant la recette** (test R-01) : sur un téléphone ordinaire, en 4G à Dakar, noter le temps d'apparition de l'accueil, de la boutique et d'une fiche produit (5 essais chacun, navigation privée pour partir sans cache).
2. **À l'ouverture** (site public, sans mot de passe) : mesure par Google PageSpeed Insights, profil mobile, sur les mêmes pages ; objectif « LCP < 3 s ».

## 4. Améliorations possibles si l'objectif n'est pas atteint

| Piste | Gain attendu | Effort |
|---|---|---|
| Héberger les polices sur le site au lieu de Google Fonts | Supprime 2 connexions externes (≈ 0,3–0,6 s sur mobile à forte latence) | Faible |
| Mettre en cache le HTML pré-rendu (accueil, fiches) | Réponse serveur ≈ 30 ms au lieu de ≈ 200 ms | Faible |
| Serveur plus proche de Dakar ou CDN | Latence réseau réduite | Selon offre d'hébergement |
