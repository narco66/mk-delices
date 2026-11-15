# Product Requirements Document — Expression des besoins

**MK DÉLICES** — Dossier de cadrage fonctionnel et technique

**Document 01/12** · Version 1.0 · 14 novembre 2026

---

## Gouvernance du document

| Champ | Valeur |
|---|---|
| Projet | MK Délices — Plateforme e-commerce pâtisserie |
| Version | 1.0 — cadrage initial du 14 novembre 2026 |
| Statut | Proposition de référence ; à valider par MK Délices |
| Localisation | Dakar, Sénégal |
| Monnaie / fuseau | XOF / Africa/Dakar (UTC+0) |
| Socle préconisé | React + TypeScript + Vite ; Laravel 13 / PHP ≥ 8.3 ; PostgreSQL |
| Hypothèses | Catalogue, prix, zones, frais, prestataires et identité visuelle à confirmer |

**Convention :** « DOIT » = exigence impérative ; « DEVRAIT » = souhaitable ; « option » = hors MVP ou à arbitrer. Les exemples de tarifs, d'adresses, de parfums et de durées ne constituent jamais des données commerciales officielles. Toutes les valeurs monétaires sont enregistrées en unités entières de XOF.


## 1. Synthèse et justification

MK Délices fabrique des tiramisus, gâteaux et confiseries à Dakar. Le commerce est aujourd’hui réalisé notamment par téléphone, réseaux sociaux et WhatsApp ; le service de livraison est assuré par des partenaires. L’objectif est de transformer un parcours de vente dispersé en une expérience mobile-first où catalogue, panier, paiement, préparation et livraison convergent vers une même référence de commande. Le site vitrine doit mettre les tiramisus au premier plan.

**Problèmes à résoudre :** informations produits dispersées ; saisie répétée des commandes ; absence de traçabilité de bout en bout ; visibilité limitée sur le statut d’encaissement et de livraison ; reporting manuel.

**Non-objectifs MVP :** marketplace multi-vendeurs, GPS continu, production industrielle, comptabilité générale, application mobile native, recommandations IA.

## 2. Vision, résultats et métriques

**Vision :** une boutique numérique de desserts frais qui inspire confiance, transforme les visites en commandes et coordonne des livraisons fiables à Dakar.

**Objectifs :** réduire les frictions d’achat, accroître la part des commandes enregistrées dans un système unique, améliorer les délais de confirmation et rendre traçables les transferts aux livreurs.

**Cibles proposées (à approuver, après mesure initiale) :** disponibilité 99,5 % mensuelle ; délai p95 d’affichage des pages catalogue < 3 s sur réseau mobile représentatif ; 100 % des commandes livrées avec état final enregistré ; 0 commande payée sur seul retour navigateur.

**KPIs :** taux de conversion = commandes confirmées / sessions qualifiées ; panier moyen = chiffre d’affaires net des commandes payées / commandes payées ; taux de livraison réussie = missions livrées / missions clôturées ; temps moyen de préparation = prête_at – acceptée_at ; taux de remboursement et abandon panier. Les calculs excluent les commandes de test.

## 3. Parties prenantes et personas

| Partie prenante | Besoin prioritaire | Décision / accès |
| --- | --- | --- |
| Direction MK Délices | Marge, image de marque, performance | Arbitrage tarifs, modes de vente, limites de service |
| Acheteur mobile | Trouver tiramisu, commander vite | Naviguer et payer sans compte obligatoire |
| Responsable commandes | Valider capacités, gérer litiges | Accepter/refuser et suivre commandes |
| Préparateur | Liste ordonnée des préparations | Marquer préparation commencée et prête |
| Responsable livraison | Affecter et arbitrer incidents | Attribuer et réaffecter les missions |
| Livreur partenaire | Missions et adresse protégées | Accepter, enlever, confirmer livraison |
| Administrateur | Paramétrage et sécurité | Gérer accès, zones et contenus |

## 4. Périmètre et matrice de fonctionnalités

| ID | Fonctionnalité | MoSCoW | Acteurs | Comportement | Acceptation |
| --- | --- | --- | --- | --- | --- |
| CAT-001 | Catalogue et vitrines | M | Visiteur, client | Lister catégories/produits actifs, variantes, photos, ingrédients, allergènes, disponibilité et prix XOF | La fiche affiche un prix et les allergènes avant ajout ; un produit désactivé ne peut pas être commandé |
| CART-002 | Panier et devis | M | Visiteur, client | Ajouter, modifier et retirer les lignes ; recalculer sous-total, remise, transport et total côté serveur | Une manipulation du prix client ne change jamais le montant final ; les quantités sont validées |
| ORDER-003 | Commande invitée | M | Visiteur, client | Saisir téléphone, destinataire, livraison, créneau et consignes ; générer référence non prédictible | Commande créée une seule fois avec idempotence ; récapitulatif consultable par lien signé |
| WA-004 | Passerelle WhatsApp | M | Visiteur, commercial | Créer un brouillon de commande avec référence puis ouvrir un message prérempli | Retour de WhatsApp ne valide ni paiement ni commande sans confirmation côté serveur |
| PAY-005 | Encaissement | M | Client, commercial | Initier Wave/Orange Money via adaptateur ; recevoir événements ; rapprochement | Webhook authentifié et idempotent ; commande payée uniquement après confirmation serveur du prestataire |
| PREP-006 | Préparation | M | Commercial, préparateur | Accepter, préparer, marquer prête, journaliser acteur et horodatage | Transition invalide refusée ; commande non autorisée non préparée sans exception explicite |
| DEL-007 | Livraisons partenaires | M | Responsable, livreur | Tarifer zone, affecter mission, accepter, enlever, livrer ou signaler un échec | Livreur ne voit que ses missions ; preuve horodatée ; montant dû calculé séparément |
| CMS-008 | Site et contenus | M | Commercial, admin | Gérer pages vitrine, bannière, horaires, FAQ, galerie, textes et liens | Contenus modifiables sans déploiement ; brouillon / publication |
| SEC-009 | Accès et audit | M | Tous | RBAC, journal immuable des événements clés, protection informations privées | Rôle livreur interdit d accéder aux commandes non affectées et au tableau financier |
| STATS-010 | Pilotage commercial | S | Commercial, admin | Ventes, commandes, panier moyen, conversion et délais | Indicateurs calculés sur ventes payées non remboursées selon période choisie |
| LOY-011 | Fidélisation | S | Client, commercial | Historique, codes promotionnels, avis modérés, points de fidélité | Une remise n est pas cumulée sans règle ; consentement marketing distinct |
| GPS-012 | Traçage GPS continu | C | Client, livreur | Position en temps réel du livreur avec accord explicite | Inactif par défaut ; consentement et politique de rétention validés |
| EVT-013 | Commandes événementielles | C | Client, commercial | Devis sur mesure et paiement d acompte éventuel | Devis et échéance validés manuellement |

## 5. User stories et critères Given/When/Then

### US-001 — Catalogue et vitrines

**En tant que** visiteur, **je veux** lister catégories/produits actifs, variantes, photos, ingrédients, allergènes, disponibilité et prix xof **afin de** disposer d’un service vérifiable.

**Étant donné** un contexte autorisé et des données valides, **quand** je déclenche cette action, **alors** la fiche affiche un prix et les allergènes avant ajout ; un produit désactivé ne peut pas être commandé.

**Cas limites à tester :** données manquantes, doublons de requête, changement concurrent de disponibilité et tentative d’accès non autorisée.

### US-002 — Panier et devis

**En tant que** visiteur, **je veux** ajouter, modifier et retirer les lignes ; recalculer sous-total, remise, transport et total côté serveur **afin de** disposer d’un service vérifiable.

**Étant donné** un contexte autorisé et des données valides, **quand** je déclenche cette action, **alors** une manipulation du prix client ne change jamais le montant final ; les quantités sont validées.

**Cas limites à tester :** données manquantes, doublons de requête, changement concurrent de disponibilité et tentative d’accès non autorisée.

### US-003 — Commande invitée

**En tant que** visiteur, **je veux** saisir téléphone, destinataire, livraison, créneau et consignes ; générer référence non prédictible **afin de** disposer d’un service vérifiable.

**Étant donné** un contexte autorisé et des données valides, **quand** je déclenche cette action, **alors** commande créée une seule fois avec idempotence ; récapitulatif consultable par lien signé.

**Cas limites à tester :** données manquantes, doublons de requête, changement concurrent de disponibilité et tentative d’accès non autorisée.

### US-004 — Passerelle WhatsApp

**En tant que** visiteur, **je veux** créer un brouillon de commande avec référence puis ouvrir un message prérempli **afin de** disposer d’un service vérifiable.

**Étant donné** un contexte autorisé et des données valides, **quand** je déclenche cette action, **alors** retour de WhatsApp ne valide ni paiement ni commande sans confirmation côté serveur.

**Cas limites à tester :** données manquantes, doublons de requête, changement concurrent de disponibilité et tentative d’accès non autorisée.

### US-005 — Encaissement

**En tant que** client, **je veux** initier wave/orange money via adaptateur ; recevoir événements ; rapprochement **afin de** disposer d’un service vérifiable.

**Étant donné** un contexte autorisé et des données valides, **quand** je déclenche cette action, **alors** webhook authentifié et idempotent ; commande payée uniquement après confirmation serveur du prestataire.

**Cas limites à tester :** données manquantes, doublons de requête, changement concurrent de disponibilité et tentative d’accès non autorisée.

### US-006 — Préparation

**En tant que** commercial, **je veux** accepter, préparer, marquer prête, journaliser acteur et horodatage **afin de** disposer d’un service vérifiable.

**Étant donné** un contexte autorisé et des données valides, **quand** je déclenche cette action, **alors** transition invalide refusée ; commande non autorisée non préparée sans exception explicite.

**Cas limites à tester :** données manquantes, doublons de requête, changement concurrent de disponibilité et tentative d’accès non autorisée.

### US-007 — Livraisons partenaires

**En tant que** responsable, **je veux** tarifer zone, affecter mission, accepter, enlever, livrer ou signaler un échec **afin de** disposer d’un service vérifiable.

**Étant donné** un contexte autorisé et des données valides, **quand** je déclenche cette action, **alors** livreur ne voit que ses missions ; preuve horodatée ; montant dû calculé séparément.

**Cas limites à tester :** données manquantes, doublons de requête, changement concurrent de disponibilité et tentative d’accès non autorisée.

### US-008 — Site et contenus

**En tant que** commercial, **je veux** gérer pages vitrine, bannière, horaires, faq, galerie, textes et liens **afin de** disposer d’un service vérifiable.

**Étant donné** un contexte autorisé et des données valides, **quand** je déclenche cette action, **alors** contenus modifiables sans déploiement ; brouillon / publication.

**Cas limites à tester :** données manquantes, doublons de requête, changement concurrent de disponibilité et tentative d’accès non autorisée.

### US-009 — Accès et audit

**En tant que** tous, **je veux** rbac, journal immuable des événements clés, protection informations privées **afin de** disposer d’un service vérifiable.

**Étant donné** un contexte autorisé et des données valides, **quand** je déclenche cette action, **alors** rôle livreur interdit d accéder aux commandes non affectées et au tableau financier.

**Cas limites à tester :** données manquantes, doublons de requête, changement concurrent de disponibilité et tentative d’accès non autorisée.

### US-010 — Pilotage commercial

**En tant que** commercial, **je veux** ventes, commandes, panier moyen, conversion et délais **afin de** disposer d’un service vérifiable.

**Étant donné** un contexte autorisé et des données valides, **quand** je déclenche cette action, **alors** indicateurs calculés sur ventes payées non remboursées selon période choisie.

**Cas limites à tester :** données manquantes, doublons de requête, changement concurrent de disponibilité et tentative d’accès non autorisée.

### US-011 — Fidélisation

**En tant que** client, **je veux** historique, codes promotionnels, avis modérés, points de fidélité **afin de** disposer d’un service vérifiable.

**Étant donné** un contexte autorisé et des données valides, **quand** je déclenche cette action, **alors** une remise n est pas cumulée sans règle ; consentement marketing distinct.

**Cas limites à tester :** données manquantes, doublons de requête, changement concurrent de disponibilité et tentative d’accès non autorisée.

### US-012 — Traçage GPS continu

**En tant que** client, **je veux** position en temps réel du livreur avec accord explicite **afin de** disposer d’un service vérifiable.

**Étant donné** un contexte autorisé et des données valides, **quand** je déclenche cette action, **alors** inactif par défaut ; consentement et politique de rétention validés.

**Cas limites à tester :** données manquantes, doublons de requête, changement concurrent de disponibilité et tentative d’accès non autorisée.

### US-013 — Commandes événementielles

**En tant que** client, **je veux** devis sur mesure et paiement d acompte éventuel **afin de** disposer d’un service vérifiable.

**Étant donné** un contexte autorisé et des données valides, **quand** je déclenche cette action, **alors** devis et échéance validés manuellement.

**Cas limites à tester :** données manquantes, doublons de requête, changement concurrent de disponibilité et tentative d’accès non autorisée.

## 6. Exigences non fonctionnelles

| ID | Exigence | Mesure / contrôle |
| --- | --- | --- |
| NFR-001 | Mobile-first | Aucun débordement horizontal à 360 px ; navigation clavier |
| NFR-002 | Performance | P95 réponse API lecture < 800 ms hors réseau tiers, seuil à valider par charge |
| NFR-003 | Disponibilité | Objectif initial 99,5 % mensuel, hors maintenance notifiée |
| NFR-004 | Sécurité | TLS, RBAC deny-by-default, protections OWASP et secrets serveur |
| NFR-005 | Traçabilité | Toute mutation de paiement, livraison, commande horodatée avec acteur |
| NFR-006 | Accessibilité | WCAG 2.2 AA comme objectif de conception |
| NFR-007 | Localisation | Français, XOF, Africa/Dakar, format téléphone sénégalais |
| NFR-008 | Reprise | Objectifs proposés RPO 24h / RTO 8h, à valider |
| NFR-009 | Référencement | URL canoniques, SEO métadonnées, structured data produits |

## 7. Règles métier structurantes

- Prix, remises, zone et frais recalculés côté serveur à la confirmation du panier.
- Une commande invitée possède un jeton de suivi à durée limitée ; jamais de recherche publique par numéro seul.
- La réception du webhook n est acceptée qu après validation de sa signature et vérification de l état externe.
- Un paiement confirmé ne signifie ni que le produit est prêt ni qu il est livré.
- Le destinataire, le téléphone, le créneau et les consignes sont gelés au passage en préparation sauf modification autorisée.
- Les stocks et quotas de créneaux doivent être réservés atomiquement, avec expiration éventuelle.
- Les informations d allergènes et de conservation sont visibles avant validation du panier.
- Les missions ne sont visibles que par le livreur affecté ; réaffectation journalisée.
- Une commande envoyée via WhatsApp reste brouillon/à confirmer ; pas de paiement implicite.
- Une commande refusée après encaissement ouvre un traitement de remboursement explicite.

## 8. MVP, versions et dépendances

| Version | Inclus | Conditions de sortie |
| --- | --- | --- |
| MVP | Vitrine, catalogue, panier invité, WhatsApp prérempli, paiement contracté, gestion préparation, livraison zones, administration | Parcours bout en bout et tests paiement réussis |
| V1.1 | Espace livreur amélioré, reporting, réclamations, avis | Métriques d usage disponibles |
| V2 | Fidélité, campagnes, événements, analyse avancée | ROI et opérations stabilisés |
| Option | GPS, application native, connecteurs CRM | Étude sécurité, coût et besoin |

## 9. Risques et hypothèses

| ID | Risque | Criticité | Traitement |
| --- | --- | --- | --- |
| R-01 | Contrat PSP indisponible | Élevé | Prévoir adaptateur, sandbox, paiement manuel contrôlé si approuvé |
| R-02 | Chaîne du froid non maîtrisée | Élevé | Qualifier produits, emballages, temps maximum, contrôle à l enlèvement |
| R-03 | Adresses de Dakar imprécises | Élevé | Zone + quartier + repère + géopoint facultatif et confirmation téléphonique |
| R-04 | Double commande WhatsApp/site | Moyen | Référence stable et conversion atomique du brouillon |
| R-05 | Coûts et conversion inconnus | Moyen | Lancer MVP, mesurer panier moyen et coût d acquisition |
| R-06 | Fuite de données clients | Élevé | RBAC, chiffrement en transit, minimisation, audit et sauvegardes |

## 10. Décisions en attente

- Raison sociale exacte, NINEA/RCCM et coordonnées publiques
- Logo et charte existants ; photographies et droits d utilisation
- Liste officielle des tiramisus, formats, ingrédients, allergènes et tarifs
- Zones réellement desservies, coût et conditions de livraison
- Horaires, jours fermés, délais minimum de préparation et quantité maximale par créneau
- Retrait sur place et paiement à la livraison : oui/non
- Compte marchand Wave/Orange Money ou agrégateur effectivement contracté
- Numéro WhatsApp Business, politique de confirmation des commandes et service client
- Capacité et tarifs des livreurs partenaires, politique de retours/échecs
- Politique de conservation, informations légales et traitement des données à valider

## 11. Critères de validation du PRD

Le propriétaire métier valide le catalogue et les modes de paiement envisagés ; le responsable opérationnel valide les états de commande et les contraintes de livraison ; le développement confirme la faisabilité ; chaque exigence MVP dispose d un test de recette associé. Aucune donnée tarifaire commerciale ne doit être figée avant décision.

## Références et réserves

**Sources externes de vérification (consultées le 14/11/2026) :**
- https://laravel.com/framework/docs/13.x/releases
- https://docs.wave.com/business
- https://docs.wave.com/checkout
- https://espaceclientv2.orange.sn/orange-money/api-internet
- https://www.cdp.sn/legislation/textes-legislatifs
- https://www.cdp.sn/legislation/textes-reglementaires

Le présent document distingue faits du projet communiqués, recommandations de conception et décisions commerciales à confirmer. Les paramètres externes (PSP, tarification, obligations légales, contrats) doivent faire l objet de vérifications au moment de l implémentation.

## Approbation et contrôle de changement

| Rôle | Visa / date |
|---|---|
| Direction MK Délices | À compléter |
| Responsable produit / opérations | À compléter |
| Responsable technique | À compléter |

Toute modification après validation doit créer un historique de version, une justification et une analyse d impact sur les autres documents.
