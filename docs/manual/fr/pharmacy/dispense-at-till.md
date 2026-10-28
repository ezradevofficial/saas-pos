---
id: pharmacy-dispense-at-till
title: Délivrer des médicaments en caisse
module: pharmacy
order: 10
permissions: [pos.sale.create, pharmacy.item.view]
roles: [pharmacist, pharmacy_technician]
screens: [pos:SellScreen]
---
Vendez des médicaments en caisse. La caisse choisit le lot, demande une ordonnance quand l'article l'exige et demande un pharmacien quand les règles l'imposent.

1. Ajoutez les articles à la vente. Choisissez le client qui achète si la caisse affiche « Choisissez le client qui achète ».
2. Par défaut, le lot à la péremption la plus proche est utilisé. Pour en choisir un autre, touchez « Choisir le lot », puis « Utiliser le lot <lot> ». Un lot marqué « Péremption proche » se vend encore ; un lot périmé, rappelé ou en attente est refusé.
3. Si « Cette vente demande une ordonnance. », touchez « Ajouter l’ordonnance ». Remplissez « Nom du patient », « Nom du prescripteur », « Numéro de licence du prescripteur », « Établissement » et « Date de prescription », puis touchez « Enregistrer l’ordonnance ».
4. Si « Un pharmacien doit approuver cette vente. », un pharmacien touche « Demander l’approbation d’un pharmacien ». Sans ce droit, un préparateur ne peut pas approuver seul.
5. Encaissez la vente comme d'habitude.

Voir « Vendre des articles » pour le reste de la vente.
