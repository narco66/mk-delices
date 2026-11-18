# 08 — Guide d'utilisation : localisation et zones de livraison

**Pour :** clients, livreurs et équipe de MK Délices.
**Date :** 18/11/2026.

Selon la configuration, la carte et la recherche d'adresse peuvent ne pas être encore disponibles (voir le guide 07). Tout ce qui suit fonctionne sans elles, sauf mention contraire.

## 1. Clients

### Indiquer où livrer (page « Commander »)

1. Dans « Où livrer ? », appuyez sur **Utiliser ma position actuelle**. Le téléphone vous demande l'autorisation.
   - Si vous refusez, ce n'est pas grave : remplissez la zone, le quartier et un point de repère, et commandez normalement.
2. Vérifiez la zone et les frais affichés, puis appuyez sur **Confirmer cette position**.
3. Si la carte est disponible, vous pouvez aussi :
   - toucher la carte ;
   - faire glisser le repère ;
   - chercher un quartier ou un lieu connu (« Sacré-Cœur 3 »).
4. Le message « Position approximative » signale un repère imprécis (souvent à l'intérieur) : déplacez-le, ou précisez bien le point de repère.
5. Nous livrons actuellement la **ville de Dakar**. Le message « Adresse hors de nos zones de livraison » signifie qu'aucune commande en ligne n'est possible à cet endroit. Écrivez-nous sur WhatsApp (lien proposé) pour une livraison sur devis, ou choisissez le retrait si MK Délices le propose.

**Les frais affichés sont ceux qui seront facturés.** Pendant leur recalcul, le bouton « Confirmer » affiche « … » : attendez une seconde.

### Mes adresses (espace client)

- Ajoutez une adresse avec un nom (Maison, Bureau), le quartier, la rue, un repère et, si vous le souhaitez, la position.
- **Définir par défaut** : cette adresse sera proposée automatiquement à la commande.
- Le crayon permet de modifier une adresse (ajouter ou corriger la position, par exemple).
- La position sert uniquement à la livraison. Le livreur ne la voit qu'après avoir accepté votre course.

## 2. Livreurs

- La position du client apparaît **après l'acceptation** de la mission.
- **Itinéraire** ouvre Google Maps (ou l'application de navigation du téléphone) directement sur le point de livraison.
- Sans position, le lien cherche « rue, repère, quartier, Dakar » : appelez le client en cas de doute.
- Votre propre position n'est **jamais** suivie par l'application.

## 3. Équipe de MK Délices

### Zones (Opérations, Zones), rôle « gestion des zones »

- Frais, minimum de commande, rémunération du livreur, délai supplémentaire, activation.
- **Contour sur la carte** :
  - cliquer pour ajouter un point, glisser pour déplacer ;
  - « Retirer le dernier point » ou « Effacer le contour » pour corriger ;
  - ou importer un fichier GeoJSON ;
  - les autres zones apparaissent en pointillés.
- **Ordre d'affichage** : si deux contours se chevauchent, le plus petit numéro l'emporte.
- Modifier les frais ne change pas les commandes déjà passées. Le nouveau tarif s'applique au prochain devis.

### Périmètre desservi (Opérations, Zones)

- La carte « Périmètre desservi » indique où MK Délices livre. Aujourd'hui : la **ville de Dakar**, sans Gorée.
- Une position hors du périmètre ne peut pas commander en ligne. Le client est invité à écrire sur WhatsApp, et le commercial peut toujours saisir la commande lui-même.
- **Modifier le périmètre** : dessiner sur la carte ou importer un contour GeoJSON. Chaque modification est tracée dans le journal.

### Commandes

- Liste des commandes, deux nouveaux filtres :
  - **Zone** : une zone, ou « Sans zone (retrait) » ;
  - **Zone vérifiée ?** : « Non, choisie par le client » isole les commandes à contrôler.
- Fiche d'une commande, ligne **Position** :
  - lien « Voir sur la carte » ;
  - origine (GPS, carte, recherche, adresse enregistrée, saisie par l'équipe) ;
  - précision.
- L'encadré orange « Zone choisie par le client, non vérifiée » demande de contrôler le quartier avant d'accepter.

### Statistiques

Le tableau « Par zone de livraison » indique, par zone :
- commandes ;
- chiffre d'affaires ;
- frais de livraison ;
- livrées et échecs ;
- retards ;
- trajet moyen ;
- part des commandes avec position.

Il ne montre aucune position individuelle.
