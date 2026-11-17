# Déploiement, exploitation et maintenance

**MK DÉLICES** — Dossier de cadrage fonctionnel et technique

**Document 11/12** · Version 1.0 · 14 novembre 2026

---

## Gouvernance du document

| Champ | Valeur |
|---|---|
| Projet | MK Délices — Plateforme e-commerce pâtisserie |
| Version | 1.0 — cadrage initial du 14 novembre 2026 |
| Statut | Proposition de référence ; à valider par MK Délices |
| Localisation | Dakar, Sénégal |
| Monnaie / fuseau | XOF / Africa/Dakar (UTC+0) |
| Socle préconisé | React + TypeScript + Vite ; Laravel 13 / PHP ≥ 8.4 (dépendances Symfony 8.4 ; Ubuntu 24.04 : PPA ondrej/php) ; PostgreSQL |
| Hypothèses | Catalogue, prix, zones, frais, prestataires et identité visuelle à confirmer |

**Convention :** « DOIT » = exigence impérative ; « DEVRAIT » = souhaitable ; « option » = hors MVP ou à arbitrer. Les exemples de tarifs, d'adresses, de parfums et de durées ne constituent jamais des données commerciales officielles. Toutes les valeurs monétaires sont enregistrées en unités entières de XOF.


## 1. Architecture cible initiale

Une VM/VPS Linux administrée, reverse proxy Nginx HTTPS, application PHP-FPM Laravel, build statique React, PostgreSQL managé si budget ou instance dédiée protégée, service queue et stockage sauvegardé. Ajuster le sizing par test de charge. Séparer comptes applicatifs et base de données ; pas de port DB exposé publiquement.

## 2. Environnements et secrets

| Environnement | Base | Moyen de paiement | Accès |
| --- | --- | --- | --- |
| DEV | Données fictives locales | Mock/sandbox | Développeurs |
| STAGING | Données factices réalistes | Sandbox PSP | Équipe QA / validation |
| PROD | Données réelles chiffrées selon besoins | Compte PSP marchand production | Utilisateurs contrôlés |

## 3. Procédure de déploiement

- Sauvegarder DB et version applicative avant changement ; contrôler intégrité.
- Exécuter CI : composer validate/install, tests PHP, lint/tests TS, build Vite, audit dépendances.
- Déployer artefacts signés ; injecter secrets par environnement.
- Appliquer migrations non destructives et vérifier transactions.
- Recharger PHP-FPM/queues, purger caches contrôlés, exécuter health checks.
- Effectuer smoke tests : catalogue, prix devis, création commande fictive contrôlée, accès admin, disponibilité webhooks.
- Surveiller logs, latences et erreurs ; effectuer rollback applicatif si seuil dépassé.

### 3.1 Mise en œuvre (scripts du dépôt, corrections de l'audit du 15/11/2026)

| Élément | Fichier | Installation sur le serveur |
|---|---|---|
| Déploiement | `deploy/scripts/deploy.sh <tag>` | Lancé par l'utilisateur `deploy` (groupe `www-data`). Production : `APP_DIR=/var/www/mk-delices`. Préproduction : `APP_DIR=/var/www/mk-delices-staging SMOKE_AUTH=utilisateur:motdepasse`. `PHP_FPM=php8.4-fpm` (ou 8.5). |
| Droits sudo limités | `deploy/sudoers/mk-deploy` | `/etc/sudoers.d/mk-deploy` (440), vérifier avec `visudo -cf` |
| Sauvegarde | `deploy/scripts/backup.sh` + `deploy/backup.env.example` | `/etc/mk-delices/backup.env` (root, 600) ; enveloppe `deploy/sbin/mk-backup` → `/usr/local/sbin/mk-backup` ; cron root 02:00 (`deploy/cron/mk-delices`) ; `mkdir -p /var/log/mk-delices` |
| Test de restauration | `deploy/scripts/restore-test.sh <db-*.dump.gpg>` | Sur une machine isolée, au moins une fois par mois : sommes de contrôle obligatoires, archive des médias vérifiée |
| Nginx | `deploy/nginx/mk-delices.conf`, `mk-delices-staging.conf` | Dossiers distincts ; `/up` accessible sans mot de passe en préproduction |

Comportement du script de déploiement :
1. sauvegarde préalable (sauf `SKIP_BACKUP=1` au premier déploiement) ;
2. build du frontend et vérification des dépendances **dans une copie de travail temporaire, site toujours en ligne** ;
3. maintenance → bascule du code → dépendances → migrations additives → caches → permissions `storage/` et `bootstrap/cache` (groupe `www-data`) → redémarrage des workers → remise en ligne ;
4. **en cas d'échec après la mise en maintenance, le site reste en maintenance** et le script indique la commande de retour à la version précédente (mémorisée dans `.deployed-ref`, ancien build conservé dans `frontend/dist.old`) ;
5. tests de fumée sur l'URL réelle (`APP_URL` ou `BASE_URL`).

Les anciens fichiers JS/CSS sont conservés à chaque déploiement : les onglets déjà ouverts continuent de fonctionner.

### 3.2 Installation du serveur (Ubuntu 24.04, une seule fois)

| Élément | Exigence | Remarque |
|---|---|---|
| PHP | **8.4 ou 8.5** (PPA `ondrej/php`) avec `php-fpm`, `pgsql`, `gd` (support WebP), `xml` (dom), `mbstring`, `curl`, `zip`, `opcache` | Ubuntu 24.04 fournit PHP 8.3 : insuffisant. Contrôle : `composer check-platform-reqs` |
| Limites PHP (pool FPM) | `upload_max_filesize = 8M`, `post_max_size = 10M`, `memory_limit = 512M`, `max_execution_time = 60` | Ré-encodage des photos produits (jusqu'à 6 Mo) |
| PostgreSQL | **18** (dépôt PGDG), y compris le client `pg_dump` 18 | Le client 16 d'Ubuntu refuse de sauvegarder un serveur 18. Rôles `mk_app` (application) et `mk_backup` (lecture seule) |
| Node.js | ≥ 20.19 (Vite 8) | Uniquement pour le build pendant le déploiement |
| Composer | 2.x | |
| Nginx | `deploy/nginx/*.conf`, certificat `certbot --nginx` | Compression gzip incluse ; préproduction : `htpasswd` + liste blanche d'IP |
| Supervisor | `deploy/supervisor/mk-delices-worker.conf` | `mkdir -p /var/log/mk-delices && chown www-data /var/log/mk-delices` |
| Cron | `deploy/cron/mk-delices` → `/etc/cron.d/` | Planificateur Laravel (chaque minute) et sauvegarde (02:00) |
| Sauvegardes | `gpg`, `rclone` ; `/etc/mk-delices/backup.env` ; `mkdir -p /var/lib/mk-delices` | Horodatage lu par `/health` (`HEALTH_BACKUP_STATUS_FILE`) |
| Journaux | `deploy/logrotate/mk-delices` → `/etc/logrotate.d/` | Rotation du journal des sauvegardes |
| Utilisateurs | `deploy` (groupe `www-data`) pour déployer ; PHP-FPM, worker et cron en `www-data` ; sauvegarde en `root` | `deploy/sudoers/mk-deploy` |

### 3.3 Supervision

- **Sonde externe** (UptimeRobot, Healthchecks…) toutes les 5 minutes sur `https://<domaine>/health` : `200` = tout va bien, `503` = un contrôle a échoué (base de données, cache, file de tâches bloquée, tâches en échec sur 24 h, paiements en attente depuis plus de 2 h, planificateur arrêté, sauvegarde de plus de 26 h).
- **Alertes** : `LOG_STACK=daily,slack` et `LOG_SLACK_WEBHOOK_URL` dans `.env` → erreurs et incidents critiques (tâche en échec, file saturée, encaissement incohérent, remboursement à vérifier) envoyés sur le canal d'alerte.
- **Sauvegarde** : `HEARTBEAT_URL` dans `/etc/mk-delices/backup.env` → la sonde alerte si aucune sauvegarde réussie depuis 26 h.

## 4. Supervision et alertes

| Signal | Seuil d alerte initial | Action |
| --- | --- | --- |
| HTTP 5xx | >2 % sur 5 min | Équipe exploitation |
| Checkout échoué | Pic inhabituel sur 15 min | Vérifier PSP et sandbox/prod |
| Jobs en échec | >10 en file dead-letter | Rejouer après diagnostic |
| Espace disque | >80 % | Purger médias temporaires / agrandir |
| Backup absent | Aucune réussite 24h | Alerte critique |
| Certificat TLS | Expiration <30 j | Renouveler |
| Paiements en attente | Dépasse délai configuré | Réconciliation prestataire |

## 5. Sauvegardes et PRA

Sauvegardes chiffrées de PostgreSQL au moins quotidiennes, copie hors serveur, rotation selon exigences contractuelles et légales, contrôle quotidien de succès et restauration mensuelle en environnement isolé. **Cibles initiales proposées** : RPO ≤24 heures ; RTO ≤8 heures, à valider après analyse d impact. Sauvegarde des fichiers médias et documents commerciaux ; restauration inclut secrets régénérables et procédures accès.

## 6. Sécurité des opérations

MFA administrateurs si possible, SSH clés, pare-feu restrictif, mises à jour sécurité, logs centralisés, revue trimestrielle des comptes, rotations de secrets et examen périodique des autorisations livreurs. Ne pas conserver les données client dans logs de proxy ni exports non protégés. Tester effectivement restauration, certificats et rotation de clé PSP.

## 7. Runbooks incidents

| Incident | Diagnostic | Remédiation |
| --- | --- | --- |
| PSP en panne | API timeout / statut prestataire | Suspendre paiement en ligne, conserver panier/commande, informer client |
| Webhook en échec | Logs corrélés et files | Rejouer après vérification authentification |
| Site indisponible | Health/DB/CPU/stockage | Rollback ou bascule, communication incident |
| Livraison non réalisée | Statut mission, appel client | Responsable décide relivraison/remboursement |
| Violation données | Alertes d accès / logs | Isoler, révoquer, investiguer, consulter conseil juridique |

## 8. Maintenance et passation

Documenter comptes techniques sans leurs secrets, topologie, procédures de sauvegarde/restauration, contacts PSP et fournisseurs, index APIs, guide admin, guide préparateur, guide livreur et calendrier mises à jour. Revue opérationnelle 30 jours après lancement sur performance, incidents et retours clients.

## Références et réserves

**Sources externes de vérification (consultées le 14/11/2026) :**
- https://laravel.com/framework/docs/13.x/releases
- https://docs.wave.com/business
- https://docs.wave.com/checkout
- https://espaceclientv2.orange.sn/orange-money/api-internet
- https://www.cdp.sn/legislation/textes-legislatifs
- https://www.cdp.sn/legislation/textes-reglementaires

Le présent document distingue faits du projet communiqués, recommandations de conception et décisions commerciales à confirmer. Les paramètres externes (PSP, tarification, obligations légales, contrats) doivent faire l objet de vérifications au moment de l implémentation.

## Approbation et contrôle de changement

| Rôle | Visa / date |
|---|---|
| Direction MK Délices | À compléter |
| Responsable produit / opérations | À compléter |
| Responsable technique | À compléter |

Toute modification après validation doit créer un historique de version, une justification et une analyse d impact sur les autres documents.
