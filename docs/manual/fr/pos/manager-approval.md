---
id: pos-manager-approval
title: Demander l'approbation d'un responsable
module: pos
order: 80
permissions: [pos.discount.give, pos.price.override, pos.sale.void, pos.sale.refund, pos.cash.move]
roles: [cashier, branch_manager]
screens: [pos:OverrideDialog]
---
Certaines actions demandent un responsable quand vous n'en avez pas le droit vous-même, ou quand le montant dépasse votre limite : une remise, un changement de prix, une annulation, un remboursement, et une entrée ou sortie d'espèces. La caisse ouvre « Approbation d'un responsable » et explique pourquoi.

Un responsable approuve sur la même caisse. Il n'a pas besoin de se connecter d'abord.

1. Demandez à un responsable de venir à la caisse.
2. Dans « Approbation d'un responsable », le responsable touche son propre nom.
3. Le responsable saisit son code PIN.
4. Le responsable touche « Approuver ».

Si le code PIN est faux, la caisse indique le nombre d'essais restants. Pour passer à une autre personne, touchez « Choisir une autre personne ». Si vous changez d'avis, fermez la fenêtre. La caisse indique « Non approuvé. Rien n'a été modifié. »

Le code PIN d'un responsable compte 6 chiffres. Si la caisse indique que personne ne peut approuver, demandez à un administrateur de donner un rôle à un responsable sur cet emplacement, puis touchez « Synchroniser ».
