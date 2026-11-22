# Audit complet — MK Délices (22/11/2026)

**Pour :** l'équipe technique et la direction de MK Délices.
**Version auditée :** commit `174c317`, déployée en préproduction (https://preprod.mk-delices.tech).
**Périmètre :**
- sécurité de l'API ;
- logique métier et intégrité des données ;
- application web (fonctionnement, sécurité, accessibilité, performance) ;
- serveur de préproduction ;
- dépendances et tests.

Les constats des audits précédents ne sont pas répétés ([AUDIT-COMPLET-2026-11-15](AUDIT-COMPLET-2026-11-15.md), [AUDIT-SECURITE-2026-11-18](AUDIT-SECURITE-2026-11-18.md)). Leur état est rappelé au §7.

**Méthode :** lecture complète du code par trois revues indépendantes. Chaque constat retenu ci-dessous a ensuite été **vérifié dans le code** (fichier et ligne cités). S'y ajoutent des contrôles automatiques (dépendances, tests) et une inspection du serveur en lecture seule. Rien n'a été modifié pendant l'audit.

## 1. Synthèse

| Gravité | Nombre | Domaines |
|---|---|---|
| Élevée | 1 | Encaissement : une commande peut être livrée sans rien encaisser |
| Moyenne | 9 | Paiement et remboursement (1), sécurité de la connexion (3), application web (4), serveur (1) |
| Faible | 13 | Corrections ponctuelles |

**Constats principaux :**
- **Autorisations :** aucune faille trouvée. Aucun client ne peut lire ni modifier les données d'un autre, et chaque écran du back-office exige une permission.
- **Point le plus important :** A-01, un cas où le livreur n'a rien à encaisser pour une commande non payée.
- **Préparation de l'ouverture au public :** elle reste conditionnée aux 9 points bloquants de `mk:go-live-check`, presque tous des décisions ou contrats de MK Délices (§6).

## 2. Logique métier et paiements

| ID | Gravité | Constat | Preuve | Correctif proposé |
|---|---|---|---|---|
| A-01 | **Élevée** | **Une tentative de paiement en ligne abandonnée efface le montant à encaisser à la livraison.** Commande saisie par l'équipe (téléphone, boutique) ou brouillon WhatsApp confirmé : moyen de paiement vide. Si le client ouvre son lien de suivi, lance un paiement puis abandonne, la commande passe en moyen « wave ». À l'affectation, le montant à encaisser vaut **0** : le livreur remet la commande sans rien encaisser, aucune facture n'est émise, personne n'est alerté | `PaymentService.php:96-97` remplace le moyen sauf s'il vaut `cash_on_delivery`. `DeliveryService.php:77` ne calcule le reste dû que pour `null` ou `cash_on_delivery` | Calculer le reste dû dès que la commande n'est pas payée (`total - payé`), quel que soit le moyen. Ne plus écraser un moyen vide : mémoriser la tentative sur le paiement lui-même |
| A-02 | Moyenne | **Le remboursement d'un trop-perçu fait passer la commande en « partiellement remboursée »**, alors que la vente est intégralement payée. La commande ne peut alors plus être acceptée (« paiement non confirmé »). Cas : deux sessions de paiement payées toutes les deux (session expirée côté site mais encore valable chez le prestataire) | `RefundService.php:186` ; condition d'acceptation `OrderStateMachine.php:167-170` | Ne recalculer le statut qu'à partir des remboursements de type vente. Après un trop-perçu, la commande reste « payée » |
| A-03 | Faible | Le retrait sur place peut être clôturé sans encaissement ni facture. Option aujourd'hui **désactivée** | `OrderStateMachine.php:33`, `178-180` | Exiger le paiement complet, ou saisir l'encaissement au comptoir comme le fait le livreur |
| A-04 | Faible | Modifier la zone d'une commande en retrait lui ajoute des frais de livraison | `CheckoutService.php:248-281` | Refuser une zone sur une commande en retrait |
| A-05 | Faible | La confirmation d'un brouillon WhatsApp ne revérifie ni que la zone est active ni le minimum de commande. Le délai de préparation peut être périmé | `CheckoutService.php:205, 334, 357` | Recalculer par le service de devis |
| A-06 | Faible | L'appel au prestataire de paiement (jusqu'à 15 s) a lieu pendant que la commande est verrouillée. Les webhooks et le back-office attendent | `PaymentService.php:47-101` | Appeler le prestataire après la transaction |
| A-07 | Faible | Rapprochement des paiements : lot de 200 sans ordre. Des sessions bloquées peuvent retarder les plus récentes | `routes/console.php` (`mk:reconcile-payments`) | Trier par date de dernière vérification |
| A-08 | Faible | Les commandes de test décrémentent le stock et les compteurs de codes promo avant d'être marquées comme tests. Le compteur « remboursements en attente » du tableau de bord ne les exclut pas | `StatsService.php:147` | Exclure les tests du compteur. Le reste n'a pas d'effet hors préproduction |

## 3. Sécurité de l'API

| ID | Gravité | Constat | Preuve | Correctif proposé |
|---|---|---|---|---|
| S-10 | Moyenne | **Pas de limite de tentatives par compte**, seulement par identifiant et adresse IP. Les écritures d'un même numéro (`771234567`, `+221…`) comptent comme des identifiants différents. Avec beaucoup d'adresses IP, les essais de mot de passe sur un numéro ne sont pas plafonnés | `AppServiceProvider.php:55-58` | Limite horaire par compte, avec l'identifiant normalisé ; même règle pour « mot de passe oublié » |
| S-11 | Moyenne | **Numéro de téléphone non vérifié mais exclusif.** Quelqu'un peut prendre le numéro d'une autre personne : elle ne peut plus créer de compte avec. Le message « numéro déjà associé » révèle aussi qu'un compte existe (comme l'email déjà utilisé) | `AuthController.php:201-208`, `AccountController.php:51` | **Décision MK Délices** : vérification par code SMS ou WhatsApp (nécessite un prestataire, D-NOT-01). À défaut, un message neutre |
| S-12 | Moyenne | **En-tête Host non contrôlé.** Les adresses d'image et l'aperçu des réseaux sociaux reprennent l'hôte envoyé par le visiteur. Sans effet aujourd'hui, mais un cache placé devant le site (CDN) pourrait diffuser des liens vers un autre domaine pendant 5 min | `ProductImage.php:38`, `SpaController.php:31, 79` ; pas de `trustHosts` ; nginx sans serveur par défaut de repli | `trustHosts` (domaines du site) et bloc nginx `default_server` qui refuse les autres noms |
| S-13 | Faible | Connexion par téléphone : si deux comptes ont le même numéro (créés par le back-office, qui ne vérifie pas l'unicité), c'est le compte **jamais connecté** qui est retenu, car PostgreSQL trie les valeurs vides en premier | `AuthController.php:232` ; `BackOfficeController.php:174` et `ReferenceDataController.php:142-146` sans contrôle ni normalisation | Index unique partiel sur le téléphone ; contrôle et normalisation dans le back-office ; tri `NULLS LAST` |
| S-14 | Faible | Inscription avec un email en majuscules déjà connu en minuscules : erreur 500 au lieu d'un message clair | Validation `unique` sensible à la casse, `AuthController.php:92` (même schéma dans le back-office) | Passer l'email en minuscules avant la validation |
| S-15 | Faible | Via « mot de passe oublié », un membre de l'équipe peut choisir un mot de passe plus faible que la règle de l'équipe (12 caractères, majuscules et minuscules) | `AuthController.php:182` contre `BackOfficeController.php:159` | Appliquer la règle de l'équipe aux comptes du personnel |
| S-16 | Faible | Le journal des échecs de connexion contient une empreinte du numéro, facile à inverser (peu de numéros possibles) | `AuthController.php:237` | Empreinte avec clé secrète (HMAC) |
| S-17 | Faible | Après suppression du compte, les commandes gardent nom, téléphone, email et adresse (copies figées). Le lien de suivi reste en clair 24 h dans la table d'idempotence | `AccountController.php:226-245`, `Idempotency.php:45-50` | **Avec le juriste** (D-JUR-03) : effacer ce que la facture n'exige pas ; ne pas conserver le lien de suivi en clair |
| S-18 | Faible | Un téléphone enregistré pour les notifications peut être réattribué à un autre compte qui connaît son jeton Firebase (secret de l'appareil) ; un enregistrement fait depuis le site n'est jamais purgé | `DeviceController.php` (`store`) | Enregistrement réservé à l'application (jeton Bearer) ; journaliser les réattributions |

## 4. Application web

| ID | Gravité | Constat | Preuve | Correctif proposé |
|---|---|---|---|---|
| W-01 | Moyenne | **Photos produit : un texte alternatif corrigé peut revenir à l'ancien.** Après la modification, la donnée locale n'est pas rafraîchie. « Mettre en premier » renvoie alors l'ancien texte de toutes les photos | `ProductPhotos.tsx:144-151, 189-197` | Rafraîchir après modification ; « Mettre en premier » n'envoie que l'ordre |
| W-02 | Moyenne | **Retour de paiement : l'écran clignote toutes les 5 s.** Il est remplacé par un indicateur de chargement, annoncé à nouveau aux lecteurs d'écran, et le bouton « Suivre ma commande » disparaît. Après 12 essais la vérification s'arrête alors que le texte annonce une mise à jour automatique | `orders.tsx:226-243` | Conserver l'affichage précédent pendant l'actualisation ; message adapté à la fin des essais |
| W-03 | Moyenne | **Une coupure réseau au mauvais moment bloque toutes les actions jusqu'au rechargement de la page** (erreur du jeton anti-falsification gardée en mémoire), avec un message « erreur inattendue » au lieu de « hors ligne » | `api.ts:94-99` | Libérer la demande en échec, délai maximal, message hors ligne |
| W-04 | Moyenne | **Paramètres : le taux de TVA perd sa virgule** (« 5,5 » est enregistré 55 % et irait sur les factures). Une erreur de saisie n'indique pas le champ fautif, alors que les autres paramètres sont déjà enregistrés | `SettingsPage.tsx:189-199`, `86-95` ; `BackOfficeController.php:203-212` | Saisie décimale ; erreur rattachée au bon champ ; enregistrement tout ou rien |
| W-05 | Faible | Revenir en arrière depuis la page du prestataire peut relancer un paiement (paramètre `auto=1` conservé dans l'adresse) | `orders.tsx:163-168` | Retirer `auto` de l'adresse avant de lancer le paiement |
| W-06 | Faible | La déconnexion et la suppression du compte ne vident pas le panier ni les liens de suivi : sur un appareil partagé, la personne suivante les retrouve | `AuthContext.tsx`, `account.tsx` | Vider le panier ; proposer d'oublier les commandes suivies |
| W-07 | Faible | Contrôle de la redirection après connexion trop permissif (`/\domaine`). Sans effet aujourd'hui, mais fragile | `account.tsx:33-35` | Comparer l'origine de l'adresse cible |
| W-08 | Faible | Accessibilité : <br>• champs obligatoires non annoncés aux lecteurs d'écran ; <br>• menu mobile du back-office sans maintien du focus clavier ni `aria-expanded` ; <br>• libellé du cœur qui change en même temps que son état ; <br>• libellé de la carte Club MK posé sur un paragraphe | `Form.tsx:25-27`, `AdminLayout.tsx:170-185`, `favorites.tsx`, `account.tsx:401` | Corrections ponctuelles (`aria-required`, piège du focus, libellé fixe) |
| W-09 | Faible | Divers :<br>• onglet WhatsApp ouvert sans couper le lien avec la page (`checkout.tsx:398`) ;<br>• trois paramètres numériques saisis comme du texte ;<br>• titre brut « preparation » ;<br>• inscription qui ignore la page d'origine ;<br>• sélection de photos vidée après un échec partiel | — | Petites corrections |

## 5. Serveur de préproduction

| ID | Gravité | Constat | Correctif proposé |
|---|---|---|---|
| I-01 | Moyenne | **Redémarrage en attente** : un nouveau noyau est installé mais pas actif (6 jours sans redémarrage). 24 mises à jour disponibles | Redémarrage planifié (~1 min d'interruption), puis vérification des services |
| I-02 | Faible | **Docker actif sans aucun conteneur.** Service inutile, qui peut ouvrir des ports en contournant le pare-feu UFW s'il est utilisé un jour | Désactiver `docker` (et `containerd`) |
| I-03 | Faible | En-têtes de sécurité envoyés **en double** (HSTS, `X-Frame-Options`…), par nginx et par un second bloc ou l'application | Ne les définir qu'à un seul endroit |
| I-04 | Rappel | **Sauvegardes uniquement sur le serveur** (chiffrées, quotidiennes, à 2 h) : aucune copie externe (`RCLONE_REMOTE` vide). Une perte du serveur emporterait les sauvegardes | Stockage externe à choisir (D-HEB-01), puis test de restauration |
| I-05 | Rappel | Emails écrits dans le journal du serveur (`MAIL_MAILER=log`) : « mot de passe oublié » et confirmations de commande n'arrivent pas | Prestataire SMTP + SPF/DKIM |

**Conforme :**
- SSH par clé uniquement ; fail2ban actif ; UFW (ports 22, 80 et 443 seulement) ; PostgreSQL limité à la machine.
- Certificat valable jusqu'au 18/02/2027, renouvellement planifié.
- Disque à 9 %, mémoire suffisante, tous les services actifs, worker en marche, aucun job en échec, aucune erreur 5xx.
- `.env` en 640 ; secrets en 600 (root).
- `APP_DEBUG=false` ; factures de préproduction dans la série `TEST` ; HTTPS forcé.

## 6. Dépendances, tests et mise en production

| Contrôle | Résultat |
|---|---|
| `composer audit` | 0 vulnérabilité |
| `npm audit` (production) | 0 vulnérabilité. 7 « modérées » dans les outils de développement seulement (ESLint → `minimatch`), sans effet sur le site |
| Mises à jour majeures | `minishlink/web-push` 11, PHPUnit 13 : non urgentes |
| Tests backend | 204/204 |
| Tests frontend | 29/29 |
| Tests de bout en bout | Parcours client, commande, back-office, accessibilité et géolocalisation au vert. Les scénarios de paiement supposent les réglages de démonstration |
| Poids du site | Page d'accueil légère ; le module de statistiques (400 ko, réservé au back-office) est chargé à la demande |
| `mk:go-live-check` (préproduction) | **9 bloquants**, tous attendus en préproduction ou dépendant de MK Délices :<br>• simulateur actif ;<br>• aucun paiement réel ;<br>• emails ;<br>• raison sociale, NINEA, RCCM ;<br>• validation du juriste ;<br>• comptes, données et administrateur de démonstration |

Le contrôle de mise en production ne mentionne pas encore l'interrupteur « paiement en ligne réel » ajouté le 22/11 : à compléter (fait partie du lot 2).

## 7. État des constats précédents (audit du 18/11)

| ID | État |
|---|---|
| S-01 SSH, S-07 version nginx | ✅ Toujours en place (vérifié) |
| S-06 erreur 500 sans `Accept` | ✅ Corrigé. Les 3 erreurs « Route [login] » du journal datent du 18/11, avant le correctif |
| S-02 mot de passe de démonstration public | ⏳ Non retenu par MK Délices |
| S-03 clé MapTiler, S-04 dépôt public, S-05 vérification TLS du poste | ⏳ Actions MK Délices / propriétaire |

## 8. Plan de correction proposé

| Lot | Contenu | Qui |
|---|---|---|
| **1 — immédiat** | A-01, A-02, W-01, W-03, W-04 | Technique |
| 2 — court terme | S-10, S-12, S-13, S-14, S-15, S-16, S-18, W-02, W-05, W-06, W-07, A-04 à A-08, contrôle de mise en production | Technique |
| 3 — serveur | I-01 (redémarrage), I-02, I-03 | Technique, à un moment choisi |
| 4 — décisions | S-11 (vérification du téléphone), S-17 (durées de conservation), I-04 (stockage externe), I-05 (SMTP), A-03 avant d'ouvrir le retrait sur place | MK Délices |
| 5 — confort | W-08, W-09 | Technique |
