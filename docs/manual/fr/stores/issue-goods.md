---
id: stores-issue-goods
title: Sortir des articles pour un service
module: stores
order: 10
permissions: [stores.requisition.view, stores.issue.view, stores.issue.create, stores.return.create]
roles: [storekeeper, branch_manager]
screens: [/stores/requisitions/:requisitionId, /stores/issues/:issueId]
---
Un service demande au magasin par une demande. Son responsable l’approuve, puis le magasin sort les articles.

1. Ouvrez « Demandes » sous « Magasins ». Ouvrez une demande approuvée.
2. Dans « Sortir des articles », lisez « reste » pour chaque ligne.
3. Saisissez dans « Sortir » la quantité que vous remettez. Les lignes vides ne sortent pas.
4. Touchez « Sortir les articles ». Les articles sortent sous un nouveau numéro.
5. Pour imprimer le « Bon de sortie » ou le « Bon de livraison », ouvrez la sortie sous « Sorties » et touchez « Imprimer ».
6. Le service confirme avec « Accuser réception ».

Si des articles reviennent, ouvrez la sortie, touchez « Retourner des articles », saisissez les quantités dans « Retour » et touchez « Retourner au magasin ». Pour cesser de sortir sur une demande, touchez « Clôturer la demande ».
