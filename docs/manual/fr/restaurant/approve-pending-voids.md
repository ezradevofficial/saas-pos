---
id: restaurant-approve-pending-voids
title: Approuver les annulations en attente d'un responsable
module: restaurant
order: 65
permissions: [pos.sale.void]
roles: [branch_manager]
screens: [/restaurant/pending-voids]
---
Un serveur peut demander de retirer un article après son envoi à un poste, depuis une caisse que le serveur n'a pas pu vérifier. L'article reste sur l'addition jusqu'à votre décision.

1. Ouvrez « Annulations en attente ». Chaque ligne montre la commande, l'article, sa valeur, qui l'a demandé, le motif et le moment.
2. Pour retirer l'article, touchez « Appliquer ». Confirmez : l'article quitte l'addition et le poste est prévenu.
3. Pour le garder, touchez « Rejeter » et confirmez. La demande est close.

Une ligne au-dessus de votre limite affiche « Au-dessus de votre limite » et seulement le bouton Rejeter. Demandez à un responsable de limite plus haute de l'appliquer.
