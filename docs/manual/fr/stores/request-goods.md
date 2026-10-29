---
id: stores-request-goods
title: Demander des articles à un magasin
module: stores
order: 5
permissions: [stores.requisition.view, stores.requisition.create]
roles: [branch_manager, storekeeper, owner]
screens: [/stores/requisitions, /stores/requisitions/new, /stores/requisitions/:requisitionId]
---
Un service demande des articles à un magasin par une demande. Le chef de service l’approuve avant toute sortie.

1. Ouvrez « Demandes » sous « Magasins » et touchez « Nouvelle demande ».
2. Choisissez le « Magasin » sollicité et le « Service » demandeur. Choisissez le « Centre de coût » si les articles lui sont imputés, et réglez « Pour le ». Ajoutez une « Remarque » si utile.
3. Sous « Lignes », recherchez et choisissez l’« Article », choisissez l’« Unité », saisissez la « Quantité » et, si besoin, une « Note pour le magasin ». Touchez « Ajouter une ligne » pour en ajouter. Seuls les articles stockés peuvent être demandés.
4. Touchez « Envoyer pour approbation ». La demande reçoit un numéro et part chez le chef de service, qui la décide dans sa boîte d’approbations. Voir « Décider des approbations ».
5. Suivez-la sur la page de la demande. Son statut passe de « En attente d’approbation » à « Approuvée », puis « Sortie en partie » ou « Sortie ».

Pour arrêter une demande pas encore entièrement sortie, ouvrez-la et touchez « Annuler la demande ». Rien ne sera sorti pour elle et son approbation s’arrête. Le magasin sort ensuite les articles : voir « Sortir des articles pour un service ».
