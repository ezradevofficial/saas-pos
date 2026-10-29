---
id: procurement-raise-requisition
title: Créer une demande d’achat
module: procurement
order: 1
permissions: [procurement.requisition.view, procurement.requisition.create]
roles: [branch_manager, procurement_officer, storekeeper, owner]
screens: [/procurement/requisitions, /procurement/requisitions/:requisitionId, /procurement/requisitions/:requisitionId/edit, /procurement/requisitions/new]
---
Une demande d’achat est une demande d’acheter des biens ou des services. Elle est approuvée avant tout achat.

1. Ouvrez « Demandes d’achat » sous « Achats » et touchez « Nouvelle demande d’achat ».
2. Choisissez la « Succursale ». Choisissez l’« Emplacement » et le « Service » s’ils s’appliquent, puis réglez « Nécessaire pour le » et « Devise ».
3. Saisissez l’« Objet » pour que l’approbateur comprenne le besoin.
4. Sous « Lignes », choisissez l’« Article », ou saisissez un « Libellé » quand ce n’est pas un article. Choisissez l’« Unité », puis saisissez la « Quantité » et le « Prix unitaire estimé ». Ajoutez le « Centre de coût » et le « Projet » si vous les utilisez. Touchez « Ajouter une ligne » pour en ajouter.
5. Touchez « Enregistrer la demande ». Elle est enregistrée comme brouillon.
6. Ouvrez le brouillon et touchez « Soumettre pour approbation ». L’approbateur la décide dans sa boîte d’approbations. Voir « Décider des approbations ».

Tant qu’elle est un brouillon, touchez « Modifier » pour la changer. Tant qu’elle attend l’approbation, touchez « Annuler la demande » pour l’arrêter. La demande est conservée, marquée annulée.

Une fois approuvée, la page de la demande propose l’étape suivante : « Demander des prix », « Lancer un appel d’offres » ou « Créer le bon de commande ». Le nouveau document reprend la succursale et les lignes de la demande.
