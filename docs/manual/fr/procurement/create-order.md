---
id: procurement-create-order
title: Créer et envoyer un bon de commande
module: procurement
order: 8
permissions: [procurement.po.view, procurement.po.create, procurement.po.print]
roles: [procurement_officer, branch_manager, owner]
screens: [/procurement/orders, /procurement/orders/new, /procurement/orders/:orderId]
---
Un bon de commande est approuvé avant d’être envoyé au fournisseur.

1. Ouvrez « Bons de commande » sous « Achats » et touchez « Nouveau bon de commande ». Pour partir d’une demande d’achat approuvée, ouvrez-la et touchez « Créer le bon de commande » : sa succursale et ses lignes sont reprises. L’attribution d’une demande de prix ou d’un appel d’offres crée aussi des bons brouillons.
2. Choisissez la « Succursale » et le « Fournisseur ». Choisissez le « Lieu de livraison », réglez la « Date de commande », la « Date prévue » et la « Devise ».
3. Activez « Prix toutes taxes comprises » si les prix unitaires incluent déjà la taxe. Désactivé, la taxe s’y ajoute.
4. Sous « Lignes », choisissez l’« Article », l’« Unité », la « Quantité » et le « Prix unitaire », et un « Code de taxe ». Touchez « Ajouter une ligne » pour en ajouter. Ajoutez des « Conditions » si utile.
5. Touchez « Enregistrer le bon de commande ». Il est enregistré comme brouillon.
6. Ouvrez-le et touchez « Soumettre pour approbation ». Si une ligne affiche « Taux à saisir », un code de taxe n’a pas de taux en vigueur à la date de commande : ajoutez le taux dans Taxes ou choisissez un autre code d’abord.
7. Quand le bon est approuvé, touchez « Envoyer au fournisseur ». Le bon est marqué envoyé et son PDF est envoyé par e-mail. Laissez « E-mail » vide pour utiliser l’e-mail du fournisseur, ou saisissez une autre adresse.

Touchez « Télécharger le PDF » pour en obtenir une copie. Touchez « Modifier » tant qu’il est un brouillon. Touchez « Annuler la commande » pour l’arrêter ; il est conservé, marqué annulé. Depuis un bon approuvé, « Réceptionner » lance la livraison (voir « Réceptionner des marchandises d’un bon de commande ») et « Saisir la facture » lance la facture du fournisseur (voir « Saisir et approuver une facture fournisseur »).
