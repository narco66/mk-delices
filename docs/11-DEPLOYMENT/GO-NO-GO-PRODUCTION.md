# Décision de mise en production (go / no-go) — MK Délices

**Pour :** la direction de MK Délices et le responsable technique, à la réunion qui autorise l'ouverture du site au public.
**Règle :** la mise en production est autorisée quand **toutes les lignes « Bloquant » sont cochées**. Les lignes « Recommandé » peuvent être traitées dans les 30 jours suivant l'ouverture.

État au 17/11/2026 : ✅ fait · ⏳ à faire par l'équipe technique · 🔴 attend MK Délices.

## 1. Métier et juridique

| # | Point | Niveau | État | Qui |
|---|---|---|---|---|
| M1 | Catalogue réel : produits, prix, ingrédients, **allergènes vérifiés sur les étiquettes**, conservation (D-CAT-01/02/03) | Bloquant | 🔴 | MK Délices |
| M2 | Photos dont MK Délices détient les droits (D-BRAND-02) | Bloquant | 🔴 | MK Délices |
| M3 | Zones, frais de livraison, minimums, rémunération des livreurs (D-ZONE, D-LIV-01) | Bloquant | 🔴 | MK Délices |
| M4 | Horaires, jours fermés (fêtes), délais et capacité des créneaux (D-HOR) | Bloquant | 🔴 | MK Délices |
| M5 | Raison sociale, NINEA, RCCM : **aucune facture en production sans eux** (D-JUR-01, D-JUR-04) | Bloquant | 🔴 | MK Délices |
| M6 | Mentions légales, CGV, politique de confidentialité relues par un juriste ; déclaration CDP (D-JUR-01/02/03) | Bloquant | 🔴 | MK Délices + juriste |
| M7 | Décision TVA du comptable (taux ou « non assujetti ») | Bloquant | 🔴 | Comptable |
| M8 | Contrats livreurs : confidentialité des données clients (D-LIV-03) | Bloquant | 🔴 | MK Délices + juriste |
| M9 | Numéro WhatsApp Business (D-WA-01) | Recommandé | 🔴 | MK Délices |
| M10 | Suppression des 2 témoignages fictifs ou remplacement par des avis réels autorisés (D-CONT-01) | Bloquant | 🔴 | MK Délices |

## 2. Paiement

| # | Point | Niveau | État | Qui |
|---|---|---|---|---|
| P1 | Contrat marchand Wave signé, clés API et secret webhook (D-PAY-01) | Bloquant | 🔴 | MK Délices |
| P2 | Tests en environnement de test Wave : paiement, échec, expiration, **remboursement partiel**, webhook rejoué | Bloquant | ⏳ après P1 | Technique |
| P3 | Un vrai paiement de faible montant en production, puis son remboursement | Bloquant | ⏳ après P1 | Technique + MK Délices |
| P4 | Simulateur de paiement inactif en production (`PAYMENTS_SIMULATOR_ENABLED=false`, ignoré hors préproduction) | Bloquant | ✅ | — |

## 3. Technique

| # | Point | Niveau | État | Qui |
|---|---|---|---|---|
| T1 | Nom de domaine acheté, DNS pointé vers le serveur, certificat HTTPS | Bloquant | 🔴 domaine à choisir | MK Délices puis technique |
| T2 | Serveur de production installé selon `DEPLOYMENT-MK-DELICES.md` § 3.2 (ou préproduction promue) | Bloquant | ⏳ | Technique |
| T3 | Envoi réel des emails (prestataire SMTP, domaine authentifié SPF/DKIM) | Bloquant | 🔴 choix du prestataire | MK Délices + technique |
| T4 | CI au vert sur le commit déployé | Bloquant | ✅ (vérifiée à chaque envoi) | — |
| T5 | Sauvegardes quotidiennes chiffrées, clé privée hors serveur | Bloquant | ✅ préproduction | — |
| T6 | **Copie des sauvegardes hors du serveur** (stockage externe) | Bloquant | 🔴 stockage à fournir | MK Délices |
| T7 | **Test de restauration réussi** (`restore-test.sh`), durée consignée | Bloquant | ⏳ nécessite la clé privée | Détenteur de la clé + technique |
| T8 | Sonde externe sur `/health` toutes les 5 min, alerte par email/SMS | Bloquant | 🔴 compte UptimeRobot | MK Délices |
| T9 | Alertes d'erreurs (Slack ou email) | Recommandé | ⏳ | Technique |
| T10 | Dépôt GitHub privé, serveur relié par clé de déploiement | Bloquant | 🔴 | Propriétaire du dépôt |
| T11 | Comptes de démonstration supprimés ; administrateur créé par `mk:create-admin` ; données `is_demo` retirées | Bloquant | ⏳ le jour J | Technique |
| T12 | Mesure de performance mobile réelle (PageSpeed ou téléphone) | Recommandé | ⏳ après T1 | Technique |
| T13 | Notifications des livreurs testées sur un vrai Android et un vrai iPhone | Recommandé | ⏳ préproduction | Technique + un livreur |

## 4. Équipe

| # | Point | Niveau | État | Qui |
|---|---|---|---|---|
| E1 | Formation de chaque rôle (voir `docs/guides/PLAN-DE-FORMATION.md`), fiche de validation signée | Bloquant | ⏳ | Technique + MK Délices |
| E2 | Recette métier : cahier `docs/RECETTE/CAHIER-DE-RECETTE-MK-DELICES.md` passé par MK Délices, aucun problème bloquant ouvert | Bloquant | ⏳ | MK Délices |
| E3 | Contact technique d'astreinte pour les 2 premières semaines | Bloquant | 🔴 | MK Délices |

## 5. Le jour de l'ouverture

1. Sauvegarde manuelle, déploiement du commit validé, tests de fumée (`RUNBOOK-EXPLOITATION.md` § 2).
2. Retrait des données de démonstration, création des vrais comptes, saisie des paramètres validés.
3. Une commande réelle complète par l'équipe (paiement, préparation, livraison, facture), puis remboursement.
4. Ouverture au public ; surveillance renforcée pendant 48 h (`/health`, paiements en attente, journaux).
5. Revue à 30 jours : incidents, performances, retours clients.

## Décision

| Date | Décision (go / no-go) | Réserves | Signature direction | Signature technique |
|---|---|---|---|---|
| | | | | |
