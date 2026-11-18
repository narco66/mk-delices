# Guide du gestionnaire commercial

Pour : la personne qui confirme les commandes, suit les paiements, gère le catalogue et les promotions.
Connexion : `https://<votre-site>/connexion` avec votre email et votre mot de passe. Vous arrivez sur **Vue générale**.

## 1. Votre tableau de bord
Les cartes du haut indiquent ce qui demande une action : **À confirmer**, **Brouillons WhatsApp**, **Paiements en attente**, **Livraisons échouées**… Cliquez sur une carte pour ouvrir la liste correspondante. La cloche en haut à droite signale chaque nouvelle commande.

## 2. Confirmer une commande
1. **Commandes** › ouvrez la commande (statut « En attente de confirmation »).
2. Vérifiez : articles, allergènes, créneau, adresse et repère, consignes du client.
   - **Position** : si le client a indiqué sa position, la ligne « Position » propose **« Voir sur la carte »**, avec son origine (GPS, repère sur la carte, recherche, adresse enregistrée) et sa précision. Une précision de plusieurs centaines de mètres invite à confirmer le repère par téléphone.
   - **« Zone choisie par le client, non vérifiée »** (encadré orange) : contrôlez que le quartier appartient bien à la zone choisie, car les frais en dépendent. Le filtre **« Zone vérifiée ? : Non »** de la liste des commandes les regroupe.
   - **Adresse hors de Dakar** : le site refuse la commande en ligne et oriente le client vers WhatsApp. C'est à vous de décider d'une livraison sur devis.
3. Regardez le badge de **paiement** :
   - « Payée » : le prestataire a confirmé, vous pouvez cliquer sur **Accepter** ;
   - « Paiement en vérification » ou « Non payée » pour un paiement en ligne : l'application **bloque** l'acceptation tant que le prestataire n'a pas confirmé. N'acceptez jamais sur la foi d'une capture d'écran envoyée par le client.
4. Si vous ne pouvez pas honorer la commande : **Refuser**, avec un motif (obligatoire). Si le client a déjà payé, une **demande de remboursement** est créée automatiquement ; un administrateur devra l'approuver.

> Le statut de la commande et le statut du paiement sont indépendants : « Payée » ne veut pas dire « acceptée », et « Acceptée » ne veut pas dire « payée ».

### Saisir une commande prise par téléphone ou à la boutique
**Commandes** › **Nouvelle commande** :
1. **Articles** : choisissez le produit et la quantité, puis **Ajouter** (les boutons − / + / corbeille ajustent chaque ligne). Les prix sont ceux du catalogue, recalculés par le serveur.
2. **Client** : prénom et téléphone obligatoires ; l'email permet d'envoyer la confirmation et la facture.
3. **Livraison** : zone (les frais s'ajoutent), quartier, repère, puis un **créneau** parmi ceux réellement disponibles (mêmes délais et capacités que sur le site).
4. **Validation** : canal (Téléphone ou Boutique), paiement, code promo éventuel, note interne, puis **Créer la commande**.

La commande est créée « En attente de confirmation » : vous êtes redirigé vers sa fiche pour l'**accepter**. Le **lien de suivi** est copié automatiquement : envoyez-le au client par WhatsApp ou SMS.
- **Paiement « Encaissement à enregistrer »** (espèces, transfert reçu…) : la commande peut être acceptée sans paiement en ligne ; enregistrez l'encaissement depuis sa fiche (**Enregistrer un encaissement**) dès réception.
- **Paiement « Lien de paiement »** : le client paie en ligne depuis son lien de suivi ; l'acceptation reste bloquée jusqu'à la confirmation du prestataire.

## 3. Commandes reçues par WhatsApp
Quand un client envoie son panier par WhatsApp, une commande **Brouillon** apparaît avec la même référence que dans son message (`MKD-XXXX-XXXX`).
1. Échangez avec le client sur WhatsApp (créneau, adresse).
2. Ouvrez le brouillon › **Modifier les informations** : prénom, téléphone, zone, quartier, créneau.
3. Cliquez sur **Confirmer le brouillon** : les prix sont recalculés au tarif du jour et le créneau est réservé.
4. Si le client paie hors ligne (espèces, transfert direct), utilisez **Enregistrer un encaissement** : montant, moyen, motif. Cette action est tracée.

Un même panier ne peut pas créer deux commandes : si le client finalise ensuite sur le site, c'est le même dossier.

## 4. Modifier une commande
Les coordonnées et le créneau sont modifiables **jusqu'au début de la préparation** ; ensuite ils sont figés. Après un encaissement, les frais de livraison ne peuvent plus changer : annulez et recréez la commande si nécessaire.

## 5. Annuler
**Annuler** (motif obligatoire) est possible avant la livraison. Le stock n'est restitué que si la préparation n'avait pas commencé. Un paiement déjà encaissé ouvre automatiquement une demande de remboursement.

## 6. Paiements et remboursements
- **Paiements** : toutes les tentatives et les notifications reçues des prestataires. **Rapprocher** interroge le prestataire si un paiement reste « en attente ».
- **Remboursements** : vous pouvez **demander** un remboursement depuis une commande (montant ≤ montant encaissé). L'**approbation** est faite par une autre personne (double contrôle).
- **Factures** (Finances › Factures) : la facture est émise **automatiquement** quand une commande payée est livrée, et envoyée par email au client (PDF). Pour un client qui la demande avant la livraison : bouton **« Émettre la facture »** sur la commande (commande intégralement payée). Une facture ne se modifie jamais : un remboursement effectué produit automatiquement un **avoir**. L'**export comptable (CSV)** donne, sur une période, toutes les factures et avoirs (avoirs en négatif) pour l'expert-comptable.

## 7. Catalogue
**Catalogue** › **Créer un produit** : nom, catégorie, description, **ingrédients et allergènes (obligatoires avant publication)**, conservation, formats et prix en FCFA. Le produit est créé en **brouillon**, invisible des clients.
- **Ajouter** une photo : JPEG, PNG ou WebP, au moins 400 px de côté, avec un texte alternatif qui décrit l'image. Cochez « Photo d'illustration » si elle ne représente pas exactement le produit vendu.
- **Publier** rend le produit commandable ; **Désactiver** le retire immédiatement de la vente.
- Changer un prix n'affecte **jamais** les commandes déjà passées.

## 8. Promotions, avis, contenus
- **Promotions** › **Nouveau code** : pourcentage ou montant, plafond, dates, nombre d'utilisations. Un seul code par commande.
- **Avis** : approuvez ou rejetez les avis avant publication.
- **Contenus du site** : pages (Notre histoire, Livraison, CGV…) et blocs de l'accueil. Une page en « Brouillon » n'est pas visible en ligne.

## Bonnes pratiques
- Ne communiquez jamais le lien de suivi d'un client à un tiers.
- Toute action (acceptation, refus, paiement manuel) est enregistrée avec votre nom et l'heure.
