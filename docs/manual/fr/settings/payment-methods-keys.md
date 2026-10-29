---
id: payment-methods-keys
title: Saisir les clés d'un fournisseur de paiement
module: core
order: 760
permissions: [core.payment_method.view, core.payment_method.edit, core.payment_method.configure]
roles: [owner, admin]
screens: [/settings/payment-methods]
---
Un mode de paiement mobile ou par carte a besoin des clés du fournisseur avant que la caisse puisse encaisser avec lui, par exemple le code court, la clé consommateur et la clé secrète (passkey) pour M-Pesa.

Avertissement : ces clés permettent au fournisseur de déplacer de l'argent réel. Saisissez-les uniquement depuis le portail du fournisseur, ne les envoyez jamais par courriel ou messagerie et ne les partagez jamais. Les identifiants enregistrés ne sont plus jamais affichés. Utilisez le bac à sable du fournisseur jusqu'à avoir testé un paiement de bout en bout.

1. Ouvrez Finances, puis « Moyens de paiement ».
2. Choisissez la société dans le sélecteur de société.
3. Touchez « Paramètres » sur le mode.
4. Remplissez chaque champ demandé, comme « Numéro court », « Clé client », « Secret client » et « Clé d’accès ». Pour M-Pesa, choisissez si le code court est de type « Paybill ou Till ».
5. Pour remplacer un identifiant enregistré, touchez « Remplacer » et saisissez la nouvelle valeur. Pour en effacer un, touchez « Retirer ».
6. Touchez « Enregistrer les paramètres ». La fenêtre liste ce qui est encore requis.
7. Quand tout est enregistré, activez le mode en caisse.

Un mode activé ne peut pas voir ses réglages supprimés. Désactivez-le d'abord.
