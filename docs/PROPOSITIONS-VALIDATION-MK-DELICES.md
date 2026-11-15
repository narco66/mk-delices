# Propositions à valider avant la mise en ligne — MK Délices

**Pour :** la direction de MK Délices · **Préparé le :** 15/11/2026 · **Statut :** propositions, rien n'est décidé.

> **Les contenus détaillés (catalogue complet, grille de prix, zones, dossier de paiement, CGV, politique de confidentialité, mentions légales) sont rédigés dans `docs/propositions/`.** Ce document en est la synthèse.

L'application est prête techniquement. Pour l'ouvrir au public, MK Délices doit trancher quatre sujets : **le catalogue**, **les prix**, **le moyen de paiement**, **les textes juridiques**. Ce document propose, pour chacun, une option recommandée et ce qu'il faut fournir. Chaque décision se saisit ensuite directement dans le back-office (aucun développement supplémentaire, sauf mention contraire).

> Les montants, taux et délais cités pour les prestataires sont des **ordres de grandeur relevés en ligne** (surtout chez des intégrateurs, pas sur des grilles officielles). Ils doivent être **confirmés par écrit** par chaque prestataire avant toute signature.

---

## 1. Le catalogue

### Proposition : une gamme de lancement courte, centrée sur le tiramisu
Commencer avec **6 à 8 produits** maîtrisés plutôt qu'un grand catalogue : moins de pertes (produits frais), préparation plus simple, message clair (« le tiramisu de Dakar »). On élargit ensuite selon les ventes réelles, mesurées par le tableau de bord.

| Catégorie | Contenu proposé | Rôle |
|---|---|---|
| **Tiramisus — les classiques** | Classique café-cacao (produit « signature »), 1 ou 2 parfums gourmands déjà maîtrisés | Cœur de l'offre |
| **Tiramisus — saveurs d'ici** | 1 ou 2 créations locales (idées à tester : bissap, bouye/baobab, mangue) | Différenciation |
| **À partager** | Les mêmes recettes en grand format (4 et 8 parts) | Anniversaires, familles |
| **Douceurs** | 1 ou 2 produits secs qui se conservent mieux (ex. bonbons, truffes) | Panier complémentaire, moins périssable |

**Formats proposés :** coupe individuelle · format 4 parts · format 8 parts. Trois formats suffisent ; chacun a son prix.

### Ce que MK Délices doit fournir, par produit
C'est le minimum exigé par l'application avant de pouvoir **publier** un produit (et une obligation d'information du client) :

| Information | Exemple de ce qu'on attend | Pourquoi |
|---|---|---|
| Nom et courte accroche | « Tiramisu classique — café et cacao » | Vitrine |
| Description | 2–3 phrases | Vitrine, référencement Google |
| **Ingrédients complets** | Mascarpone, œufs, sucre, biscuits (farine de blé), café, cacao | Information du client |
| **Allergènes** | Lait, œufs, gluten (+ fruits à coque, arachide… si présents) | Sécurité alimentaire — bloquant |
| **Conservation** | « Au réfrigérateur entre 0 et 4 °C, à consommer sous 48 h » | Sécurité alimentaire — bloquant |
| Durée de conservation (heures) | 48 | Affichée au client et au livreur |
| Chaîne du froid requise ? | Oui / Non | Contrôles à la préparation et à l'enlèvement |
| Formats et prix | Coupe / 4 parts / 8 parts | Voir §2 |
| Délai de préparation par format | 4 h, 6 h, 24 h… | Calcul automatique des créneaux proposés |
| Quantité maximale par commande (facultatif) | 10 | Capacité de l'atelier |
| Photos | 2–3 photos par produit, prises par MK Délices | Conversion ; aujourd'hui remplacées par des illustrations |

➡ **Modèle à remplir :** `docs/modeles/catalogue-a-remplir.csv` (s'ouvre dans Excel). Une fois rempli, je peux le charger dans l'application.

**Photos :** prévoir une séance photo (lumière naturelle, fond clair, produit seul + produit en situation). Les photos doivent appartenir à MK Délices ou être cédées par écrit.

---

## 2. Les prix

### Proposition : un prix calculé à partir du coût réel, pas « au feeling »
Pour chaque format :

```
Coût de revient = ingrédients + emballage (coupe, couvercle, sac isotherme…) + part de main-d'œuvre et d'énergie
Prix de vente   = coût de revient × coefficient
```

- Le **coefficient** doit couvrir : les invendus/pertes (produits frais), la commission du prestataire de paiement (environ 1 à 3,5 % selon l'option, voir §3), les promotions éventuelles et la marge. En pâtisserie, on travaille souvent avec un coefficient **entre 2,5 et 3,5** ; à ajuster selon vos coûts et les prix pratiqués à Dakar.
- **Vérifier la cohérence** avec 3 ou 4 concurrents (pâtisseries et vendeurs Instagram/WhatsApp) : relever leurs prix au même format.
- **Arrondir** à des montants simples (à la centaine ou au millier de FCFA) : l'application affiche les prix en FCFA sans décimales.

*Exemple de calcul (chiffres fictifs, uniquement pour illustrer la méthode) : coût de revient d'une coupe = 900 FCFA ; coefficient 3 → 2 700 FCFA ; arrondi → 2 500 ou 3 000 FCFA selon le positionnement.*

### Livraison : des zones, un tarif fixe par zone
| Décision | Proposition |
|---|---|
| Zones de départ | Commencer par **3 à 5 zones pilotes** autour de l'atelier, puis étendre (recommandation du plan budgétaire) |
| Frais client | Un **montant fixe par zone**, affiché avant la commande |
| Minimum de commande | Par zone (ex. couvrir au moins le coût de la course) |
| Rémunération du livreur | Montant fixe par course livrée et par zone, à négocier avec 2–3 livreurs partenaires |
| Échec de livraison | À décider : la course échouée est-elle payée au livreur ? (aujourd'hui : non) |

➡ Pour fixer ces montants : demander des **tarifs écrits à 2 ou 3 livreurs / sociétés de livraison** pour chaque zone pilote.

### Promotions
Proposition : un code de lancement (ex. –10 %, plafonné, valable 1 mois) et rien d'autre au démarrage. Un seul code par commande (règle déjà en place).

---

## 3. Le paiement

### Les trois options possibles au Sénégal

| Option | Moyens acceptés | Commission (ordre de grandeur, à confirmer) | Délai d'ouverture (indicatif) | État dans l'application |
|---|---|---|---|---|
| **A. Wave Business en direct** | Wave | ≈ **1 %** par paiement, gratuit pour le client | ≈ 1 à 2 semaines (vérification de l'entreprise) | **Adaptateur déjà développé** : il reste à le tester avec vos accès |
| **B. Orange Money en direct** (Orange / Sonatel) | Orange Money | ≈ **1,5 à 2,5 %** selon volume | ≈ 2 à 4 semaines | Emplacement prévu, **à développer** une fois les spécifications reçues (≈ 3 à 5 jours) |
| **C. Agrégateur** (ex. PayDunya, CinetPay) | Wave, Orange Money, Free Money, **cartes bancaires** | ≈ **2,5 à 3,5 %** (+ parfois un montant fixe) | ≈ 1 à 3 semaines | **À développer** (≈ 3 à 5 jours), un seul contrat pour tous les moyens |

### Recommandation
1. **Au lancement : Wave Business en direct (option A).** C'est le moyen le plus utilisé à Dakar, le moins cher, et le développement est déjà fait. Sur un panier de 10 000 FCFA, la commission serait d'environ 100 FCFA, contre 250 à 300 FCFA via un agrégateur.
2. **Ensuite : ajouter Orange Money**, en direct (B) ou via un agrégateur (C) si vous voulez aussi les cartes bancaires (clientèle expatriée, entreprises).
3. **Paiement à la livraison : désactivé au lancement.** Avec des produits frais préparés sur commande, un client absent ou qui refuse = produit perdu et course payée. À rouvrir éventuellement plus tard pour des clients fidèles.
4. **Paiement avant acceptation : maintenu** (déjà en place) : le commercial n'accepte une commande payée en ligne qu'une fois le paiement confirmé par le prestataire.

### Ce qu'il faut pour ouvrir un compte marchand
Liste usuelle, **à confirmer auprès du prestataire** : NINEA, registre du commerce (RCCM), pièce d'identité du gérant, coordonnées bancaires (RIB) ou compte de réception, adresse et activité de l'entreprise, adresse du site.

### Questions à poser par écrit au prestataire avant de signer
1. Commission exacte par paiement, montant minimum/maximum, frais de retrait vers la banque.
2. Délai de versement des fonds sur votre compte.
3. Remboursements : possibles depuis l'API ? Frais ?
4. Webhooks (notifications de paiement) **signés** et environnement de test (sandbox) disponible ?
5. Durée du contrat, frais d'entrée ou mensuels, conditions de résiliation.

Dès réception des accès de test, je branche et je teste l'intégration en sandbox avant toute activation.

---

## 4. Les textes juridiques

### Les textes de référence identifiés
- **Loi n° 2008-08 du 25 janvier 2008** sur les transactions électroniques et **décret n° 2008-718 du 30 juin 2008** relatif au commerce électronique : identification du vendeur, informations à donner avant la commande, archivage des contrats.
- **Loi n° 2008-12 du 25 janvier 2008** sur la protection des données à caractère personnel, contrôlée par la **CDP** (Commission des Données Personnelles) : déclaration des traitements, information des clients, droits d'accès et de rectification.
- **Loi n° 2021-25 du 12 avril 2021** sur les prix et la protection du consommateur (contrats avec les particuliers).

> Je n'ai pas pu confirmer en ligne les dispositions précises de la loi 2021-25 sur la vente à distance (par exemple un éventuel droit de rétractation et ses exceptions pour les produits périssables). **Ce point doit être vérifié par un juriste.**

### Les trois documents à produire

| Document | Contenu proposé (les trames existent déjà dans l'application) |
|---|---|
| **Mentions légales** | Raison sociale, forme juridique, NINEA/RCCM, adresse, téléphone, email, responsable de publication, **hébergeur** (Hostinger : nom, adresse) |
| **Conditions générales de vente** | Voir propositions de règles ci-dessous |
| **Politique de confidentialité** | Données collectées (prénom, téléphone, adresse de livraison, email facultatif, commandes), finalités, durées de conservation, destinataires (équipe, livreur pendant la livraison, prestataire de paiement), hébergement, droits des clients, contact |

### Règles proposées pour les CGV (à valider par MK Délices puis par un juriste)
| Sujet | Proposition |
|---|---|
| Formation de la commande | La commande n'est ferme qu'après **confirmation par MK Délices** ; un message WhatsApp ne vaut pas commande |
| Prix | En FCFA, frais de livraison affichés avant validation ; prix applicable = prix au moment de la commande |
| Paiement | En ligne avant préparation ; le paiement n'est considéré comme reçu qu'après confirmation du prestataire |
| Livraison | Sur créneau choisi ; le client doit être joignable et présent |
| Absence du client | Une nouvelle livraison peut être proposée avec de nouveaux frais ; au-delà, produit non remboursé (périssable) — **à arbitrer** |
| Annulation par le client | **Gratuite tant que la commande n'est pas confirmée** (déjà possible depuis le lien de suivi) ; après confirmation et avant préparation : remboursement à décider ; après début de préparation : pas de remboursement (produit frais sur commande) |
| Annulation par MK Délices | Remboursement intégral (déjà automatisé, avec double contrôle) |
| Réclamation | Sous **24 h** après livraison, avec photo, via WhatsApp ; remplacement ou remboursement selon le cas |
| Allergènes | Liste affichée sur chaque fiche ; le client est responsable de la vérifier |
| Conservation | Consignes affichées ; MK Délices n'est pas responsable d'une mauvaise conservation après remise |

### Point d'attention : l'hébergement à l'étranger
Le serveur Hostinger est probablement situé **hors du Sénégal** (à vérifier dans hPanel : localisation du VPS). Envoyer des données personnelles de clients sénégalais à l'étranger peut nécessiter une **démarche auprès de la CDP**. À faire vérifier par le juriste au moment de la déclaration du traitement.

### Démarches
1. Faire relire/compléter les trois trames par un **juriste ou avocat sénégalais**.
2. **Déclarer le traitement** « gestion des clients et des commandes en ligne » à la CDP (et, si nécessaire, la question du transfert hors Sénégal).
3. Saisir les textes validés dans **Back-office › Contenus du site** (la mention « en attente de validation » disparaît alors).

---

## 5. Ordre proposé et qui fait quoi

| Étape | Qui | Durée indicative |
|---|---|---|
| 1. Lancer la demande de compte **Wave Business** (le délai le plus long) | Direction MK Délices | Cette semaine |
| 2. Remplir le **modèle de catalogue** et calculer les prix | Direction + atelier | 1 à 2 semaines |
| 3. Demander les **tarifs des livreurs** pour 3–5 zones pilotes | Responsable livraisons | 1 semaine |
| 4. Faire valider **CGV, confidentialité, mentions légales** ; déclaration CDP | Direction + juriste | 2 à 4 semaines |
| 5. **Séance photo** | MK Délices | 1 journée |
| 6. Charger catalogue, zones, textes ; tester Wave en sandbox ; déployer la **préproduction** sur le VPS | Équipe technique | 2 à 3 jours dès réception |
| 7. Recette interne (commandes de test), formation de l'équipe avec les guides | Toute l'équipe | 1 semaine |
| 8. Ouverture au public | Décision de la direction | — |

---

## Sources consultées (15/11/2026)
- Commissions et intégration Wave : [kolonell.com — Wave Côte d'Ivoire vs Sénégal 2026](https://kolonell.com/fr/blog/wave-cote-ivoire-vs-senegal-frais-marchand-comparatif-2026), [kolonell.com — Frais Wave / Orange Money / Stripe 2026](https://kolonell.com/fr/blog/frais-transaction-wave-orange-money-stripe-compares-2026), [absitech.dev — Intégrer Wave, Orange Money, Free Money 2026](https://absitech.dev/blog/integrer-wave-orange-money-free-money-senegal), [nowpayments.io — Payment gateways Senegal 2026](https://nowpayments.io/blog/payment-gateways-senegal)
- Orange Money : [kolonell.com — Orange Money API Sénégal 2026](https://kolonell.com/fr/blog/orange-money-api-collecte-paiement-senegal-2026), [espace Orange Money API Internet](https://espaceclientv2.orange.sn/orange-money/api-internet)
- Agrégateurs : [terangaweb.sn — Recevoir des paiements au Sénégal 2026](https://terangaweb.sn/recevoir-des-paiements-sur-votre-site-web-au-senegal/), [absitech.dev — Wave, Orange Money, Stripe 2026](https://absitech.dev/blog/integrer-wave-orange-money-stripe-e-commerce-senegalais-2026)
- Juridique : [Loi 2008-08 (Africa laws)](https://www.africa-laws.org/Senegal/Comercial%20law/Loi%20n%C2%B0%202008-08%20sur%20les%20transactions%20%C3%A9lectroniques.pdf), [Décret commerce électronique (PressAfrik)](https://www.pressafrik.com/file/48132/), [Loi 2008-12 (AFAPDP)](https://www.afapdp.org/wp-content/uploads/2018/05/Senegal-texte-de-loi-2008.pdf), [DLA Piper — Data protection Senegal](https://www.dlapiperdataprotection.com/index.html?t=law&c=SN), [Loi 2021-25 (UNEP LEAP)](https://leap.unep.org/en/countries/sn/national-legislation/loi-ndeg-2021-25-du-12-avril-2021-sur-les-prix-et-la-protection), [CDP — textes législatifs](https://www.cdp.sn/legislation/textes-legislatifs)
