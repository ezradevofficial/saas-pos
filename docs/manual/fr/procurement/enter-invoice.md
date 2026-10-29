---
id: procurement-enter-invoice
title: Saisir et approuver une facture fournisseur
module: procurement
order: 12
permissions: [procurement.invoice.view, procurement.invoice.create, procurement.invoice.approve, procurement.invoice.override]
roles: [accountant, procurement_officer, owner]
screens: [/procurement/invoices, /procurement/invoices/:invoiceId]
---
Chaque facture fournisseur est rapprochée du bon de commande (prix) et de ce qui a été reçu et pas encore facturé (quantité).

1. Ouvrez « Bons de commande » sous « Achats » et ouvrez un bon approuvé. Touchez « Saisir la facture ».
2. Saisissez le « Numéro de facture du fournisseur » et la « Date de facture ». Réglez la « Date d’échéance » si vous l’avez, et « Prix toutes taxes comprises » si nécessaire.
3. Pour chaque ligne, saisissez la « Quantité facturée » et le « Prix unitaire », et choisissez le « Code de taxe ».
4. Touchez « Enregistrer et rapprocher ». La page de la facture s’ouvre avec le « Rapprochement à trois voies » : chaque ligne comparée à la commande et aux réceptions, dans les tolérances de la société.
5. Si toutes les lignes correspondent, la facture affiche « Rapproché ». Touchez « Approuver la facture ». L’approbation fige le taux et les montants.
6. Si au moins une ligne diffère, la facture affiche « Cette facture ne correspond pas » et ses lignes indiquent « Écart de prix » ou « Plus que reçu ». Une personne autorisée à approuver les écarts touche « Approuver malgré l’écart », saisit le « Motif de l’approbation » et confirme. Le motif est enregistré. Sans ce droit, demandez-lui de l’approuver.

Touchez « Annuler la facture » pour arrêter une facture non approuvée ; elle est conservée, marquée annulée. Ouvrez « Factures fournisseurs » sous « Achats » pour les chercher et les filtrer par statut ou fournisseur. Les tolérances se règlent dans « Paramètres des achats ».
