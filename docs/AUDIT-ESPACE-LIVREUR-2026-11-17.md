# Audit de l'espace livreur — 17 novembre 2026

**Pour :** l'équipe projet et le responsable des livraisons de MK Délices.
**Périmètre :** écrans livreur (`frontend/src/pages/courier`, `layouts/CourierLayout.tsx`), API (`Api/V1/Courier/MissionController`, `MissionResource`), règles métier des missions (`Domain/Deliveries/DeliveryService`), affectation par le responsable.
**Méthode :** lecture complète du code et vérification ciblée des constats majeurs ; les parcours principaux sont déjà couverts par les tests automatiques (accepter, enlever, livrer, échouer, confidentialité entre livreurs).

## 1. Synthèse

L'espace livreur est **sûr et fonctionnel** : chaque livreur ne voit que ses missions, l'adresse et le téléphone du client ne sont dévoilés qu'après acceptation puis masqués une fois la mission terminée, chaque action est vérifiée sous verrou côté serveur, et l'interface est pensée pour le téléphone (grands boutons, barre d'action en bas d'écran, contrôle du froid à l'enlèvement).

Les manques sont **opérationnels** : rien ne prévient un livreur qu'une mission l'attend, son statut « Indisponible » n'est pas respecté, et il ne peut pas rendre une mission acceptée. Sur le terrain, cela se traduira par des produits frais qui attendent et des appels téléphoniques pour tout débloquer.

| Gravité | Nombre |
|---|---|
| Élevée | 2 |
| Moyenne | 5 |
| Faible | 5 |

## 2. Constats

### Élevés

| # | Constat | Conséquence | Correction proposée |
|---|---|---|---|
| E1 | **Le livreur n'est pas prévenu d'une nouvelle mission.** Aucune notification à l'affectation ; seule la liste se rafraîchit toutes les 60 s, *si l'application est ouverte*. Aucune alerte non plus si une mission reste sans réponse. | Mission proposée ignorée pendant que des produits frais attendent ; le responsable doit appeler chaque livreur. | Court terme : alerte au responsable si une mission n'est pas acceptée sous X minutes (paramètre), avec bouton de réaffectation. Moyen terme : notification au livreur (WhatsApp/SMS selon la décision D-NOT-01, ou notification du navigateur). |
| E2 | **Le statut « Indisponible » n'est pas respecté.** Le livreur peut se déclarer indisponible, mais l'affectation ne le vérifie pas (`DeliveryService::assign`). | Missions envoyées à un livreur en pause ou qui a fini sa journée. | Refuser l'affectation à un livreur indisponible (ou demander une confirmation explicite au responsable) et le signaler dans la liste de choix. |

### Moyens

| # | Constat | Conséquence | Correction proposée |
|---|---|---|---|
| M1 | **Impossible de rendre une mission acceptée avant l'enlèvement** (panne, accident, urgence) : seule action possible, « Confirmer l'enlèvement ». | Le livreur doit appeler le responsable, qui annule la mission depuis le back-office. | Action « Je ne peux plus assurer cette mission » (motif obligatoire) avant enlèvement : la commande redevient « prête » et le responsable est alerté. |
| M2 | **Les espèces à remettre ne sont pas affichées au livreur** : l'accueil montre seulement sa rémunération (« À percevoir »). | Désaccords au moment du règlement ; le livreur ne sait pas combien il doit rendre. | Carte « Espèces à remettre » (encaissements non réglés) à côté de « À percevoir ». |
| M3 | **La preuve « code » n'est pas vérifiée** : n'importe quel texte est accepté. | La preuve de remise est purement déclarative. | Code de remise à 4 chiffres communiqué au client (suivi de commande) et vérifié par le serveur. *Décision MK Délices.* |
| M4 | **La course est payée à 50 % pour « client absent » même sans tentative de contact** (la case « J'ai tenté de contacter le client » est facultative). | Rémunération d'un échec qui aurait pu être évité. | Rendre la tentative de contact obligatoire pour les motifs imputés au client. *Règle à valider par MK Délices.* |
| M5 | **Pas de bouton WhatsApp vers le client**, seulement « Appeler ». | À Dakar, WhatsApp est souvent le canal le plus utilisé (localisation, message vocal). | Bouton « WhatsApp » (même règle d'affichage que le téléphone : après acceptation uniquement). |

### Faibles

| # | Constat | Correction proposée |
|---|---|---|
| F1 | Aucun signal de retard : un créneau dépassé ne se distingue pas sur la carte de mission. | Badge « En retard » / « Dans 20 min » comme sur l'écran de préparation. |
| F2 | Historique limité aux 50 dernières missions, sans récapitulatif des gains par période. | Filtre par semaine et total gagné / réglé. |
| F3 | La fiche d'une mission ouverte ne se rafraîchit pas : une mission retirée par le responsable reste affichée jusqu'à la prochaine action (qui échoue alors proprement). | Rafraîchissement périodique de la fiche. |
| F4 | Formulaire de remise : si l'écran est ouvert directement (lien), le type de preuve par défaut peut être vide et provoquer une erreur de saisie. | Initialiser la valeur une fois la liste chargée. |
| F5 | Aucune tolérance aux coupures réseau : une action échouée doit être refaite à la main (sans risque de doublon : le serveur refuse les actions incohérentes). | Message explicite « Pas de réseau — réessayez » et nouvelle tentative automatique des actions. |

## 3. Points vérifiés et conformes

- Accès réservé au rôle livreur avec profil actif ; un livreur ne peut ni lire ni agir sur la mission d'un autre (réponse identique, sans divulgation de son existence).
- Adresse complète, repère, téléphone et consignes visibles uniquement pendant la mission (acceptée → enlevée), masqués avant acceptation et après clôture.
- Transitions contrôlées par le serveur sous verrou : impossible de livrer avant l'enlèvement, d'agir deux fois, ou d'agir sur une mission retirée.
- Contrôle du conditionnement (et du froid) obligatoire à l'enlèvement ; preuve de remise obligatoire ; montant encaissé obligatoire pour un paiement à la livraison, écart tracé.
- Échec de livraison : motif obligatoire, état du produit, rémunération selon la règle des 50 % ; le responsable et le commercial sont alertés (y compris lors d'un second échec).
- Interface mobile : boutons ≥ 44 px, barre d'action au-dessus de la barre d'accueil iPhone, lien « Aller au contenu », itinéraire Google Maps.
- Application mobile : mêmes routes accessibles par jeton (`docs/API-MOBILE.md`).

## 4. Plan de correction proposé

1. **Sans décision métier, immédiatement :** E2, M1, M2, M5, F1, F3, F4, F5 et la partie « alerte au responsable » de E1.
2. **Après décision de MK Délices :** M3 (code de remise), M4 (contact obligatoire), notification du livreur (E1, canal à choisir : WhatsApp Business, SMS ou notification du navigateur).

## 5. Suivi des corrections (17/11/2026)

| Constat | État | Vérification |
|---|---|---|
| E2 — statut « Indisponible » | **Corrigé** : affectation refusée par le serveur, livreur grisé dans la liste du responsable | Test automatisé |
| E1 — mission sans réponse | **Corrigé** : alerte au responsable après 10 min (réglable), une seule fois par mission ; **notification du navigateur** au livreur à chaque nouvelle mission (décision du 17/11/2026 : bouton « Activer les notifications », iPhone via l'écran d'accueil, désabonnement à la déconnexion, services de notification limités à Google/Mozilla/Microsoft/Apple) | 6 tests automatisés ; envoi chiffré et signé vérifié contre le service de Google. L'abonnement réel n'est pas testable par navigateur automatisé : **à vérifier sur un vrai téléphone en préproduction** |
| M1 — rendre une mission | **Corrigé** : bouton « Rendre » avant l'enlèvement, motif obligatoire, commande de nouveau « prête », responsable notifié | Tests automatisés + parcours navigateur mobile |
| M2 — espèces à remettre | **Corrigé** : carte « Espèces à remettre » sur l'accueil du livreur | Test automatisé |
| M5 — WhatsApp | **Corrigé** : bouton WhatsApp (après acceptation uniquement) | Parcours navigateur |
| F1 — retard | **Corrigé** : badges « Dans X min », « Créneau en cours », « En retard » | Contrôle visuel |
| F3 — fiche non rafraîchie | **Corrigé** : rafraîchissement toutes les 30 s | — |
| F4 — type de preuve vide | **Corrigé** : formulaire réinitialisé une fois les types chargés | — |
| F5 — coupures réseau | **Corrigé** : 2 nouvelles tentatives automatiques, message « Pas de réseau », état réel rechargé | — |
| M3 — preuve de remise | **Corrigé** (décision du 17/11/2026) : code à 4 chiffres par commande (client et commercial seulement), blocage après 5 codes faux avec alerte au responsable, remise « sans code » possible mais signalée au commercial | Tests automatisés + parcours navigateur |
| M4 — échec « client absent » | **Corrigé** (décision du 17/11/2026) : course rémunérée seulement si le livreur a contacté le client et attendu 10 min (réglable) | Tests automatisés + parcours navigateur |
| F2 — gains par période | **À prioriser** | — |

