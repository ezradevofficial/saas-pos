---
id: ecommerce-refund-order
title: Rembourser une commande en ligne
module: ecommerce
order: 50
permissions: [ecommerce.order.view, ecommerce.order.refund]
roles: [owner, branch_manager]
screens: [/ecommerce/orders, /ecommerce/orders/:orderId]
---
Rendez l'argent à un client pour une commande annulée ou retournée.

1. Ouvrez « Commandes en ligne » et touchez une commande dont le paiement indique « Remboursement dû ».
2. Touchez « Enregistrer le remboursement ».
3. L'argent est rendu par le moyen de paiement du client. Si ce n'est pas possible ici, remboursez à la main et saisissez sa référence sous « Référence du remboursement ».
4. Confirmez. Le statut du paiement devient « Remboursée ».

Un remboursement au-dessus de votre plafond, avec les autres remboursements de la commande, attend l'approbation d'un responsable dans Approbations. Rien ne bouge avant. Voir « Décider des approbations ».
