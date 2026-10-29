---
id: payment-methods-callbacks
title: Enregistrer les URL de rappel M-Pesa
module: core
order: 770
permissions: [core.payment_method.view, core.payment_method.edit, core.payment_method.configure]
roles: [owner, admin]
screens: [/settings/payment-methods]
---
Safaricom envoie les résultats de paiement à des adresses de rappel. Chaque adresse contient un jeton secret.

Avertissement : ne partagez ces adresses que via le portail Daraja. Obtenir de nouvelles URL remplace immédiatement les anciennes ; les paiements envoyés aux anciennes adresses sont perdus tant que vous n'avez pas enregistré les nouvelles auprès de Safaricom.

1. Ouvrez Finances, puis « Moyens de paiement ».
2. Saisissez d'abord le code court et les identifiants (voir « Saisir les clés d'un fournisseur de paiement »).
3. Touchez « Adresses de rappel » sur le mode M-Pesa.
4. Copiez chaque URL de « Adresses de rappel » et saisissez-la dans le portail Daraja.
5. Touchez « Enregistrer les adresses C2B ». La page confirme quand Safaricom a accepté les URL de confirmation et de validation.

Si une adresse a pu fuiter, touchez « Obtenir de nouvelles adresses », puis « Remplacer les adresses », et enregistrez aussitôt les nouvelles URL auprès de Safaricom.
