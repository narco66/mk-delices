# Registre des décisions à valider — MK Délices

**Mis à jour :** 15/11/2026 · **Statut :** ouvert · **Destinataires :** direction MK Délices, responsable opérations, responsable technique.

Ce registre liste (1) les **informations commerciales, juridiques et financières** que seule MK Délices peut fournir ou valider, et (2) les **arbitrages techniques** pris pendant le développement, à confirmer.

Tant qu'une décision n'est pas validée, l'application fonctionne avec un **paramètre configurable** (back-office › Paramètres, marqué « À valider par MK Délices ») ou une **donnée de démonstration explicitement signalée** (`is_demo`, bandeau « Catalogue de démonstration »). Aucune donnée de démonstration n'est chargée hors environnements `local`/`testing`.

Légende statut : 🔴 bloquant pour la mise en production · 🟠 nécessaire avant ouverture au public · 🟢 amélioration.

## 0. Décisions validées par la direction le 15/11/2026

La direction de MK Délices a validé les propositions de `docs/propositions/`. Elles sont chargées dans l'application (catalogue publié, zones, horaires, règles) et rejouables sur tout environnement :
`php artisan mk:import-catalogue docs/propositions/catalogue-propose.csv --publish` puis `php artisan db:seed --class=LaunchConfigurationSeeder`.

| ID | Décision validée | Reste à faire |
|---|---|---|
| D-CAT-01 | Gamme de lancement de 8 produits (01-CATALOGUE-ET-PRIX) | Photos (D-BRAND-02) |
| D-CAT-02 / D-CAT-03 | Ingrédients, allergènes, conservation 48 h, œufs pasteurisés, sans alcool | **Revérifier les allergènes sur les étiquettes des ingrédients achetés** |
| Prix | Grille validée (coupe Classique 2 500 FCFA…) | Contrôler le coefficient sur le coût de revient réel |
| D-ZONE-01 / D-ZONE-02 | 4 zones pilotes, frais 1 500 → 3 000 FCFA, minimums 5 000 → 10 000 FCFA | Confirmer la rémunération par devis livreurs |
| D-HOR-01 / D-HOR-02 | Mardi → dimanche 10 h–20 h, créneaux de 2 h, 5 commandes/créneau, délai 4 h (coupes) / 24 h (sur commande) | Saisir les jours fériés (Tabaski, Korité…) |
| D-QUA-01 | Durée maximale hors froid : 30 min | — |
| D-RET-01 | Pas de retrait sur place | — |
| D-PAY-01 | Wave Business en direct au lancement | **Contrat, accès sandbox puis tests** |
| D-PAY-04 / D-PAY-05 | Paiement à la livraison désactivé ; paiement exigé avant acceptation | — |
| D-LIV-02 | Preuve : confirmation du destinataire ou code | — |
| D-LIV-01 | Courses livrées rémunérées ; **50 % pour un échec dû au client** (client absent, injoignable, refus) — validé et **implémenté** | Pourcentage et motifs modifiables dans Paramètres ; « adresse introuvable » exclue par défaut (faute non attribuable) |
| Promotion | Code BIENVENUE10 (10 %, plafond 2 000 FCFA) | Créé **inactif** : à activer, avec dates, le jour de l'ouverture |
| D-JUR-04 | **Factures obligatoires, avec ou sans TVA** — décidé et **implémenté** : facture émise automatiquement à la livraison (ou au paiement, au choix), avoir à chaque remboursement, numérotation continue FA-AAAA-NNNNN / AV-AAAA-NNNNN, documents immuables, PDF envoyé au client et téléchargeable, export CSV comptable | **Faire confirmer par l'expert-comptable** l'assujettissement à la TVA (Paramètres › Facturation et TVA, désactivée par défaut : mention « TVA non applicable ») et le libellé de la mention ; renseigner **raison sociale, NINEA, RCCM, adresse** (obligatoires : sans elles, aucune facture n'est émise en production) |
| D-JUR-01 / 02 / 03 | Projets de mentions légales, CGV et politique de confidentialité approuvés sur le fond | **Compléter les `[À COMPLÉTER]`, relecture par un juriste, déclaration CDP** ; les pages restent affichées « en attente de validation » |

Les autres lignes du tableau ci-dessous restent ouvertes.

---

## 1. Décisions métier, juridiques et financières

| ID | Sujet | Ce qui est attendu de MK Délices | Où le saisir / impact technique | Valeur actuelle (provisoire) | Statut |
|---|---|---|---|---|---|
| D-BRAND-01 | Logo et identité visuelle | Logo vectoriel, couleurs, polices, droits d'usage | Remplacer `Logo` (`frontend/src/components/brand/Brand.tsx`), favicon, tokens `frontend/src/index.css` | Monogramme « MK » provisoire, palette cacao/caramel/framboise du document UI-UX | 🟠 |
| D-BRAND-02 | Photographies produits | Photos dont MK Délices détient les droits (formats 1:1, 4:5, 16:9) | Back-office › Catalogue › produit › Images (ré-encodage WebP automatique) | Illustrations vectorielles marquées « Illustration » | 🟠 |
| D-CAT-01 | Catalogue officiel | Liste des produits, formats, saveurs, prix XOF | Back-office › Catalogue | 10 produits **fictifs** (`is_demo`), prix inventés | 🔴 |
| D-CAT-02 | Ingrédients et allergènes | Liste exacte par produit (obligatoire avant publication) | Fiche produit ; la publication est refusée sans ingrédients/allergènes | Valeurs de démonstration | 🔴 |
| D-CAT-03 | Conservation / DLC | Consignes et durée de conservation par recette | Champs « Conservation », « Durée de conservation (h) » | Texte de démonstration « 0–4 °C, 48 h » | 🔴 |
| D-QUA-01 | Chaîne du froid | Seuil sanitaire (durée maximale hors froid), procédure d'escalade | Paramètre `quality.cold_chain_max_minutes` ; contrôle conditionnement déjà obligatoire à « prête » et à l'enlèvement | Non renseigné | 🔴 |
| D-WA-01 | Numéro WhatsApp Business | Numéro international (ex. 221XXXXXXXXX) | Paramètre `business.whatsapp_number` | Vide en production ; numéro fictif `221700000000` en local | 🔴 |
| D-ZONE-01 | Zones desservies | Liste des zones/quartiers | Back-office › Livraisons › Zones & tarifs | 4 zones **fictives** (`is_demo`) | 🔴 |
| D-ZONE-02 | Tarifs de livraison | Frais client par zone, minimum de commande | Idem | Montants fictifs 1 500–3 000 FCFA | 🔴 |
| D-HOR-01 | Horaires et jours fermés | Horaires de livraison par jour, fermetures exceptionnelles | Paramètres `business.hours`, `ordering.closed_dates` | Lun–Sam 09:00–19:00 (démo) | 🟠 |
| D-HOR-02 | Délais et capacité | Délai minimal de commande, durée d'un créneau, capacité par créneau | `ordering.min_lead_minutes`, `ordering.slot_duration_minutes`, `ordering.slot_capacity` + temps de préparation par format | 240 min · 120 min · 5 commandes | 🟠 |
| D-RET-01 | Retrait sur place | Oui / non, adresse et horaires | `ordering.pickup_enabled` | Désactivé | 🟢 |
| D-PAY-01 | Wave | Contrat marchand, clés API, secret webhook, accès sandbox | `.env` (`WAVE_API_KEY`, `WAVE_WEBHOOK_SECRET`) + activer `payments.methods.wave` | Adaptateur codé selon la documentation publique, **jamais exécuté contre un compte réel** | 🔴 |
| D-PAY-02 | Orange Money | Souscription API Internet Orange Business, spécifications | Nouvel adaptateur à coder dans `OrangeMoneyGateway` | Emplacement prévu, **non implémenté** (spécifications remises après contrat) | 🟠 |
| D-PAY-03 | Carte bancaire | Choix d'un prestataire (agrégateur) et contrat | Nouvel adaptateur `PaymentGateway` | Non implémenté | 🟢 |
| D-PAY-04 | Paiement à la livraison | Autorisé ? Plafond ? | `payments.methods.cash_on_delivery` ; encaissement saisi par le livreur puis rapproché par le commercial | Désactivé | 🟠 |
| D-PAY-05 | Paiement avant acceptation | Faut-il exiger le paiement confirmé avant d'accepter une commande payée en ligne ? | `payments.require_payment_before_acceptance` | Oui | 🟠 |
| D-ANN-01 | Annulation / remboursement | Politique écrite (délais, frais engagés, produits déjà préparés) | Paramètre `policies.cancellation`, CGV | Annulation client possible uniquement avant acceptation ; tout remboursement sous double contrôle | 🔴 |
| D-LIV-01 | Rémunération des livreurs | Montant par course et par zone ; une course échouée est-elle payée ? | Champ « Rémunération livreur » de chaque zone ; règlements dans Livraisons › Règlements | Montants fictifs ; seules les courses **livrées** sont dues | 🟠 |
| D-LIV-02 | Preuve de remise | Types de preuve acceptés (confirmation orale, code, photo) | `delivery.proof_types` | `recipient_confirmation`, `photo_reference`, `code` | 🟠 |
| D-LIV-03 | Contrats livreurs | Conditions d'accès aux données clients, confidentialité | Juridique | — | 🔴 |
| D-JUR-01 | Mentions légales | Raison sociale, NINEA/RCCM, hébergeur | Contenus › `mentions-legales`, paramètres `business.legal_name`, `business.ninea` | Trame « à compléter » | 🔴 |
| D-JUR-02 | CGV | Texte validé par un conseil | Contenus › `conditions-generales` | Trame marquée « en attente de validation » | 🔴 |
| D-JUR-03 | Données personnelles | Formalités CDP (loi 2008-12), durées de conservation, hébergement hors Sénégal | Contenus › `confidentialite` ; politique de purge à coder une fois les durées fixées | Trame ; aucune purge automatique des données clients | 🔴 |
| D-JUR-04 | Factures / TVA | ~~Faut-il émettre une facture, appliquer une taxe ?~~ **Décidé** : factures obligatoires, TVA paramétrable (voir tableau des décisions validées) | Paramètres › Facturation et TVA ; Finances › Factures | Implémenté ; assujettissement TVA à confirmer par l'expert-comptable | 🟠 |
| D-NOT-01 | Notifications client | Canaux autorisés : email (prestataire SMTP), SMS, WhatsApp Business Platform (modèles approuvés) | `MAIL_*` ; nouveaux canaux à brancher sur `OrderNotifier` | Email via journal (`MAIL_MAILER=log`) en dev ; SMS/WhatsApp automatiques non configurés | 🟠 |
| D-STATS-01 | Taux de conversion | Choix d'un outil d'analytics (sessions qualifiées) et consentement cookies | Indicateur affiché « non mesuré » | — | 🟢 |
| D-CONT-01 | Témoignages | Avis clients réels et autorisation de publication | Contenus › bloc `home.testimonials` | 2 témoignages **fictifs** avec avertissement visible | 🟠 |
| D-HEB-01 | Hébergement / domaine / emails | Hébergeur, DNS, stockage objet, SMTP, sauvegardes | Voir `docs/11-DEPLOYMENT` | Non choisi | 🔴 |

## 2. Arbitrages techniques pris pendant le développement (à confirmer)

| ID | Contradiction ou lacune constatée | Arbitrage retenu | Justification |
|---|---|---|---|
| DEV-ARCH-001 | Hiérarchie PRD > schéma : le schéma prévoit `deliveries` séparée de `delivery_assignments` (relation 1:1) | Fusion dans `delivery_assignments` (enlèvement, remise, preuve, incident, règlement) | Supprime une jointure sans perte d'information ; une nouvelle mission est créée à chaque réaffectation, l'historique reste complet |
| DEV-ARCH-002 | L'API prévoit `POST /orders/{ref}/whatsapp-draft` alors que le brouillon naît du panier (pas encore de référence) | Ajout de `POST /checkout/whatsapp-draft` (création depuis le panier) ; `POST /orders/{ref}/whatsapp-draft` régénère le message d'une commande existante | Respecte US-004 « créer un brouillon avec référence puis ouvrir un message prérempli » |
| DEV-ARCH-003 | Machine à états : les règles §6 évoquent l'annulation après échec de livraison, absente du tableau §2 | `delivery_failed → cancelled` autorisé avec motif obligatoire (rôle commercial/admin) | « décision de relivraison ou annulation » (§6) |
| DEV-ARCH-004 | Réaffectation d'une mission acceptée mais non enlevée : transition `assigned → ready` absente | Transition système `assigned → ready` lorsque le responsable retire la mission | Nécessaire à la « réaffectation » (Module 8) |
| DEV-ARCH-005 | Retrait sur place : aucune transition de remise | Action `hand_over_pickup` (`ready → delivered`) disponible seulement si la commande est en retrait (option désactivée) | Cohérent avec D-RET-01 |
| DEV-ARCH-006 | `draft → pending_confirmation` « validation panier » | La commande web est créée directement en `pending_confirmation` ; `draft` est réservé aux brouillons WhatsApp, confirmés par le commercial (re-tarification au catalogue courant) | Évite des brouillons fantômes pour chaque panier |
| DEV-ARCH-007 | Suivi invité : l'API annonce 200/403, le test T-017 demande « 403 ou 404 indistinct » | Toujours **404** indistinct (référence inconnue, jeton absent, faux ou expiré) | Aucune information révélée (sécurité) |
| DEV-ARCH-008 | Approbation des remboursements : la matrice RBAC ne l'attribue pas | Demande : commercial/admin ; approbation : **administrateur différent du demandeur** (double contrôle SEC-014) | À confirmer : un second rôle « finance » peut être créé |
| DEV-ARCH-009 | Vérification d'un paiement : webhook signé **ou** consultation PSP (BR-005) | Les deux : webhook authentifié **puis** consultation serveur de l'état réel avant de passer `paid` | Protège contre un webhook rejoué ou forgé avec une clé fuitée |
| DEV-ARCH-010 | Stock | Le stock n'est suivi que si `stock_qty` est renseigné (sinon production à la commande) ; il est restitué à l'annulation uniquement avant préparation | Produits frais fabriqués sur commande |
| DEV-ARCH-011 | Clients invités | Pas de fusion des fiches clients par numéro de téléphone | Évite d'exposer l'historique d'une personne à une autre |
| DEV-ARCH-012 | SEO d'un SPA | Pré-rendu des métadonnées par Laravel (`SpaController`) + `sitemap.xml` + `robots.txt` | Pas de SSR complet : compromis simple compatible avec l'architecture retenue |
| DEV-ARCH-013 | TypeScript 7 fourni par le gabarit Vite | Retour à TypeScript 6.0 | `typescript-eslint` ne prend pas encore en charge TS 7 ; ESLint est exigé par les conventions |
| DEV-ARCH-014 | Avis clients | Dépôt depuis le lien de suivi après livraison, publication après modération | « avis modérés » (LOY-011) |
| DEV-ARCH-015 | Points de fidélité (LOY-011), GPS (GPS-012), commandes événementielles (EVT-013) | Non développés | Hors MVP selon le PRD (V2 / option) |
