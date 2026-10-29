---
id: fa-register-asset
title: Enregistrer et mettre en service une immobilisation
module: fixed_assets
order: 2
permissions: [fixed_assets.asset.view, fixed_assets.asset.manage]
roles: [branch_manager, accountant, owner]
screens: [/fixed-assets/assets, /fixed-assets/assets/new, /fixed-assets/assets/:assetId, /fixed-assets/assets/:assetId/edit]
---
Une immobilisation est un brouillon jusqu’à sa mise en service, qui l’immobilise et lance son amortissement.

1. Ouvrez « Registre des immobilisations » sous « Immobilisations » et touchez « Enregistrer une immobilisation ».
2. Sous « Immobilisation », saisissez le « Nom ». Laissez « Numéro » vide pour le faire numéroter. Choisissez l’« Emplacement » et la « Catégorie », et saisissez le « Numéro de série » et une « Description » si vous les avez.
3. Sous « Acquisition », réglez la « Date d’acquisition » et la « Date de mise en service » (l’amortissement commence là ; c’est la date d’acquisition par défaut). Vérifiez la « Devise », saisissez le « Coût », et la « Référence fournisseur » et « Garantie jusqu’au » si vous les avez.
4. Sous « Amortissement », les règles de la catégorie s’appliquent sauf si vous en choisissez d’autres : changez la « Méthode d’amortissement », la « Durée d’utilité (mois) » ou la « Valeur résiduelle » pour ce bien seulement.
5. Touchez « Enregistrer le brouillon ».
6. Ouvrez le brouillon. Touchez « Mettre en service », vérifiez la « Date de mise en service » et confirmez. L’immobilisation est inscrite au cours de la date d’acquisition et s’amortit à partir de sa mise en service.

Tant qu’elle est un brouillon, touchez « Modifier » pour la changer, ou « Annuler le brouillon » pour l’abandonner ; le brouillon est conservé, marqué annulé. Sur une immobilisation en service, touchez « Imprimer l’étiquette » pour son numéro, et lisez :

- « Plan d’amortissement » : les dotations comptabilisées et prévues ;
- « Historique » : tout ce qui est arrivé à l’immobilisation ;
- « Documents » : touchez « Envoyer un fichier » pour joindre une facture ou une garantie, « Télécharger » pour la lire, « Archiver » pour la retirer.

Pour retrouver une immobilisation, utilisez « Rechercher par numéro, nom ou numéro de série » et les filtres « Statut », « Catégorie », « Emplacement » et les dates.
