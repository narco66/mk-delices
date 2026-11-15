# Guide du préparateur

Pour : la personne qui prépare les desserts à l'atelier.
Connexion : `https://<votre-site>/connexion`, puis menu **Préparation**.

## L'écran de préparation
Trois colonnes :
- **À préparer** : commandes acceptées par le commercial ;
- **En préparation** : commandes que vous avez démarrées ;
- **Prêtes** : commandes conditionnées, en attente d'un livreur.

Chaque carte indique : la référence, le **créneau de livraison** (les plus urgentes en premier, badge « urgent » à moins de 2 h), les produits et quantités, les **allergènes**, la **conservation**, les **consignes du client** et un badge **Produit frais** quand la chaîne du froid est requise. Le téléphone et l'adresse du client ne vous sont pas affichés : ils ne sont pas nécessaires à la préparation. L'écran se met à jour tout seul chaque minute ; vous pouvez filtrer par jour.

## Préparer une commande
1. Sur la carte, cliquez sur **Démarrer la préparation**. Les coordonnées et le créneau de la commande sont alors figés.
2. Préparez les produits en respectant les consignes de conservation affichées.
3. Cliquez sur **Marquer prête**. Une fenêtre vous demande de confirmer le contrôle :
   - conditionnement et quantités vérifiés ;
   - pour un produit frais : **maintien au froid** vérifié.
   Le bouton **Confirmer** ne s'active qu'une fois la case cochée.
4. La commande passe dans **Prêtes** ; le responsable des livraisons est prévenu.

Il n'y a ni « ajouter » ni « supprimer » sur cet écran : chaque carte est une **commande** acceptée par le commercial, qui arrive ici toute seule. La référence ouvre la fiche complète de la commande.

**Démarrage par erreur ?** Sur la carte, **Annuler le démarrage** (affiché avec l'heure limite) remet la commande dans « À préparer » et la rend de nouveau modifiable. Un motif est demandé et conservé dans l'historique. Ce bouton disparaît après le délai de correction (15 minutes par défaut, réglable par l'administrateur : Paramètres › `preparation.undo_window_minutes`).

## En cas de problème
- **Ingrédient manquant ou produit impropre** : ne marquez pas la commande prête. Prévenez immédiatement le commercial, qui contactera le client et décidera (report ou annulation avec remboursement).
- **Retard** : prévenez le responsable des livraisons pour qu'il anticipe.
- Vous ne pouvez pas accepter, refuser ou annuler une commande : ces décisions appartiennent au commercial.
