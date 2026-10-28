---
id: restaurant-send-to-kitchen
title: Prendre une commande et l'envoyer en cuisine
module: restaurant
order: 20
permissions: [restaurant.order.edit, restaurant.order.send, restaurant.ticket.serve]
roles: [waiter, bartender]
screens: [pos:OrderScreen]
---
Ajoutez ce que les clients demandent, puis envoyez-le en cuisine ou au bar.

1. Choisissez une catégorie, puis touchez un article dans « Carte » pour l'ajouter. Si l'article a des choix, faites-les, puis touchez « Ajouter à la commande ».
2. Pour modifier une ligne, touchez-la. Vous pouvez régler « Quantité », « Service », « Place » et « Note pour la cuisine », ou toucher « Changer les options ». Touchez « Enregistrer la ligne ».
3. Touchez « Envoyer <n> articles en cuisine ». Les articles partent vers leur poste dans un nouvel envoi.
4. Quand vous êtes prêt pour un service suivant, touchez « Lancer le service <n> » pour envoyer les articles retenus.
5. Sous « Tickets de cuisine et de bar », touchez « Marquer servi » quand le plat du poste arrive à la table.

Pour renvoyer un envoi, touchez « Renvoyer », choisissez l'envoi : la cuisine reçoit un nouveau ticket marqué renvoi. Si « Aucune imprimante de cuisine n’est connectée », le ticket est sur l'écran de cuisine.
