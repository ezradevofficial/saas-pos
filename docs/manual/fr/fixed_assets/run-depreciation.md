---
id: fa-run-depreciation
title: Lancer l’amortissement mensuel
module: fixed_assets
order: 4
permissions: [fixed_assets.depreciation.view, fixed_assets.depreciation.post]
roles: [accountant, owner]
screens: [/fixed-assets/runs, /fixed-assets/runs/:runId]
---
Il y a un calcul par mois, comptabilisé dans l’ordre. Chaque calcul est comptabilisé en comptabilité par succursale et centre de coût.

1. Ouvrez « Calculs d’amortissement » sous « Immobilisations ».
2. Sous « Calculer un mois », choisissez le « Mois » et touchez « Prévisualiser ». Rien n’est encore enregistré.
3. Lisez l’aperçu : « Immobilisations », « Total », la « Convention » et la dotation de chaque bien. « Aucune dotation ce mois-ci. » signifie que rien n’est dû.
4. Touchez « Comptabiliser » suivi du mois. Le calcul est comptabilisé et apparaît sous « Calculs comptabilisés ».

Les calculs se comptabilisent dans l’ordre : si vous choisissez un autre mois, la page indique le prochain à comptabiliser. Un mois d’une période comptable clôturée ne peut pas être comptabilisé.

Pour annuler un calcul, ouvrez-le depuis « Calculs comptabilisés », touchez « Annuler le calcul », saisissez le motif et confirmez. Les amortissements cumulés reviennent en arrière et les écritures sont contrepassées.
