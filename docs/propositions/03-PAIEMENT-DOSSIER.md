# Dossier « moyen de paiement » — MK Délices

**Statut : PROPOSITION.** Recommandation : **Wave Business en direct** au lancement, puis Orange Money (direct ou via un agrégateur). Conditions, taux et pièces exigées à **confirmer par écrit** auprès de chaque prestataire.

## 1. Pièces à préparer (liste usuelle, à confirmer)
- [ ] NINEA
- [ ] Registre du commerce (RCCM) ou statut équivalent
- [ ] Pièce d'identité du gérant / représentant légal
- [ ] Justificatif d'adresse de l'entreprise
- [ ] Coordonnées bancaires (RIB) ou compte de réception des fonds
- [ ] Description de l'activité : vente en ligne de pâtisseries fraîches, livrées à Dakar
- [ ] Adresse du site : `https://preprod.mk-delices.tech` (préproduction, tests Wave) puis `https://mk-delices.com` (production, adresse principale ; `mk-delices.tech` y redirigera)
- [ ] Panier moyen et volume mensuel estimés
- [ ] Email et téléphone du contact technique

## 2. Message type à envoyer à Wave Business

> **Objet : Ouverture d'un compte marchand Wave Business avec paiement en ligne (API Checkout) — MK Délices**
>
> Bonjour,
>
> MK Délices est une pâtisserie artisanale basée à Dakar (NINEA : [À COMPLÉTER]). Nous lançons une boutique en ligne et souhaitons proposer le paiement Wave à nos clients via votre **API Checkout**, avec confirmation des paiements par **webhook**.
>
> Pourriez-vous nous indiquer :
> 1. la procédure et les pièces nécessaires à l'ouverture du compte marchand ;
> 2. la commission appliquée par paiement, les éventuels montants minimum ou maximum et les frais de retrait vers notre banque ;
> 3. le délai de versement des fonds ;
> 4. la possibilité d'effectuer des **remboursements** via l'API ;
> 5. l'accès à un **environnement de test** et à la documentation des webhooks (signature) ;
> 6. la durée du contrat et les conditions de résiliation.
>
> Volume estimé : [À COMPLÉTER] commandes par mois, panier moyen d'environ [À COMPLÉTER] FCFA.
>
> Contact : [NOM], [FONCTION], [TÉLÉPHONE], [EMAIL].
>
> Cordialement,
> [NOM] — MK Délices

*Le même message, en remplaçant « API Checkout » par « API de paiement web / Orange Money Web Payment », peut être adressé à Orange Business Sénégal, ou à un agrégateur (PayDunya, CinetPay…) en ajoutant la question : « quels moyens de paiement sont inclus (Wave, Orange Money, Free Money, cartes) et à quel taux chacun ? ».*

## 3. Grille de comparaison à remplir avec les réponses reçues

| Critère | Wave Business | Orange Money | Agrégateur : ________ |
|---|---|---|---|
| Commission par paiement | | | |
| Montant fixe par paiement | | | |
| Frais de retrait / versement | | | |
| Délai de versement | | | |
| Remboursement par API | | | |
| Webhooks signés | | | |
| Sandbox disponible | | | |
| Frais d'ouverture / mensuels | | | |
| Durée et résiliation | | | |
| Délai d'ouverture du compte | | | |

## 4. Ce qui se passe une fois le contrat signé
1. Le prestataire fournit une **clé API** et un **secret de webhook** : ils sont transmis **uniquement à l'équipe technique**, par un canal sûr (jamais par email non chiffré, jamais dans un document partagé).
2. L'équipe technique les installe **sur le serveur** (fichier de configuration non versionné) et teste en environnement de test : paiement réussi, paiement refusé, notification en double, remboursement.
3. MK Délices active le moyen de paiement dans **Back-office › Paramètres › Moyens de paiement**.
4. Premier paiement réel de faible montant par l'équipe, puis remboursement, pour valider la chaîne complète.

## 5. Règles internes proposées
- Une commande payée en ligne n'est acceptée qu'**après confirmation du prestataire** (jamais sur capture d'écran).
- Paiement à la livraison : **désactivé** au lancement.
- Tout remboursement est **demandé par une personne et approuvé par une autre** (déjà en place dans l'application).
- Rapprochement hebdomadaire : comparer les paiements de l'application avec le relevé du prestataire.
