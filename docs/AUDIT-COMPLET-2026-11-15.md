# Audit complet de l'application MK Délices — 15 novembre 2026

**Pour :** l'équipe projet (développement, exploitation) et la direction de MK Délices, pour décider de ce qui doit être corrigé avant la préproduction puis avant l'ouverture au public.
**Périmètre :** API Laravel (`backend/`), SPA React (`frontend/`), configuration de déploiement (`deploy/`), documentation d'exploitation. Version auditée : branche `main` + modifications locales non commitées (facturation, commandes back-office, annulation de démarrage).
**Méthode :** quatre audits indépendants en lecture seule (sécurité, logique métier et intégrité des données, frontend/UX/accessibilité, exploitation/performance), puis vérification dans le code des constats majeurs et contrôles automatiques.

## 1. Synthèse

| Indicateur | Résultat |
|---|---|
| Tests backend (PHPUnit) | 97 / 97, 1 019 assertions |
| Tests frontend (Vitest) | 9 / 9 · TypeScript strict et ESLint : 0 erreur |
| Vulnérabilités connues des dépendances | 0 (`composer audit`, `npm audit --omit=dev`) |
| Secrets dans le dépôt | aucun (`.env` ignoré, clé SSH hors dépôt) |
| Constats | **5 bloquants**, **11 élevés**, **17 moyens**, une trentaine de faibles |

**Appréciation générale.** Le socle est solide : autorisation vérifiée côté serveur sur chaque route, jetons hachés et comparés en temps constant, paiement jamais validé sur le seul retour navigateur, montants toujours recalculés côté serveur, journaux et factures immuables, verrous et contraintes SQL sur les invariants financiers, aucune injection SQL ni XSS trouvée. Les faiblesses se concentrent sur **trois zones** :
1. **les cas limites des paiements et remboursements** (dormants tant que Wave n'est pas activé, mais à corriger avant) ;
2. **l'outillage de déploiement et de sauvegarde**, qui ne fonctionnerait pas en l'état ;
3. quelques défauts d'interface, dont un bouton inopérant introduit lors de la dernière livraison.

**Verdict :** la préproduction peut être déployée après le **lot 1** ci-dessous (≈ 1 jour). L'ouverture au public exige en plus le **lot 2**.

## 2. Constats bloquants

| # | Constat | Où | Conséquence | Correction |
|---|---|---|---|---|
| B1 | **Remboursement Wave sans montant**, appelé dans une transaction avec réessais automatiques | `WaveGateway.php:77-85`, `RefundService.php:64-87` | Un geste de 2 000 FCFA peut rembourser toute la commande (à confirmer en sandbox) ; un délai réseau peut provoquer un **double remboursement** ; l'avoir ne correspond plus à l'argent rendu | Passer le montant (ou n'automatiser que le remboursement total), sortir l'appel du verrou avec un état `processing` et une clé d'idempotence, pas de réessai sur les POST |
| B2 | **Paiement confirmé après expiration locale perdu** | `PaymentService.php:176-178, 194-196` | Le client paie au moment où la session expire : la session est close « expirée » par le rapprochement, le webhook « réussi » est ignoré → **argent encaissé, commande impayée**, aucune alerte | Ne jamais clore localement un paiement que le prestataire annonce « en attente » ; traiter un succès tardif (encaissement + alerte) |
| B3 | **Les sauvegardes ne tournent jamais** | `deploy/cron/mk-delices:5`, `backup.sh:11,14`, scripts en mode 100644 | `GPG_RECIPIENT` jamais fourni, scripts non exécutables, copie hors serveur optionnelle → aucune sauvegarde, aucune alerte ; `deploy.sh` s'arrête à l'étape 1 | `chmod +x` dans git, `/etc/mk-delices/backup.env` (600) chargé par le script, `RCLONE_REMOTE` obligatoire, sonde de bon fonctionnement |
| B4 | **Déploiement ni sûr ni réversible** | `deploy.sh:15-48` | Code mis à jour avant la maintenance (site en état hybride), `trap` qui remet en ligne après un échec ou échoue après `cd`, aucun retour arrière, permissions `storage/` non gérées (erreurs 500 possibles) | Répertoires de release + lien `current`, build avant `down`, bascule atomique, retour arrière par lien, `chgrp www-data` + ACL |
| B5 | **Version de PHP incompatible** | `composer.json` (`^8.3`) vs `composer.lock` (Symfony ≥ 8.4) ; Nginx/`deploy.sh` en 8.4, développement en 8.5 | `composer install` échoue sur Ubuntu 24.04 (PHP 8.3) | `"php": "^8.4"`, version PHP-FPM paramétrée, PPA documenté |

## 3. Constats élevés

| # | Constat | Où | Correction |
|---|---|---|---|
| E1 | **Adresse IP du client falsifiable** (`trustProxies('*')` alors que Nginx est en frontal) : contournement de la limitation des tentatives de connexion (force brute), IP fausses dans le journal d'audit | `bootstrap/app.php:47` | `trustProxies(at: [])` ou IP du proxy réel ; `fastcgi_param HTTP_X_FORWARDED_FOR ""` |
| E2 | **Double encaissement non détecté** (session Wave + paiement manuel, ou frais modifiés pendant un paiement) : `paid_xof` > total, statistiques gonflées, facture bloquée | `PaymentService.php:52-70, 206, 249` ; `CheckoutService.php:225` | Détecter le trop-perçu et ouvrir un remboursement ; interdire paiement manuel / modification des frais si une session est ouverte |
| E3 | **Rejet de remboursement sans verrou** : un remboursement exécuté peut finir « rejeté », sans avoir | `RefundService.php:90-103` | Transaction + `lockForUpdate` + revérification du statut |
| E4 | **Paiement à la livraison écrasé** par une tentative de paiement en ligne abandonnée : le livreur n'a rien à encaisser (fonction actuellement désactivée) | `PaymentService.php:93`, `DeliveryService.php:60` | Ne pas modifier `payment_method` à l'ouverture de session ; montant à encaisser = total − payé |
| E5 | **« Annuler le démarrage » ne fait rien depuis la fiche commande** (régression de la dernière livraison ; fonctionne depuis l'écran Préparation) | `OrderDetailPage.tsx:351` | Ouvrir la fenêtre de motif aussi pour `cancel_preparation` + test E2E |
| E6 | **Bibliothèque de graphiques chargée par toute la boutique** (≈ 400 kB, dont le cœur React, préchargés à l'accueil) : ≈ 265 kB compressés avant le premier affichage, pénalisant en 3G/4G | `vite.config.ts:45-48` | Restreindre le découpage aux seuls modules recharts/d3 |
| E7 | **Préproduction et production pointent vers le même dossier** sur un VPS unique (Nginx, `.env`, supervisor, cron) | `mk-delices-staging.conf:29`, `deploy.sh:8` | `/var/www/mk-delices-staging`, script paramétré, noms de services distincts |
| E8 | **Tests de fumée inopérants** (`APP_URL` non exporté ; 401 en préproduction à cause du mot de passe) | `deploy.sh:51` | URL passée en argument, `curl -u` ou `/up` sans authentification |
| E9 | **Aucune supervision ni alerte** (pannes, jobs en échec, sauvegarde absente, certificat) ; `/up` ne teste pas la base | doc 11 §4 non implémentée | Sonde externe, route `/health` (base, âge de la sauvegarde, `failed_jobs`), alertes email au niveau `error` |
| E10 | **Verrou du planificateur bloqué 24 h** après un arrêt brutal : rapprochement des paiements suspendu | `routes/console.php:60` | `withoutOverlapping(10)` |
| E11 | **Comptes de démonstration (dont administrateur) au mot de passe public** semés en préproduction | `DemoDataSeeder.php:23,49-56` | Atténué par le mot de passe Nginx de la préproduction ; mot de passe aléatoire affiché une fois pour l'administrateur hors poste local |

## 4. Constats moyens

**Sécurité et confidentialité**
- **M1** Jeton de suivi conservé **en clair** dans `idempotency_keys` (24–48 h, et dans les sauvegardes) — `Idempotency.php:45-52`. Ne pas mémoriser le jeton ou chiffrer la réponse.
- **M2** **Injection de formules** dans l'export CSV des factures (nom client saisi librement) — `InvoiceController.php:62`. Préfixer `'` les cellules commençant par `= + - @`.
- **M3** Jetons de suivi conservés **sans limite** sur l'appareil (jusqu'à 20, listés sur `/suivi`) : visibles sur un téléphone partagé — `storage.ts:46-51`. Expiration, purge à la déconnexion, bouton « Oublier cet appareil ».

**Logique métier**
- **M4** Brouillon WhatsApp confirmé : **promotion ni revalidée ni comptée** (limite d'usage contournable) — `CheckoutService.php:259-281`.
- **M5** Montant reçu ≠ montant attendu : paiement laissé « en attente » indéfiniment, une ligne de journal ajoutée toutes les 5 min — `PaymentService.php:199-204`.
- **M6** Anti-doublon des notifications par statut : un **second échec de livraison n'alerte plus personne** ; envoi marqué « envoyé » avant l'envoi — `OrderNotifier.php:37,70-85`.
- **M7** Paiement à la livraison : l'espèce collectée ne crée pas de paiement → **ni facture ni statistiques** — `DeliveryService.php:189-203`.
- **M8** Espèces détenues par un livreur impossibles à solder si la course est gratuite — `DeliveryService.php:139-143`.
- **M9** Paiement manuel accepté sur une commande annulée/refusée, sans remboursement — `PaymentService.php:246-275`.

**Frontend**
- **M10** Session expirée (401/419) : pas de redirection vers la connexion ni de nouvelle tentative CSRF — `api.ts:77-78`, `main.tsx`.
- **M11** Erreur du devis au paiement invisible : bouton « Confirmer » grisé sans explication — `checkout.tsx:237-243`.
- **M12** Accessibilité : focus clavier invisible sur les créneaux et formats (WCAG 2.4.7) ; contour des champs à 1,38:1 (WCAG 1.4.11) ; menu mobile du back-office sans gestion du focus ni lien d'évitement.
- **M13** Conflit de version (409) dans les fenêtres « Marquer prête » / « Annuler le démarrage » / « Modifier » : erreur en boucle jusqu'à réouverture.
- **M14** Dates des factures **sans année** dans le registre — `InvoicesPage.tsx:131`, `InvoiceList.tsx:50`.

**Exploitation et performance**
- **M15** **Index manquants** sur les clés étrangères (`order_items.order_id`, `orders.customer_id`, `payments.order_id`, `refunds.*`, `delivery_assignments.order_id`, `product_variants.product_id`…) et sur la date des statistiques (parcours complet de `orders` toutes les 60 s).
- **M16** Notifications email sans délai entre tentatives (6 essais immédiats) ; `stopwaitsecs=3600` dans supervisor ; anciens fichiers JS supprimés au déploiement (écran cassé pour les onglets ouverts).
- **M17** Prérequis serveur non documentés (extensions PHP, limites d'envoi et mémoire pour les images 8000 px, `pg_dump` 18, Node ≥ 20.19, gzip), sauvegardes distantes sans rétention/immuabilité, restauration des médias jamais testée.

## 5. Constats faibles (résumé)
- Préparateur : accès au détail complet de toute commande historique (téléphone masqué mais nom, notes, journal visibles).
- Image décodée avant le contrôle de ses dimensions (épuisement mémoire possible par un compte catalogue).
- Changement de mot de passe d'un utilisateur non tracé dans l'audit ; compte client désactivé conservant l'accès à ses commandes ; énumération des emails à l'inscription.
- Preuve de remise « code » non vérifiée ; encaissement livreur inférieur au dû non signalé ; retrait sur place sans preuve.
- Créneau réservé sans délai de préparation lors de la confirmation d'un brouillon ; promotions non rendues à l'annulation ; statistiques incluant des commandes annulées encore « payées ».
- En-têtes de sécurité perdus sur `/assets/` (piège `add_header` Nginx) et doublés par Laravel ; `www` HTTPS non redirigé ; logrotate absent pour les sauvegardes ; e-mails de préproduction écrits dans les journaux.
- Nouvelle commande back-office : devis recalculé à chaque frappe du code promo, double clic sur +/−, « Paiement à la livraison » proposé comme lien de paiement.
- Accessibilité secondaire : notes en étoiles mal annoncées, onglets du compte client incomplets, barre d'actions livreur sous la barre d'accueil iPhone.
- Divers : identifiant de facture non contraint au format UUID (erreur 500), filtre `status[]` en erreur 500, triggers d'immuabilité ne couvrant pas `TRUNCATE`.

**Poste de développement :** la variable `NODE_TLS_REJECT_UNAUTHORIZED=0` est active dans l'environnement de la session (désactive la vérification des certificats HTTPS pour Node/npm). Elle n'est définie ni dans le projet ni au niveau utilisateur/machine : identifier le terminal ou l'outil qui la pose et la retirer.

## 6. Points vérifiés et conformes
- Autorisation : chaque route privée sous `auth:sanctum` + compte actif + permission explicite, revérifiée dans le code métier ; administrateur via `Gate::before` seulement s'il est actif ; inscription publique limitée au rôle client.
- Jetons de suivi et de panier aléatoires, hachés (SHA-256), comparés par `hash_equals`, expirants ; erreurs indistinctes (404).
- Webhooks signés (HMAC, fenêtre de 5 min), dédoublonnés ; paiement validé uniquement après consultation serveur-à-serveur ; montant et devise contrôlés ; simulateur inaccessible en production.
- Prix, frais et promotions toujours recalculés côté serveur (site, WhatsApp, back-office).
- Double contrôle des remboursements ; cumul ≤ encaissé ; factures immuables, numérotation sans trou, avoirs plafonnés, TVA extraite du TTC.
- Créneaux et stock protégés contre la surréservation ; une seule mission active par commande ; effets de bord déclenchés après validation de la transaction.
- Aucune requête SQL construite avec une donnée utilisateur ; aucun `dangerouslySetInnerHTML` ; Markdown sans HTML brut ; liens externes en `noopener`.
- Images ré-encodées en WebP sans métadonnées ; aucun PHP exécutable sous `/storage`.
- Données personnelles filtrées par rôle ; journaux Nginx sans chaîne de requête ; emails masqués dans les journaux de notification.
- CSP cohérente avec ce que charge le SPA ; HTTPS forcé ; TLS 1.2/1.3.
- Pagination sur toutes les listes du back-office ; pas de requêtes N+1 ; paramètres mis en cache.
- Code typé strictement ; composants de dialogue accessibles (Radix) ; zones tactiles ≥ 44 px pour les livreurs ; couleurs de texte ≥ 4,5:1.

## 7. Plan d'action recommandé

**Lot 1 — avant la préproduction (≈ 1 jour)**
B3, B4 (version simplifiée : ordre des étapes, `trap` sûr, permissions), B5, E1, E5, E6, E7, E8, E10, M2, M14, et E3 (verrou du rejet de remboursement).

**Lot 2 — avant l'ouverture au public (≈ 3 à 4 jours)**
B1 et B2 (avec les tests en sandbox Wave), E2, E4, E9, E11, M1, M3 à M13, M15, M16, M17, B4 complet (releases et retour arrière). Ajouter un test automatisé pour chaque scénario corrigé.

**Lot 3 — amélioration continue**
Constats faibles, à traiter au fil des évolutions.

Chaque correction doit être accompagnée d'un test reproduisant le scénario décrit, puis de l'exécution complète des suites PHPUnit, Vitest et Playwright.

## 8. Suivi des corrections

| Lot | Constats | État | Vérification |
|---|---|---|---|
| Lot 1 (15/11/2026) | B3, B4, B5, E1, E3, E5, E6, E7, E8, E10, M2, M14 | **Corrigés** (commit `3db801e`) | Test dédié pour chacun ; sauvegarde/restauration exécutées localement (chiffrement GPG réel à vérifier sur le serveur) ; JS initial de la boutique 255 → 162 kB gz |
| Lot 2 (16-17/11/2026) | **B1** remboursement Wave (partiel jamais automatique, appel hors verrou, marqueur anti-doublon, pas de nouvel essai sur les POST) · **B2** paiement confirmé après expiration locale · **E2** trop-perçu détecté et remboursé sans avoir · **E4** paiement à la livraison préservé, montant à encaisser calculé · **E9** sonde `/health`, alertes, battement du planificateur · **E11** mot de passe aléatoire de l'administrateur de démonstration hors poste local · **M1** réponses idempotentes chiffrées · **M3** liens de suivi expirant après 30 jours, « Oublier cet appareil » · **M4** code promo des brouillons WhatsApp revérifié et compté · **M5** montant incohérent : tentative close, sans boucle · **M6** notifications par version (second échec notifié) · **M7** espèces à la livraison enregistrées comme paiement · **M8** espèces des courses gratuites soldables · **M9** pas d'encaissement manuel sur commande annulée ou paiement en ligne ouvert · **M10** session expirée et jeton CSRF renouvelé · **M11** erreur de devis affichée · **M12** focus visible, contrastes des champs, menu mobile et lien d'évitement · **M13** conflits de version dans les fenêtres · **M15** 17 index · **M16** nouvelles tentatives espacées, supervisor, rechargement après déploiement · **M17** prérequis serveur, gzip, logrotate · **F3** imputation du remboursement · **I1** identifiant de facture invalide → 404 | **Corrigés** | 131 tests backend (dont 30 nouveaux), 14 tests frontend |
| Lot 3 (17/11/2026) | **Sécurité** : préparateur limité aux commandes en production (sans nom, journal ni missions) · image refusée sur ses dimensions avant décodage (5 000 px max) · écran Utilisateurs réservé à l'équipe, changements de mot de passe tracés · compte client désactivé sans accès par le compte · entrées malformées en 4xx · API en `no-store` · TRUNCATE interdit sur journaux et factures. **Métier** : délai de préparation appliqué aux brouillons confirmés et aux changements de créneau · usage du code promo rendu à l'annulation · chiffre d'affaires hors commandes annulées et trop-perçus. **Exploitation** : en-têtes de sécurité sur `/assets/` (fichier commun), `www` redirigé, configurations validées par `nginx -t`. **Interface** : nouvelle commande (code promo appliqué à la validation, +/− protégés, seuls les paiements en ligne proposés), ouverture WhatsApp non bloquée, rafraîchissements après remboursement, heures en heure de Dakar, notes en étoiles accessibles, onglets du compte client, confirmation de suppression d'adresse, barre livreur au-dessus de la barre d'accueil iPhone, numéro masqué non cliquable | **Corrigés** | Tests dédiés (backend et frontend) |
| Non traités, par choix | **Énumération des emails à l'inscription** : indiquer « email déjà utilisé » est nécessaire à l'expérience client ; risque limité par la limitation des tentatives. **Code de remise vérifié** : suppose de transmettre un code au client à la commande (fonctionnalité à décider par MK Délices). **Preuve de remise en retrait sur place** : le retrait est désactivé. **Confirmation en sandbox de l'API de remboursement Wave** : dépend du contrat (D-PAY-01) | Décision métier | — |

**Point d'attention :** le comportement réel de l'API de remboursement Wave (partiel ou intégral) reste à confirmer en sandbox ; tant qu'il ne l'est pas, seul le remboursement intégral d'un paiement Wave est exécuté automatiquement, tout partiel passe en exécution manuelle depuis l'espace marchand Wave.
