# Feuille de route et budget prévisionnel

**MK DÉLICES** — Dossier de cadrage fonctionnel et technique

**Document 12/12** · Version 1.0 · 14 novembre 2026

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


## 1. Hypothèses de planification

Budget de **cadrage indicatif**, et non devis commercial : prix, charge et disponibilité des développeurs inconnus. Estimation **56–89 jours-homme** (somme des phases de l implémentation) ; intégrer marge de risque 15–25 % sur intégrations et recette. Hypothèse : une équipe restreinte, architecture monolithe modulaire, une seule marque et une région de livraison initiale.

## 2. Roadmap / jalons

| Jalon | Fenêtre indicative | Livrables / dépendances |
| --- | --- | --- |
| J0 | Semaine 1 | Décisions métiers/PSP/CGV |
| J1 | Semaines 2–3 | Socle backend/frontend + migrations |
| J2 | Semaines 3–5 | Vitrine/catalogue/checkout |
| J3 | Semaines 5–7 | Paiements et WhatsApp sandbox |
| J4 | Semaines 7–9 | Préparation et livraison |
| J5 | Semaines 9–11 | Recette, corrections, sécurité |
| J6 | Semaine 12 | Production/formation sous réserve go/no-go |

Les semaines sont des hypothèses de parallélisme : à recalculer après affectation des personnes et accès prestataires.

## 3. Estimation de charge

| Phase | Min jh | Max jh |
| --- | --- | --- |
| P0 | Validation métier | 3 | 5 |
| P1 | Socle technique | 5 | 8 |
| P2 | Catalogue/vitrine | 8 | 12 |
| P3 | Checkout/commande | 10 | 15 |
| P4 | Paiement/WhatsApp | 8 | 14 |
| P5 | Préparation/livraison | 10 | 16 |
| P6 | Qualité/recette | 8 | 12 |
| P7 | Déploiement/formation | 4 | 7 |

## 4. Scénarios de coût de réalisation

En appliquant **un taux fictif de calcul** de 75 000 à 150 000 XOF/jh (non vérifié sur le marché) :

- Bas : 56 jh × 75 000 = **4 200 000 XOF** ;
- Haut : 89 jh × 150 000 = **13 350 000 XOF** ;
- Fourchette indicative avec marge 20 % : **5 040 000 à 16 020 000 XOF**.

**Attention :** estimation illustrative à demander en devis, ni tarif prestataire ni engagement. Elle n inclut pas coûts du matériel de livraison, photographie commerciale, frais juridiques ni commissions PSP.

## 5. Charges récurrentes — catégories à chiffrer

| Poste | Périodicité | Méthode de chiffrage |
| --- | --- | --- |
| Domaine + DNS | Annuel | Devis registrar .sn ou autre |
| Hébergement et base | Mensuel | Devis fournisseur selon trafic/capacité |
| Stockage médias + backup | Mensuel | Go stockés + rétention |
| Emails / SMS transactionnels | À l usage | Prix unitaire et volume réel |
| WhatsApp API automatisée | À l usage / mensuel | Tarifs Meta/partenaire et templates approuvés |
| PSP Wave/Orange | Par transaction | Grille contractuelle + frais fixes éventuels |
| Maintenance applicative | Mensuel | Contrat SLA et sécurité |
| Assistance/formation | Ponctuel | Devis au périmètre |

## 6. Prévision à partir des volumes

Équations : `CA mensuel = commandes payées × panier moyen net` ; `marge contribution = CA - coût matière - emballage - subvention livraison - frais PSP - charges variables` ; `seuil rentabilité numérique = coûts fixes digitaux / marge contribution par commande`. Ces valeurs ne doivent pas être renseignées comme factuelles tant que les prix de vente, achats et commissions ne sont pas connus.

## 7. Risques budgétaires et arbitrages

| Risque | Impact | Arbitrage |
| --- | --- | --- |
| Accès PSP retardé | Surcoût intégration | Sandbox et adaptateur générique |
| Shooting produits non disponible | Conversion plus faible | Prévoir séance photo avant lancement |
| Zones de livraison trop larges | Coût logistique élevé | Ouvrir quartiers pilotes puis étendre |
| Scope creep | Retard et dépassement | MVP verrouillé, changements chiffrés |
| Support après lancement | Incidents non traités | Provision maintenance et SLA |

## 8. Conditions de validation et recommandations

Valider budget et devis fournisseur par fournisseur, ne pas choisir un PSP sans conditions marchand écrites, mesurer les coûts après 30 jours de ventes, ouvrir d abord un nombre réduit de zones et de produits vedettes, puis étendre selon métriques réelles. Désigner responsable marketing produits, responsable commandes et responsable livraison.

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
