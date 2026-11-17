# Guide du responsable des livraisons

Pour : la personne qui organise les livreurs partenaires.
Connexion : `https://<votre-site>/connexion`, puis menu **Livraisons**.

## 1. Préparer le terrain
- **Zones & tarifs** : liste des zones desservies avec frais client, minimum de commande et **rémunération du livreur** par course. (La modification des zones est réservée à l'administrateur.)
- **Livreurs** : créez le compte de chaque livreur (nom, téléphone, email, mot de passe provisoire, moyen de transport) et cochez **les zones qu'il dessert**. Un livreur ne peut recevoir que des commandes de ses zones. Décochez « Actif » pour suspendre un livreur.

## 2. Affecter une commande
Dans **Livraisons**, la section « À affecter » liste les commandes **prêtes** (et celles en échec de livraison).
1. Dans la liste déroulante de la commande, choisissez un livreur. Seuls les livreurs **actifs et éligibles à la zone** sont proposés, avec leur nombre de missions en cours ; ceux qui se sont déclarés **indisponibles** apparaissent grisés et ne peuvent pas être choisis.
2. Cliquez sur **Affecter**. Le livreur voit la mission « proposée » sur son téléphone.
3. Quand il **accepte**, la commande passe « Confiée au livreur » et il voit alors l'adresse et le téléphone du client.

Une commande ne peut avoir qu'**une seule mission active** : une deuxième affectation est refusée.
Si un livreur **refuse** (avec motif), la commande redevient disponible : réaffectez-la.
Pour retirer une mission **avant l'enlèvement** (livreur indisponible), utilisez **Retirer la mission** avec un motif.
Le livreur peut aussi **rendre** lui-même une mission acceptée avant l'enlèvement (panne, urgence) : vous recevez une notification « Commande prête : à affecter » et la commande réapparaît dans « À affecter ».
**Mission sans réponse :** si un livreur n'a pas accepté une mission au bout de **10 minutes** (réglable : Paramètres › `delivery.accept_timeout_minutes`), vous recevez une notification « Mission non acceptée » : relancez-le ou retirez la mission pour la confier à un autre livreur.

## 3. Incidents de livraison
Quand un livreur déclare un **échec** (client absent, adresse introuvable, client injoignable, refus, produit endommagé…), la commande apparaît dans **Incidents à traiter** avec :
le motif, sa note, l'état du produit, l'heure et s'il a tenté de joindre le client.

Décidez :
- **Relivrer** : réaffectez la commande (même livreur ou un autre) ;
- **Annuler** : demandez au commercial d'annuler depuis la fiche commande (motif, traitement financier).

Si le produit a rompu la chaîne du froid, ne le relivrez pas : appliquez la procédure sanitaire validée par MK Délices.

## 4. Règlement des livreurs
**Règlements livreurs** affiche, par livreur : les courses livrées non réglées, le **montant dû** et les **espèces détenues** (paiement à la livraison, si activé).
Après avoir payé un livreur, cochez les courses concernées et cliquez sur **Marquer … réglée(s)**. L'opération est tracée.
Seules les courses **livrées** sont dues (règle provisoire, à confirmer par MK Délices).
