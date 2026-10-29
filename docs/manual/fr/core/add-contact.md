---
id: core-add-contact
title: Ajouter un client ou un fournisseur
module: core
order: 40
permissions: [core.party.view, core.party.create, core.party.edit, core.party.archive]
roles: [sales_rep, accountant, procurement_officer, branch_manager, owner, admin]
screens: [/contacts/customers, /contacts/customers/new, /contacts/customers/:partyId, /contacts/suppliers, /contacts/suppliers/new, /contacts/suppliers/:partyId]
---
Les clients sont ceux à qui vous vendez ; les fournisseurs, ceux à qui vous achetez. Un nom suffit pour commencer. Ajoutez des numéros de téléphone pour que la caisse retrouve le client.

1. Ouvrez « Clients » ou « Fournisseurs » sous « Contacts ». Cherchez avec « Nom, identifiant fiscal ou téléphone », ou utilisez les onglets « Actifs » et « Archivés ».
2. Touchez « Ajouter un client » ou « Ajouter un fournisseur ».
3. Saisissez le « Nom ». Choisissez la « Nature », « Personne » ou « Organisation ». Ajoutez la « Raison sociale » et l’« Identifiant fiscal » si vous les avez (KRA PIN au Kenya, NIF en RD Congo).
4. Sous « Rôles », gardez le rôle proposé par la page. Un contact peut être à la fois client et fournisseur.
5. Sous « Numéros de téléphone », touchez « Ajouter un numéro » et saisissez le numéro. Ajoutez les « Adresses e-mail » et les « Adresses » de la même façon.
6. Sous « Conditions », choisissez la « Devise », le « Délai de paiement » en jours, la « Liste de prix » et, pour un client, le « Plafond de crédit ». Laissez « Plafond de crédit » vide pour ne pas en fixer.
7. Touchez « Créer le client » ou « Créer le fournisseur ».

Le contact s’ouvre avec un onglet « Détails » et un onglet « Historique ». Pour le modifier, changez les champs et touchez « Enregistrer les modifications ». Si {{app_name}} trouve un contact qui lui ressemble, il l’affiche au-dessus de la page pour que vous vérifiiez un éventuel doublon.

Pour retirer un contact des listes et de la caisse, ouvrez-le et touchez « Archiver », puis « Archiver le contact ». Son historique est conservé. Touchez « Restaurer » dans l’onglet « Archivés » pour le remettre.

Si vous ne pouvez pas fixer un plafond de crédit directement, vous ne pouvez que le baisser ici. Voir « Demander une modification de plafond de crédit ».
