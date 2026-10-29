---
id: fuel-issue-a-fuel-voucher
title: Émettre et annuler un bon de carburant
module: fuel
order: 56
permissions: [fuel.voucher.view, fuel.voucher.manage]
roles: [station_manager]
screens: [/fuel/vouchers, /fuel/vouchers/:voucherId]
---
Un bon est de la valeur de carburant prépayée. Il se dépense en caisse comme un paiement et son solde diminue.

1. Ouvrez « Bons de carburant ». Cherchez par code ou filtrez par statut.
2. Touchez « Nouveau bon ».
3. Choisissez la « Devise » et saisissez la « Valeur ». Le bon ne peut être dépensé que dans cette devise.
4. Laissez « Code » vide pour en générer un. Choisissez le « Client » si le bon lui appartient, et le « Type de carburant » s'il est limité à un seul. Réglez « Expire le » s'il a une dernière date.
5. Touchez « Émettre le bon ». Touchez « Ouvrir le bon » pour le lire.
6. Sur la page du bon, « Utilisations » liste chaque dépense, avec le montant et la date.
7. Pour en annuler un, touchez « Annuler le bon », saisissez le « Motif » et confirmez.

Un bon annulé ne peut plus être dépensé. Ses utilisations restent dans les registres. Pour dépenser un bon à la pompe, voir « Vendre du carburant à la pompe ».
