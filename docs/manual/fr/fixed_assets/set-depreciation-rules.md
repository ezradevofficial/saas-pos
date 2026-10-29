---
id: fa-set-depreciation-rules
title: Régler la convention d’amortissement et l’amortissement fiscal
module: fixed_assets
order: 11
permissions: [fixed_assets.settings.edit, fixed_assets.tax.view]
roles: [admin, owner]
screens: [/fixed-assets/settings]
---
Deux paramètres de société façonnent l’amortissement.

1. Ouvrez « Paramètres » sous « Immobilisations ». Si vous avez plusieurs sociétés, choisissez-en une dans le sélecteur de société.
2. Sous « Amortissement », choisissez la « Convention » : « Selon les jours en service », ou « Demi-mois le premier et le dernier mois ». Le choix s’enregistre aussitôt. Elle est figée une fois un calcul comptabilisé : la page indique alors « Des amortissements sont déjà comptabilisés avec cette convention. »
3. Sous « Amortissement fiscal », activez « Utiliser l’amortissement fiscal » pour tenir les amortissements fiscaux à côté de l’amortissement comptable. Le tableau des classes fiscales liste chaque classe avec sa méthode et son « Taux annuel ».
4. Une classe marquée « À confirmer » n’a pas encore de taux confirmé dans le pack pays. La page indique alors que l’amortissement fiscal n’est pas encore utilisable ; donnez en attendant une classe fiscale à chaque catégorie. « Ce que le propriétaire doit confirmer » liste ce qui manque.

Sans le droit de modifier, les contrôles sont en lecture seule.
