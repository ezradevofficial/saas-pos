---
id: fa-dispose-asset
title: Sortir une immobilisation
module: fixed_assets
order: 6
permissions: [fixed_assets.asset.view, fixed_assets.disposal.request, fixed_assets.disposal.approve]
roles: [branch_manager, owner]
screens: [/fixed-assets/disposals, /fixed-assets/assets/:assetId, /fixed-assets/disposals/:disposalId]
---
Une sortie retire une immobilisation de l’usage : cession, mise au rebut ou perte. Elle est approuvée avant d’être comptabilisée.

1. Ouvrez « Registre des immobilisations » sous « Immobilisations » et ouvrez une immobilisation en service.
2. Touchez « Sortir ».
3. Choisissez le « Type » : « Cession », « Mise au rebut » ou « Perte ». Réglez la « Date de sortie ».
4. Pour une cession, saisissez le « Prix de cession » dans la bonne devise.
5. Saisissez le « Motif » et touchez « Demander la sortie ». La sortie s’ouvre sur sa propre page, « En attente d’approbation ».
6. Un responsable d’agence la décide dans sa boîte d’approbations. Voir « Décider des approbations ».
7. Une fois approuvée, ouvrez la sortie et touchez « Comptabiliser la sortie ». L’immobilisation quitte le registre comme « Sortie ».

La page de la sortie montre alors « Ce qui a été comptabilisé » : le dernier amortissement, le prix en devise de base et la « Plus ou moins-value ». Ouvrez « Sorties » sous « Immobilisations » pour la liste. Pour arrêter une demande en attente ou approuvée, touchez « Annuler la sortie ».
