# Cahier de recette — MK Délices

**Pour :** l'équipe MK Délices qui teste l'application avant son ouverture au public (gérance, commercial, préparation, livraisons, livreurs).
**Où :** la préproduction, https://srv2163043.hstgr.cloud — identifiant et mots de passe communiqués séparément (jamais par écrit dans ce document).
**Quand :** phase P6 du plan de développement. La recette est terminée quand tous les tests sont passés et qu'**aucun problème bloquant** ne reste ouvert.

> **Tout est fictif en préproduction.** Aucun paiement n'est réel (choisir « Paiement simulé »), aucun email ni SMS n'est envoyé, aucun livreur ne se déplace. Vous pouvez tout essayer sans risque : passer des commandes, les annuler, rembourser, déclarer des échecs de livraison…

## 1. Comment remplir ce cahier

Pour chaque test : suivez les étapes, comparez avec le **résultat attendu**, puis notez :
- **OK** : tout se passe comme décrit ;
- **KO** : quelque chose ne va pas → remplissez une fiche d'anomalie (§ 9) ;
- **Remarque** : ça fonctionne, mais un libellé, une couleur ou une étape pourrait être améliorée.

Testez sur les appareils réellement utilisés : **téléphone** pour la boutique et les livreurs, **ordinateur** pour le back-office. Un même test peut être fait par plusieurs personnes : chacune remplit sa ligne.

**Comptes de démonstration** (mot de passe communiqué séparément) : `admin@`, `commercial@`, `preparateur@`, `livraison@`, `livreur1@`, `livreur2@`, `client1@` à `client3@` — tous en `@mk-delices.test`.

**Données à utiliser :** la **fiche du jeu d'essai** indique, pour chaque scénario, la commande (`MKD-…`), le code de remise ou le lien de suivi préparés pour le test. L'équipe technique la remet avant chaque session, après `php artisan mk:demo-refresh`. Voir [JEU-D-ESSAI.md](JEU-D-ESSAI.md).

## 2. Boutique (client, sur téléphone)

| N° | Test | Étapes | Résultat attendu | OK / KO | Remarques |
|---|---|---|---|---|---|
| R-01 | Découvrir la boutique | Ouvrir le site sur téléphone, parcourir l'accueil | Tiramisus, produits populaires et nouveautés affichés ; rien ne déborde de l'écran ; textes lisibles | | |
| R-02 | Fiche produit | Ouvrir « Tiramisu Classique » | Prix, formats, **allergènes** et consignes de conservation présents et exacts | | |
| R-03 | Panier | Ajouter 2 coupes, changer la quantité, retirer un article | Total recalculé à chaque changement | | |
| R-04 | Minimum de commande | Panier de 2 500 FCFA, zone avec minimum 5 000 FCFA | Commande bloquée avec une explication claire | | |
| R-05 | Commander sans compte | Panier ≥ minimum → Commander → prénom, téléphone, zone, quartier, créneau, accepter les CGV → Confirmer | Écran de paiement avec la référence MKD-… ; frais de livraison corrects selon la zone | | |
| R-06 | Payer | « Paiement simulé » → « Simuler un paiement réussi » | « Paiement confirmé » ; la commande apparaît « Payée » dans le suivi | | |
| R-07 | Paiement échoué | Nouvelle commande → « Simuler un échec » → réessayer | Message clair ; nouvelle tentative possible ; aucune commande en double | | |
| R-08 | Suivre sa commande | Ouvrir le lien de suivi reçu | Étapes de la commande, articles, montant ; aucune donnée d'un autre client | | |
| R-09 | Annuler | Sur une commande pas encore acceptée : Annuler avec un motif | Commande annulée ; si elle était payée, le remboursement est pris en charge par l'équipe | | |
| R-10 | Commander par WhatsApp | Panier → « Envoyer mon panier sur WhatsApp » | WhatsApp s'ouvre avec un message prérempli contenant la référence ; la commande n'est **pas** considérée comme payée | | |
| R-11 | Créer un compte | Inscription → commander connecté | La commande apparaît dans « Mon compte » ; adresse enregistrable | | |
| R-12 | Facture | Après livraison (voir § 5), ouvrir le suivi ou « Mon compte » | Bouton « PDF » : facture lisible, montants exacts (mention « document de test » normale en préproduction) | | |
| R-13 | Pages d'information | CGV, confidentialité, mentions légales, livraison, contact | Textes présents ; signaler ce qui doit être complété | | |
| R-14 | Clavier seul (ordinateur) | Passer une commande sans souris (Tab, Entrée, flèches) | Toujours possible de voir où l'on se trouve ; commande aboutie | | |

## 3. Commercial (ordinateur)

| N° | Test | Étapes | Résultat attendu | OK / KO | Remarques |
|---|---|---|---|---|---|
| C-01 | Tableau de bord | Se connecter en `commercial@` | Commandes à confirmer, paiements, chiffres du jour | | |
| C-02 | Accepter | Ouvrir la commande payée de R-06 → Accepter | Commande « Acceptée » ; elle apparaît chez le préparateur | | |
| C-03 | Paiement non confirmé | Commande non payée en ligne → Accepter | Refusé tant que le prestataire n'a pas confirmé le paiement | | |
| C-04 | Refuser une commande payée | Refuser avec un motif | Commande refusée ; demande de remboursement ouverte automatiquement | | |
| C-05 | Saisir une commande téléphone | Commandes → Nouvelle commande → articles, client, zone, créneau → Créer | Commande créée, lien de suivi copié ; prix et frais identiques au site | | |
| C-06 | Brouillon WhatsApp | Ouvrir un brouillon (R-10) → compléter → Confirmer | Brouillon transformé en commande ; code promo revérifié | | |
| C-07 | Encaissement manuel | Commande téléphone → Enregistrer un encaissement (espèces) | Commande « Payée » ; facture émise une fois livrée | | |
| C-08 | Modifier une commande | Changer adresse ou créneau avant préparation | Modification enregistrée et visible dans l'historique ; impossible après démarrage de la préparation | | |
| C-09 | Demander un remboursement | Sur une commande payée → Demander un remboursement partiel | Demande créée, **en attente d'approbation par une autre personne** | | |
| C-10 | Catalogue | Modifier un prix, ajouter une photo, masquer un produit | Effet visible sur la boutique ; les commandes déjà passées gardent leur ancien prix | | |
| C-11 | Promotions | Créer un code, l'utiliser sur la boutique, le désactiver | Remise appliquée puis refusée une fois désactivé | | |
| C-12 | Avis clients | Approuver / refuser un avis | Seuls les avis approuvés apparaissent sur la fiche produit | | |
| C-13 | Factures | Finances → Factures → PDF et Export comptable (CSV) | Numérotation continue ; export lisible dans Excel | | |
| C-14 | Statistiques | Statistiques sur 30 jours | Chiffre d'affaires, panier moyen, meilleures ventes cohérents | | |

## 4. Préparation (ordinateur ou tablette)

| N° | Test | Étapes | Résultat attendu | OK / KO | Remarques |
|---|---|---|---|---|---|
| P-01 | Tableau de préparation | Se connecter en `preparateur@` | Trois colonnes ; commandes triées par créneau ; allergènes et consignes visibles ; pas de téléphone client | | |
| P-02 | Préparer | Démarrer la préparation → Marquer prête (cocher le contrôle) | La commande passe en « Prêtes » ; « Confirmer » inactif tant que le contrôle n'est pas coché | | |
| P-03 | Erreur de démarrage | Démarrer la mauvaise commande → « Annuler le démarrage » avec un motif | La commande revient dans « À préparer » ; impossible après 15 minutes | | |
| P-04 | Droits limités | Tenter d'ouvrir une ancienne commande livrée ou le menu Finances | Accès refusé | | |

## 5. Livraisons et livreurs

| N° | Test | Étapes | Résultat attendu | OK / KO | Remarques |
|---|---|---|---|---|---|
| L-01 | Affecter | `livraison@` → Livraisons → choisir « Livreur Démo A » → Affecter | Mission proposée au livreur | | |
| L-02 | Accepter la mission (téléphone) | `livreur1@` → mission → Accepter | Adresse et téléphone du client visibles **seulement après** acceptation | | |
| L-03 | Livrer | Confirmer l'enlèvement (contrôle) → Livrée → preuve | Commande « Livrée » ; facture émise automatiquement | | |
| L-04 | Paiement à la livraison | Commande téléphone non payée → livrer en saisissant le montant encaissé | Commande « Payée » ; un montant inférieur est signalé dans l'historique | | |
| L-05 | Échec de livraison | Déclarer « Client absent » | Commande « Échec de livraison » ; course rémunérée à 50 % ; l'équipe est alertée | | |
| L-06 | Réaffecter | Après un échec, réaffecter | Nouvelle mission ; un second échec alerte de nouveau | | |
| L-07 | Refuser une mission | Livreur → Refuser avec un motif | Commande de nouveau disponible pour une autre affectation | | |
| L-07b | Rendre une mission acceptée | Livreur : Accepter → Rendre (motif « panne ») | Commande de nouveau « à affecter » ; le responsable reçoit une notification | | |
| L-07c | Livreur indisponible | Livreur : passer « Indisponible » → le responsable tente de lui affecter une commande | Livreur grisé, affectation impossible | | |
| L-07d | Mission sans réponse | Affecter une mission et ne pas l'accepter pendant 10 minutes | Le responsable reçoit « Mission non acceptée » | | |
| L-08 | Confidentialité | `livreur2@` tente d'ouvrir une mission de `livreur1@` | Accès refusé | | |
| L-09 | Règlements | Livraisons → Règlements → régler un livreur | Montant dû et espèces détenues remis à zéro pour les missions réglées | | |

## 5 bis. Géolocalisation et zones (téléphone, puis ordinateur)

Sur la page « Commander ». Pour simuler une position ailleurs qu'à l'endroit où l'on se trouve, utiliser « Choisir sur la carte » ou la recherche.

| N° | Scénario | Étapes | Résultat attendu | OK / KO | Remarque |
|---|---|---|---|---|---|
| G-01 | Position acceptée | « Utiliser ma position actuelle » → autoriser → « Confirmer cette position » | Le téléphone demande l'autorisation **seulement** au clic ; « Position de livraison confirmée » | | |
| G-02 | Position refusée | Même bouton → refuser l'autorisation | Message clair ; la commande reste possible avec zone, quartier et repère | | |
| G-03 | Carte | « Choisir sur la carte » → toucher la carte, faire glisser le repère | Carte lisible sur téléphone, repère déplaçable, mention © MapTiler / OpenStreetMap | | |
| G-04 | Recherche d'adresse | Taper « Sacré Coeur 3 » → choisir un résultat → confirmer | Quartier (et rue si connue) remplis automatiquement, toujours modifiables | | |
| G-05 | Hors de Dakar | Placer le repère à Pikine ou Rufisque | « En dehors de notre périmètre de livraison », lien WhatsApp ; impossible de confirmer cette position | | |
| G-06 | Frais affichés = frais payés | Changer de zone juste avant de confirmer | Le bouton affiche « … » pendant le recalcul ; le total payé est celui affiché | | |
| G-07 | Adresses du compte | `client1@` → Mon compte → Adresses : ajouter une adresse avec position, la modifier, « Définir par défaut » | Une seule adresse par défaut ; elle est proposée automatiquement au moment de commander | | |
| G-08 | Fiche commande (commercial) | Ouvrir une commande passée avec une position | Ligne « Position » : lien « Voir sur la carte », origine (GPS, carte…), précision | | |
| G-09 | Zone non vérifiée | Commandes → filtre « Zone vérifiée ? : Non, choisie par le client » | Liste des commandes dont la zone doit être contrôlée avant acceptation ; alerte orange sur la fiche | | |
| G-10 | Itinéraire du livreur | `livreur1@` → mission acceptée d'une commande avec position → « Itinéraire » | Google Maps (ou l'application du téléphone) s'ouvre sur le point exact | | |
| G-11 | Périmètre desservi | `admin@` → Opérations → Zones → « Périmètre desservi » | Contour de la ville de Dakar affiché sur la carte ; modification réservée à l'administrateur | | |
| G-12 | Statistiques par zone | Statistiques → tableau « Par zone de livraison » | Commandes, chiffre d'affaires, livraisons et part « avec position » par zone ; aucune position individuelle | | |

## 6. Administration et finances (`admin@`)

| N° | Test | Étapes | Résultat attendu | OK / KO | Remarques |
|---|---|---|---|---|---|
| A-01 | Double contrôle des remboursements | Approuver la demande de C-09 (avec un autre compte que celui qui l'a créée) | Remboursement effectué ; **avoir** émis si la commande était facturée | | |
| A-02 | Auto-approbation impossible | Le commercial tente d'approuver sa propre demande | Refusé | | |
| A-03 | Utilisateurs | Créer un compte d'équipe, le désactiver | Le compte désactivé ne peut plus se connecter | | |
| A-04 | Paramètres | Modifier horaires, jours fermés, TVA (activer puis désactiver) | Effet visible sur les créneaux et sur les factures suivantes | | |
| A-05 | Journal d'audit | Consulter le journal | Les actions sensibles des tests précédents y figurent | | |

## 7. Ce qui ne peut PAS être recetté en préproduction

- **Paiements réels** (Wave) : en attente du contrat et des accès de test Wave.
- **Emails et SMS** aux clients : volontairement non envoyés en préproduction.
- **Textes juridiques définitifs** : en attente de la relecture par un juriste.
- **Photos définitives et logo** : en attente de la séance photo.
- **GPS en conditions réelles** : la précision en intérieur et sur les téléphones d'entrée de gamme se vérifie sur le terrain, à Dakar (G-01 avec deux ou trois téléphones différents).

Ces points seront vérifiés lors d'une **courte recette complémentaire** juste avant l'ouverture.

## 8. Critères de passage en production (go / no-go)

| Critère | Statut |
|---|---|
| Tous les tests ci-dessus réalisés (OK ou anomalie documentée) | ☐ |
| Aucune anomalie **bloquante** ouverte | ☐ |
| Anomalies **majeures** corrigées ou acceptées par écrit par la gérance | ☐ |
| Décisions de lancement prises : contrat Wave, textes juridiques validés, NINEA / RCCM / raison sociale, TVA, domaine, numéro WhatsApp | ☐ |
| Sauvegardes hors serveur et surveillance actives | ☐ |
| Équipe formée (guides dans `docs/guides/`) | ☐ |

**Décision :** ☐ Go ☐ No-go — Date : ………… — Nom et signature de la gérance : …………………………

## 9. Fiche d'anomalie

Un problème = une fiche (copier le modèle) :

```
N° de test : (ex. R-05)                Gravité : Bloquant / Majeur / Mineur
Testé par :                            Date et heure :
Appareil et navigateur : (ex. Samsung A15, Chrome)
Ce que j'ai fait :
Ce que j'attendais :
Ce qui s'est passé : (joindre une capture d'écran ; noter la référence de commande MKD-… et, si un message d'erreur affiche une référence, la recopier)
```

**Gravité :**
- **Bloquant** : empêche de commander, de payer, de préparer ou de livrer, ou expose des données personnelles → interdit la mise en production.
- **Majeur** : fonctionne mal mais un contournement existe (ex. mauvais montant affiché, alerte non reçue).
- **Mineur** : présentation, libellé, confort d'utilisation.
