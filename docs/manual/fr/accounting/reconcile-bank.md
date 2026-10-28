---
id: accounting-reconcile-bank
title: Rapprocher un compte bancaire ou de monnaie mobile
module: accounting
order: 30
permissions: [accounting.bank.view, accounting.bank.reconcile]
roles: [accountant]
screens: [/accounting/bank, /accounting/bank/:bankId]
---
Le rapprochement fait correspondre les lignes d’un relevé avec la comptabilité.

1. Ouvrez « Banque et monnaie mobile » sous « Comptabilité » et ouvrez le compte.
2. Touchez « Importer un relevé ». Choisissez le « Format » : « Fichier CSV » ou « Relevé M-Pesa ». Pour un fichier CSV, indiquez la colonne de la date, du libellé, de la référence et du montant.
3. Choisissez le « Fichier » et touchez « Importer un relevé ». Pour M-Pesa, utilisez l’export du relevé professionnel ; seules les opérations terminées sont importées.
4. Touchez « Rapprocher les cas certains ». Les lignes qui correspondent par montant, ou qu’une règle de comptabilisation couvre, sont rapprochées pour vous.
5. Pour les lignes restantes, touchez une ligne du relevé, puis les lignes comptables qui lui correspondent. Quand « Choisi » égale le montant du relevé, touchez « Rapprocher ».
6. Pour une ligne sans écriture, comme des frais bancaires, choisissez le compte dans « Comptabiliser sur » et touchez « Comptabiliser et rapprocher ».

La synthèse indique le « Solde du relevé », le « Solde comptable » et ce qui reste non rapproché de chaque côté.
