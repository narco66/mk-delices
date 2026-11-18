# Annexe A2 — Projet de mention « géolocalisation » pour la politique de confidentialité

**Pour :** le conseil juridique de MK Délices, qui valide les textes légaux avant publication.
**Date :** 18/11/2026.
**Statut :** projet, **non publié**. La politique en ligne reste le texte provisoire en attente de revue.

Chaque paragraphe décrit le fonctionnement réel de l'application, vérifié dans le code et par les tests (voir `../06-RAPPORT-DE-TESTS.md`). Les passages entre crochets restent à compléter par MK Délices ou son conseil.

---

## À ajouter dans « Données collectées »

**Position de livraison (facultative).** Lors d'une commande, ou dans votre carnet d'adresses, vous pouvez indiquer votre position sur une carte ou par le bouton « Utiliser ma position actuelle ».

Votre position n'est demandée **que lorsque vous appuyez sur ce bouton**, après l'autorisation de votre navigateur. Vous pouvez la refuser : la saisie de votre quartier et d'un point de repère suffit pour commander.

Nous n'enregistrons votre position que si vous la confirmez, avec la commande ou l'adresse concernée. Nous ne suivons jamais votre position en continu.

## À ajouter dans « Finalités »

La position de livraison sert uniquement à :
- vérifier que l'adresse se trouve dans notre périmètre de livraison (actuellement la ville de Dakar) et appliquer les frais de la zone correspondante ;
- guider le livreur jusqu'à vous.

## À ajouter dans « Destinataires »

- **Le livreur affecté à votre commande** voit votre position **uniquement après avoir accepté la course**, et pendant celle-ci.
- **Le personnel habilité de MK Délices** (commercial, responsable des livraisons) la consulte pour vérifier l'adresse.
- **Le fournisseur de cartes [MapTiler AG, Suisse]** reçoit, quand la carte s'affiche, la zone affichée et l'adresse IP de votre appareil (comme tout site consulté), mais ni votre nom ni votre commande.
- **La recherche d'adresse**, lorsqu'elle est activée, est transmise par nos serveurs à ce même fournisseur, sans donnée vous identifiant.

## À ajouter dans « Durée de conservation »

- **Position associée à une commande** : effacée automatiquement **90 jours** après le créneau de livraison, une fois la commande terminée (livrée, annulée ou refusée). L'adresse écrite (quartier, rue, repère) reste conservée avec la commande et la facture, [pendant la durée légale de conservation des pièces comptables].
- **Position enregistrée dans votre carnet d'adresses** : conservée tant que vous gardez cette adresse. Vous pouvez la modifier ou la supprimer à tout moment depuis votre espace client.
- **Statistiques** : seulement des totaux par zone, sans aucune position individuelle.

## Points à trancher par le conseil juridique

1. **Déclaration auprès de la CDP.** Indiquer si le traitement de la position relève d'une déclaration ou d'une demande d'avis, au titre de la loi n° 2008-12 du 25 janvier 2008, et mentionner le numéro de récépissé.
2. **Transfert hors du Sénégal.** Le fournisseur de cartes est établi en Suisse : préciser la base du transfert (articles de la loi n° 2008-12 relatifs aux transferts vers un pays tiers).
3. **Durée de 90 jours.** Elle est retenue par MK Délices (décision du 18/11/2026) par analogie avec la recommandation CNIL de 2 mois pour l'historique de localisation, plus une marge pour les réclamations. Confirmer qu'elle convient au délai de réclamation prévu par les CGV.
