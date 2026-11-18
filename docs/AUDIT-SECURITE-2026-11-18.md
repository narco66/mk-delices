# Audit de sécurité — MK Délices

**Pour :** la direction de MK Délices et le responsable technique.
**Date :** 18/11/2026.
**Périmètre :**
- application au commit `1e88652` (backend Laravel, frontend React) ;
- préproduction `srv2163043.hstgr.cloud` ;
- dépôt GitHub (public) ;
- poste de développement.

Tous les constats ci-dessous ont été **vérifiés** : commandes exécutées, réponses du serveur, lecture du code. Aucun test intrusif n'a été mené, aucune donnée n'a été modifiée sur le serveur, et aucune tentative de connexion n'a été faite avec des identifiants.

## 1. Synthèse

L'application est **bien protégée**. Contrôle d'accès par rôle côté serveur, protection des paiements et des données personnelles, en-têtes de sécurité, limitation des tentatives : tout tient. Les dépendances n'ont aucune vulnérabilité connue.

Les faiblesses trouvées concernent surtout **l'environnement** :
- la configuration SSH du serveur ;
- des mots de passe de démonstration publiés dans le dépôt public ;
- une clé MapTiler sans restriction.

Ce sont des réglages, pas des défauts de conception. Un seul défaut applicatif, mineur, a été trouvé ; il est corrigé.

| Gravité | Nombre | Corrigés |
|---|---|---|
| Élevée | 2 | 1 (S-01, le 18/11/2026) ; S-02 non retenu pour l'instant |
| Moyenne | 3 | 0, actions MK Délices ou accord requis |
| Faible | 4 | 2 (S-06, S-07) |

## 2. Constats

| ID | Gravité | Constat | Preuve | Recommandation | État |
|---|---|---|---|---|---|
| S-01 | **Élevée** | **SSH accepte la connexion de root par mot de passe.** Deux fichiers de configuration se contredisent (`50-cloud-init.conf` : oui ; `60-cloudimg-settings.conf` : non) et le premier lu l'emporte. Le compte root a un mot de passe. Aucune protection contre les essais répétés (fail2ban inactif) | `sshd -T` : `permitrootlogin yes`, `passwordauthentication yes` ; `passwd -S root` : P. Aucune tentative d'intrusion dans les journaux (filtrage probable en amont par Hostinger), mais rien ne la bloquerait côté serveur | `PasswordAuthentication no` et `PermitRootLogin prohibit-password` ; installer fail2ban ; `X11Forwarding no`. Toutes les connexions actuelles se font déjà par clé : aucune coupure attendue | ✅ **appliqué le 18/11/2026** : fichier `00-mk-hardening.conf` (annulation automatique programmée pendant le test) ; nouvelle connexion par clé vérifiée ; mot de passe plus proposé (`Permission denied (publickey)`) ; fail2ban actif sur SSH (5 échecs en 10 min → 1 h) |
| S-02 | **Élevée** | **8 comptes de démonstration de la préproduction utilisent le mot de passe publié dans le dépôt public** (`README.md`, `docs/ACCES-DEMONSTRATION.md`, seeders). Concernés : commercial, préparateur, responsable des livraisons, livreurs, clients. Seul l'administrateur a un mot de passe distinct (choix de l'audit du 15/11, E11) | Comparaison des empreintes en base, sans tentative de connexion | Le mot de passe d'accès nginx (non public) reste une barrière. Mais le compte commercial voit les données clients et peut saisir des encaissements : lui donner, ainsi qu'aux autres rôles, un mot de passe propre à la préproduction, stocké dans `/etc/mk-delices/`, et le communiquer aux testeurs | ⏳ non retenu le 18/11/2026, à décider avant la recette avec de vraies données |
| S-03 | Moyenne | **Clé MapTiler sans restriction d'origine**, alors qu'elle est lisible dans la page (fond de carte) | Réponse 200 depuis le serveur, sans en-tête d'origine | Clé serveur distincte ; clé des tuiles restreinte au domaine ; régénérer, la clé ayant circulé en clair | ⏳ MK Délices |
| S-04 | Moyenne | **Dépôt GitHub public** : il expose l'architecture, le nom d'hôte de la préproduction, l'utilisateur d'accès nginx et les mots de passe de démonstration (S-02). Aucun vrai secret dans les 23 commits | Analyse de tout l'historique Git | Passer le dépôt en **privé** (go/no-go T10) | ⏳ propriétaire du dépôt |
| S-05 | Moyenne | **Vérification des certificats HTTPS désactivée sur le poste de développement** (`NODE_TLS_REJECT_UNAUTHORIZED=0` dans l'environnement du terminal) : tout programme Node (npm, Vite, Playwright…) accepte n'importe quel certificat, donc une interception réseau | Avertissement Node à chaque `npm` ; variable absente des variables Windows et des profils shell, donc injectée par l'outil qui lance le terminal | Retirer la variable. Si un proxy d'entreprise intercepte le HTTPS, installer son certificat racine (`NODE_EXTRA_CA_CERTS`) plutôt que de couper la vérification | ⏳ utilisateur du poste |
| S-06 | Faible | **Erreur 500 au lieu de 401** quand un client de l'API non connecté n'envoie pas `Accept: application/json` : redirection vers une route `login` inexistante, erreurs dans les journaux | `GET /api/v1/admin/stats` → 500 ; journal « Route [login] not defined » | `redirectGuestsTo` : l'API répond 401 | ✅ corrigé, testé (`SecurityTest`, le test échoue sans le correctif) |
| S-07 | Faible | nginx annonce sa version | `server_tokens build` dans `/etc/nginx/nginx.conf` | `server_tokens off;` | ✅ appliqué le 18/11/2026 (en-tête `Server: nginx`) |
| S-08 | Faible | `/health` est public et liste l'état des composants (base, file, paiements en attente, sauvegarde) | Réponse JSON sans authentification | Acceptable pour la sonde externe (T8) : aucune donnée sensible ni version. À restreindre si la sonde le permet (jeton dans l'URL) | Accepté |
| S-09 | Faible | Recherches du back-office (clients, paiements, factures) : les caractères `%` et `_` saisis par l'équipe ne sont pas neutralisés | Lecture du code (requêtes paramétrées) | Aucune injection possible ; uniquement des résultats plus larges. Harmoniser avec la recherche du catalogue (`addcslashes`) si souhaité | Accepté |

## 3. Points vérifiés et conformes

| Domaine | Vérification |
|---|---|
| Dépendances | `composer audit` et `npm audit` (avec les outils de développement) : **0 vulnérabilité** |
| Secrets | Aucun secret réel dans l'historique Git (23 commits) ; `.env` ignorés ; secrets du serveur dans `/etc/mk-delices/` (droits 600, root) ; `.env` de la préproduction en 640 |
| Transport | HTTP redirigé vers HTTPS ; TLS 1.0 et 1.1 refusés ; HSTS un an |
| En-têtes | CSP stricte (scripts du site seulement, `frame-ancestors 'none'`, carte limitée à `api.maptiler.com`), `X-Frame-Options DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy` (géolocalisation limitée au site) |
| Fichiers exposés | `.env`, `.git`, journaux, `composer.*`, sauvegardes : non servis (404, ou page d'accueil de l'application) |
| Ports | Seuls 22, 80 et 443 ouverts ; PostgreSQL limité à la machine (scram-sha-256) ; pare-feu UFW actif ; mises à jour de sécurité automatiques ; `display_errors`, `expose_php` et `allow_url_include` désactivés ; `APP_DEBUG=false` |
| Authentification | Sessions chiffrées, cookies `Secure`, `HttpOnly`, `SameSite=Lax` ; jetons mobiles valables 30 jours ; limitation de débit sur la connexion, l'inscription et les jetons ; en-tête `X-Forwarded-For` forgé sans effet |
| Autorisations | Toutes les routes du back-office exigent une permission (sauf les notifications, limitées à l'utilisateur lui-même) ; un livreur n'accède qu'à ses missions ; un client qu'à ses adresses (test d'isolement) ; matrice des rôles testée |
| Paiements | Webhooks signés HMAC-SHA256, horodatage limité à 5 minutes, comparaison en temps constant ; simulateur répondant 404 hors préproduction ; montants toujours recalculés par le serveur |
| Suivi de commande | Jeton aléatoire de 40 caractères, stocké haché, comparé en temps constant, expirant après 30 jours ; aucune position exposée publiquement |
| Injections | Aucun SQL construit avec une saisie (requêtes paramétrées ; seul le tri du catalogue utilise une constante interne) ; aucun HTML brut dans React ; export CSV protégé contre les formules de tableur |
| Envoi de fichiers | Photos limitées à JPEG, PNG et WebP, 6 Mo maximum |
| Requêtes vers des tiers | Notifications du navigateur limitées en HTTPS aux services officiels (Google, Mozilla, Apple, Microsoft) ; géocodage vers un hôte fixe, paramètres encodés |
| Données personnelles | Positions GPS visibles du livreur seulement pendant la mission ; statistiques sans position individuelle ; positions effacées après 90 jours |

## 4. Actions

| Priorité | Action | Qui |
|---|---|---|
| 1 | ✅ S-01 et S-07 faits en préproduction ; à reproduire sur la production (`DEPLOYMENT-MK-DELICES.md`) | Technique |
| 2 | S-02 : mots de passe propres à la préproduction pour les comptes de démonstration | Technique, **après accord** (à communiquer aux testeurs) |
| 3 | S-04 : passer le dépôt GitHub en privé | Propriétaire du dépôt |
| 4 | S-03 : clé serveur MapTiler distincte, clé des tuiles restreinte et régénérée | MK Délices |
| 5 | S-05 : retirer `NODE_TLS_REJECT_UNAUTHORIZED=0` du poste | Utilisateur du poste |
| Avant ouverture | Refaire cet audit sur le serveur de production, puis `php artisan mk:go-live-check` | Technique |
