---
id: pos-give-discount
title: Accorder une remise
module: pos
order: 40
permissions: [pos.sale.create, pos.discount.give]
roles: [cashier, branch_manager]
screens: [pos:LineDialog, pos:OverrideDialog]
---
Une remise se déduit d'une ligne. Votre rôle fixe la remise maximale que vous pouvez accorder, en pourcentage de la ligne.

1. Touchez la ligne dans « Vente en cours ».
2. Saisissez le montant dans « Remise sur la ligne ». Il ne peut pas dépasser le prix de la ligne.
3. Touchez « Enregistrer la ligne ». La remise apparaît sur la ligne et dans le total de la vente.

Si la remise dépasse votre limite, ou si votre rôle ne permet pas d'accorder des remises, la caisse ouvre « Approbation d'un responsable ». Un responsable choisit son nom, saisit son code PIN et touche « Approuver ». Voir « Demander l'approbation d'un responsable ».

Changer la quantité peut faire dépasser votre limite à une remise. Dans ce cas, la caisse retire la remise et vous le dit. Demandez à un responsable de l'accorder à nouveau.

Le rôle de caissier n'a pas de droit de remise propre : les remises d'un caissier demandent donc un responsable. Les administrateurs peuvent donner une limite à un rôle sous Paramètres, Rôles.
