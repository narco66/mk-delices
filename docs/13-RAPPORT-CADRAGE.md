# Rapport de cadrage transversal — MK Délices

## Livrables
12 documents en deux formats, spécification OpenAPI initiale, matrice de traçabilité, analyse préalable.

## Contrôle de cohérence
- Rôles communs : VISITEUR, CLIENT, COMMERCIAL, PREPARATEUR, RESP_LIVRAISON, LIVREUR, ADMIN ; les libellés peuvent être présentés différemment mais doivent mapper aux mêmes permissions.
- Paiement confirmé seulement après vérification fiable du PSP ; jamais par retour navigateur.
- Commande et paiement possèdent des états distincts et les missions de livreur leurs états propres.
- Les API sont protégées par rôle et propriété ; catalogue public en lecture.
- Les données de référence sont des exemples et le budget est une estimation non contractuelle.

## Réserves
Le fichier OpenAPI constitue une base d inventaire ; ses schémas doivent être affinés avant début de codage. Les maquettes Figma ne sont pas générées : leur spécification est fournie. La validation réglementaire, commerciale et contractuelle incombe au porteur du projet.

## Go/no-go
Réunir les décisions sur logo, produits/prix, chaîne du froid, zones, livreurs, CGV, moyens de paiement contractés et niveaux de service.
