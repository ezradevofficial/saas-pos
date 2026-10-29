---
id: accounting-invoices-and-bills
title: Établir et valider une facture
module: accounting
order: 15
permissions: [accounting.invoice.view, accounting.invoice.create, accounting.invoice.post]
roles: [accountant, owner]
screens: [/accounting/documents, /accounting/documents/new, /accounting/documents/:documentId]
---
Une facture ou un avoir part chez un client ; une facture fournisseur ou un avoir fournisseur vient d’un fournisseur. La validation crée l’écriture.

1. Ouvrez « Factures » sous « Comptabilité ». Les onglets « Clients » et « Fournisseurs » listent les documents. Cherchez par numéro ou référence, et filtrez par « Statut », « Du » et « Au ».
2. Touchez « Nouvelle facture » sur « Clients », ou « Nouvelle facture fournisseur » sur « Fournisseurs ».
3. Choisissez la « Nature » : par exemple « Facture » ou « Avoir ». Pour un avoir ou un avoir fournisseur, choisissez la « Facture concernée ».
4. Choisissez le « Contact », la « Devise », la « Date d’émission » et l’« Échéance ». Laissez « Échéance » vide pour appliquer les conditions de paiement du contact. Pour une facture fournisseur, saisissez le « Numéro du fournisseur ».
5. Activez « Prix toutes taxes comprises » si les prix unitaires incluent déjà la taxe.
6. Sous « Lignes », saisissez un « Libellé », choisissez le « Compte », saisissez la « Quantité » et le « Prix unitaire », et choisissez la « Taxe ». Touchez « Ajouter une ligne » pour en ajouter.
7. Touchez « Enregistrer le brouillon ». Le document est un « Brouillon » sans numéro.
8. Ouvrez-le, vérifiez les lignes, puis touchez « Valider ». Il reçoit son numéro et devient « Validé ». Touchez « Annuler le brouillon » pour abandonner un brouillon.

Si une ligne affiche « Taux à saisir », son code de taxe n’a pas encore de taux. Saisissez le taux confirmé dans les paramètres de taxe, puis validez.

Un document validé montre ce qui reste « Restant dû », son « Écriture » avec « Ouvrir l’écriture », et ses « Règlements ». Touchez « Imprimer » pour obtenir un PDF. Pour solder un avoir validé avec des factures ouvertes, touchez « Solder des factures ». Pour encaisser ou payer de l’argent sur une facture, voir « Enregistrer un encaissement ou un paiement ».
