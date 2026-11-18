# Plan de formation — MK Délices

**Pour :** la direction de MK Délices (organisation) et la personne qui anime les séances.
**Où :** sur la **préproduction** (données fictives : on peut tout essayer sans risque). Chaque participant vient avec **l'appareil qu'il utilisera au travail** : téléphone pour les livreurs, ordinateur ou tablette pour l'atelier et le bureau.
**Supports :** les guides de `docs/guides/`, à remettre imprimés ou en PDF à chaque participant.

## 1. Séances

| Séance | Public | Durée | Guide | Objectif : à la fin, la personne sait… |
|---|---|---|---|---|
| S1 | Tous | 30 min | — | Le circuit d'une commande : client → commercial → atelier → livreur → facture. Qui fait quoi, qui prévenir en cas de problème |
| S2 | Commercial | 1 h 30 | `GUIDE-COMMERCIAL.md` | Confirmer ou refuser une commande, saisir une commande reçue par WhatsApp ou téléphone, modifier, annuler, suivre un paiement, demander un remboursement, **dicter le code de remise** au client d'une commande téléphone, vérifier une **remise sans code**, contrôler une **zone non vérifiée** (filtre et alerte) à l'aide de la position du client |
| S3 | Préparateurs | 45 min | `GUIDE-PREPARATEUR.md` | Lire la file de préparation, démarrer, signaler un problème, marquer prêt, vérifier la chaîne du froid |
| S4 | Responsable des livraisons | 1 h 15 | `GUIDE-RESPONSABLE-LIVRAISONS.md` | Gérer livreurs et zones (tarifs, **périmètre desservi**, contours sur la carte), affecter et réaffecter, réagir à une mission non acceptée ou à un **code bloqué**, décider après un échec, régler les livreurs et récupérer les espèces |
| S5 | Livreurs | 45 min | `GUIDE-LIVREUR.md` | Se connecter, **activer les notifications**, accepter / refuser / rendre une mission, enlever, **ouvrir l'itinéraire** vers la position du client, **demander le code de remise**, déclarer un échec (contact + 10 min d'attente) |
| S6 | Administrateur | 1 h | `GUIDE-ADMINISTRATEUR.md` | Créer et désactiver des comptes, attribuer les rôles, valider un remboursement (double contrôle), modifier les paramètres, lire le journal d'audit, réagir à un incident |

Effectif conseillé : 6 personnes maximum par séance. S2 à S5 peuvent se faire le même jour, puis l'**exercice commun** (§ 2).

## 2. Exercice commun (1 h, toute l'équipe)

Chacun à son poste, sur la préproduction, l'animateur joue le client :

1. Commande en ligne payée (simulateur), avec la **position** choisie sur la carte ou par recherche d'adresse, confirmée par le commercial.
2. Préparée et marquée prête par l'atelier.
3. Affectée par le responsable ; le livreur reçoit la **notification** sur son téléphone, accepte, enlève.
4. Livraison avec le **code** donné par le « client » ; vérifier la facture émise.
5. Deuxième commande : **client absent** → échec déclaré par le livreur, décision du responsable, relivraison.
6. Troisième commande reçue par **WhatsApp**, saisie par le commercial, payée à la livraison si ce mode est activé.
7. Remboursement d'une commande annulée : demandé par le commercial, validé par l'administrateur.
8. Adresse **hors de Dakar** (Pikine) : le client est orienté vers WhatsApp ; le commercial saisit la commande sur devis s'il l'accepte.

## 3. Avant les séances (technique)

- Un compte **nominatif** de préproduction par participant (jamais de compte partagé), avec le bon rôle.
- Données de démonstration rechargées si besoin ; notifications des livreurs actives.
- Téléphones des livreurs : Android avec Chrome à jour ; iPhone en iOS 16.4 ou plus, application ajoutée à l'écran d'accueil.
- Identifiants d'accès à la préproduction transmis individuellement, **jamais** par un groupe WhatsApp.

## 4. Fiche de validation

Cocher quand la personne a réalisé le geste **seule**, sans aide.

| Nom | Rôle | Geste clé réalisé seul(e) | Date | Signature |
|---|---|---|---|---|
| | Commercial | Commande WhatsApp saisie, confirmée, remboursement demandé | | |
| | Préparateur | Commande préparée et marquée prête, problème signalé | | |
| | Resp. livraisons | Affectation, réaffectation, décision après échec, règlement | | |
| | Livreur | Notifications activées, livraison avec code, échec déclaré | | |
| | Administrateur | Compte créé puis désactivé, remboursement validé | | |

La formation est terminée quand chaque rôle a au moins **une personne titulaire et une remplaçante** validées (ligne E1 de `docs/11-DEPLOYMENT/GO-NO-GO-PRODUCTION.md`).

## 5. Après l'ouverture

- Les 2 premières semaines : un point de 15 minutes en fin de journée pour remonter les difficultés.
- Toute nouvelle personne : séance de son rôle + exercice commun réduit, avant d'avoir un compte de production.
