# Guide de l'administrateur

Pour : la personne responsable de la plateforme (accès complet). Ce guide complète les guides des autres rôles, dont l'administrateur peut effectuer toutes les actions.

## 1. Premier démarrage (production)
1. Créez le premier compte administrateur sur le serveur : `php artisan mk:create-admin` (mot de passe saisi, 12 caractères minimum). Aucun compte n'existe par défaut.
2. **Paramètres** : tout paramètre marqué **« À valider par MK Délices »** doit être renseigné avant ouverture :
   - identité (nom légal, NINEA, téléphone, email, réseaux sociaux) et **numéro WhatsApp Business** (format `221XXXXXXXXX`) ;
   - **horaires de livraison** par jour, jours fermés, durée et capacité des créneaux, délai minimal ;
   - **moyens de paiement** : un moyen n'apparaît aux clients que s'il est activé ici **et** configuré techniquement (contrat marchand) ;
   - preuves de remise, motifs d'incident, seuil de chaîne du froid ;
   - **facturation** : raison sociale, NINEA, RCCM et adresse (sans elles, aucune facture n'est émise) ; TVA activée ou non selon l'avis de l'expert-comptable (taux 18 % par défaut, prix catalogue considérés TTC) ; moment d'émission (livraison ou paiement).
   Si des factures étaient dues avant que ces mentions soient renseignées : `php artisan mk:issue-invoices --dry-run` puis `php artisan mk:issue-invoices` sur le serveur.
3. **Livraisons › Zones & tarifs** : créez les zones réelles, frais client, minimum de commande, rémunération livreur.
4. **Catalogue** : vos vrais produits (voir le guide commercial). Supprimez ou archivez les produits de démonstration s'il en reste.
5. **Contenus du site** : remplacez les trames juridiques (CGV, confidentialité, mentions légales) par les textes validés par votre conseil.

### Zones, carte et périmètre desservi
- **Opérations › Zones › Périmètre desservi** : contour où MK Délices livre (aujourd'hui la ville de Dakar, sans Gorée). Pour l'élargir, dessinez un nouveau contour sur la carte ou importez un fichier GeoJSON ; la modification est tracée dans le journal d'audit.
- **Contours des zones tarifaires** (facultatif) : une fois dessinés, ils déterminent automatiquement la zone et les frais à partir de la position du client. Dessinez-les **toutes** avant de compter sur ce contrôle.
- **Carte et recherche d'adresse** : fournisseur MapTiler, clés configurées sur le serveur (voir `docs/audit-geolocalisation/07-GUIDE-CONFIGURATION.md`). Le jour de l'ouverture, ajoutez le **domaine de production** aux origines autorisées de la clé dans le compte MapTiler.
- **Positions des clients** : effacées automatiquement 90 jours après la livraison ; l'adresse écrite reste sur la commande et la facture.

## 2. Utilisateurs et rôles
**Utilisateurs** › **Nouvel utilisateur** : nom, email, mot de passe provisoire (12 caractères, majuscule, minuscule, chiffre), un ou plusieurs rôles :

| Rôle | Accès |
|---|---|
| Administrateur | Tout, dont utilisateurs, paramètres, journal, approbation des remboursements |
| Gestionnaire commercial | Commandes, clients, catalogue, contenus, promotions, paiements (lecture et encaissement manuel), demandes de remboursement, statistiques |
| Préparateur | File de préparation uniquement |
| Responsable livraisons | Livreurs, affectations, missions, incidents, règlements |

Les livreurs se créent dans **Livraisons › Livreurs**. Décochez **Compte actif** pour couper un accès : la session ouverte est bloquée immédiatement. Revoyez la liste des comptes chaque trimestre.

## 3. Remboursements (double contrôle)
Une demande de remboursement (refus ou annulation d'une commande payée, paiement reçu après annulation, geste commercial) doit être **approuvée par une personne différente du demandeur**.
**Remboursements** › **Approuver** : si le prestataire le permet, le remboursement est exécuté automatiquement ; sinon effectuez-le chez le prestataire puis **Confirmer l'exécution** avec la référence. **Rejeter** exige un motif.

## 4. Journal d'audit et sécurité
- **Journal d'audit** : qui a fait quoi et quand (connexions, changements de prix, de rôles, de statuts, paiements manuels, remboursements). Il ne peut être ni modifié ni effacé.
- Ne partagez jamais un compte ; chaque personne a le sien.
- Les mots de passe des prestataires de paiement se trouvent uniquement dans la configuration du serveur, jamais dans l'application ni dans un email.

## 5. En cas d'incident
| Situation | Action |
|---|---|
| Paiements bloqués « en attente » | **Paiements** › **Rapprocher** ; vérifier l'espace marchand du prestataire ; prévenir l'équipe technique |
| Prestataire de paiement en panne | Désactiver temporairement le moyen dans **Paramètres** ; les commandes restent enregistrées |
| Compte compromis | Désactiver le compte, changer les mots de passe, consulter le journal d'audit, prévenir l'équipe technique |
| Fuite de données suspectée | Prévenir immédiatement la direction et l'équipe technique ; obligations de notification à examiner avec le conseil juridique (CDP) |

Procédures techniques (sauvegardes, restauration, déploiement) : `deploy/` et `docs/DEV-ARCHITECTURE.md`.
