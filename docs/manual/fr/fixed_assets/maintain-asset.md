---
id: fa-maintain-asset
title: Planifier et enregistrer la maintenance des immobilisations
module: fixed_assets
order: 8
permissions: [fixed_assets.maintenance.view, fixed_assets.maintenance.manage]
roles: [branch_manager, accountant, owner]
screens: [/fixed-assets/maintenance, /fixed-assets/assets/:assetId]
---
Les plans de maintenance disent quand une immobilisation doit être entretenue. Les ordres de maintenance enregistrent le travail.

1. Ouvrez une immobilisation sous « Registre des immobilisations » et choisissez l’onglet « Relevés et maintenance ».
2. Pour noter un relevé de compteur, touchez « Ajouter un relevé », réglez la « Date », saisissez le « Relevé » et une « Note », puis touchez « Enregistrer le relevé ».
3. Pour planifier un entretien récurrent, touchez « Ajouter un plan ». Saisissez le « Nom » et choisissez la « Base » : « Selon le temps » demande « Tous les (jours) » ; « Selon l’usage » demande « Toutes les (unités) ». Réglez « Prévenir jours avant » et touchez « Enregistrer le plan ».
4. Pour enregistrer un travail, touchez « Ajouter un ordre ». Saisissez le « Titre », choisissez le « Plan » s’il en dépend un, réglez l’« Échéance » et touchez « Enregistrer l’ordre ».
5. Ouvrez « Maintenance » sous « Immobilisations » pour suivre tous les travaux. L’onglet « Ordres de maintenance » liste chaque travail avec son statut et son échéance ; l’onglet « Plans » liste les plans et leur « Prochaine échéance ».
6. Quand le travail est fait, touchez sa ligne dans « Ordres de maintenance ». Choisissez le « Statut » (« Ouvert », « En cours » ou « Terminé »). Pour « Terminé », réglez « Terminé le » et le « Relevé », et saisissez le « Coût ». Touchez « Enregistrer l’ordre ».

L’historique de l’immobilisation garde la maintenance faite.
