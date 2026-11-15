# Propositions à valider — MK Délices

Contenus rédigés pour la mise en ligne, **à valider par la direction (et par un juriste pour les textes légaux)**. Rien n'est publié automatiquement.

| Fichier | Contenu | Qui valide | Chargement dans l'application |
|---|---|---|---|
| `01-CATALOGUE-ET-PRIX.md` | 8 produits, fiches complètes, grille de prix, méthode de vérification par le coût de revient | Direction + atelier | `php artisan mk:import-catalogue docs/propositions/catalogue-propose.csv` (produits créés **en brouillon**), puis publication dans Back-office › Catalogue |
| `catalogue-propose.csv` | Le même catalogue, au format importable (ouvrable dans Excel) | — | Option `--dry-run` pour vérifier sans rien enregistrer |
| `02-ZONES-ET-LIVRAISON.md` | 4 zones pilotes, tarifs, minimums, rémunération livreurs, horaires, règles d'échec | Direction + responsable livraisons | Back-office › Livraisons › Zones & tarifs ; Paramètres (horaires, créneaux) |
| `03-PAIEMENT-DOSSIER.md` | Pièces à réunir, message type à Wave / Orange / agrégateur, grille de comparaison | Direction | Activation dans Paramètres une fois le contrat signé et testé |
| `04-CGV.md` | Conditions générales de vente | Direction + **juriste** | `php artisan mk:import-content conditions-generales docs/propositions/04-CGV.md --validated` |
| `05-POLITIQUE-CONFIDENTIALITE.md` | Politique de confidentialité | Direction + **juriste** (+ déclaration CDP) | `php artisan mk:import-content confidentialite docs/propositions/05-POLITIQUE-CONFIDENTIALITE.md --validated` |
| `06-MENTIONS-LEGALES.md` | Mentions légales | Direction | `php artisan mk:import-content mentions-legales docs/propositions/06-MENTIONS-LEGALES.md --validated` |

**Avant `--validated` :** retirer l'encadré « Projet … à faire valider » en tête de fichier et compléter tous les `[À COMPLÉTER]`. Sans `--validated`, la page reste marquée « en attente de validation » sur le site.

Vue d'ensemble et calendrier : `../PROPOSITIONS-VALIDATION-MK-DELICES.md`.
