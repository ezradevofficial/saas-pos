---
id: accounting-record-payment
title: Enregistrer un encaissement ou un paiement
module: accounting
order: 17
permissions: [accounting.payment.view, accounting.payment.create]
roles: [accountant, owner]
screens: [/accounting/payments, /accounting/payments/:paymentId]
---
Un encaissement est de l’argent reçu d’un client ; un paiement est de l’argent versé à un fournisseur. Enregistrez-le sur le compte bancaire par lequel il est passé et affectez-le aux factures qu’il règle.

1. Ouvrez « Encaissements et paiements » sous « Comptabilité ». Cherchez par numéro ou référence, et filtrez par « Nature », « Du » et « Au ». « Non affecté » montre ce qui n’est pas encore rapproché d’une facture.
2. Touchez « Enregistrer un règlement ».
3. Choisissez la « Nature » : « Encaissement » ou « Paiement ».
4. Choisissez le « Contact » et le « Compte bancaire ». Réglez la « Date » et saisissez le « Montant ».
5. Saisissez la « Référence » donnée par la banque ou M-Pesa, pour rapprocher la ligne de relevé plus tard.
6. Sous « Factures ouvertes », saisissez dans « Affecter » la part du montant qui règle chaque facture. Si rien n’est ouvert pour le contact, vous voyez « Rien n’est ouvert pour ce contact dans cette devise. »
7. Touchez « Enregistrer l’encaissement » ou « Enregistrer le paiement ».

Touchez une ligne pour l’ouvrir. Le règlement montre son « Écriture » avec « Ouvrir l’écriture », et sous « Règlements » les factures qu’il a soldées, ou « Rien n’est affecté pour l’instant. » Quand le règlement est dans une autre devise, un « Écart de change » apparaît. Pour le rapprocher d’un relevé bancaire, voir « Rapprocher un compte bancaire ou de monnaie mobile ».
