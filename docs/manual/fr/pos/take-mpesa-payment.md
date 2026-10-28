---
id: pos-take-mpesa-payment
title: Encaisser un paiement M-Pesa
module: pos
order: 60
permissions: [pos.sale.create]
roles: [cashier, branch_manager]
screens: [pos:PaymentScreen]
---
Il y a deux façons d'encaisser M-Pesa. Envoyez une demande de paiement quand la caisse est en ligne. Saisissez le code du message du client quand elle ne l'est pas, ou quand la demande échoue.

## Envoyer une demande de paiement

1. Sur « Encaissement », touchez le moyen M-Pesa sous « Moyen de paiement ».
2. Vérifiez « Numéro de téléphone du client ». Il est rempli à partir du client si vous en avez ajouté un.
3. Touchez « Envoyer une demande de paiement de » avec le montant.
4. La caisse affiche « Demande envoyée au téléphone du client ». Le client confirme avec son code PIN M-Pesa.
5. Quand « Paiement reçu » apparaît, le paiement s'ajoute tout seul à la vente.
6. Touchez « Terminer la vente ».

Si le client ne confirme pas, la caisse indique « Aucun paiement ». Renvoyez la demande, ou saisissez le code.

## Saisir le code

1. Touchez le moyen M-Pesa.
2. Saisissez le code du message de confirmation du client dans « Code M-Pesa ». Il compte 8 à 12 lettres et chiffres.
3. Touchez « Ajouter le paiement », puis « Terminer la vente ».

La caisse vérifie le code à sa prochaine synchronisation avec le serveur. Un code ne peut payer qu'une seule vente. Si la caisse indique que le code a déjà payé une autre vente, vérifiez à nouveau le message du client.

Une fois la demande de paiement envoyée, les articles et le client de la vente sont verrouillés. Terminez la vente, ou remboursez d'abord le paiement.
