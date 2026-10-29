---
id: fa-set-up-categories
title: Configurer les catégories d’immobilisations
module: fixed_assets
order: 1
permissions: [fixed_assets.asset.view, fixed_assets.category.manage]
roles: [accountant, admin, owner]
screens: [/fixed-assets/categories]
---
Une catégorie porte les règles d’amortissement par défaut d’un groupe d’immobilisations, comme les véhicules ou les ordinateurs. Ses comptes viennent des règles de comptabilisation du module Comptabilité.

1. Ouvrez « Catégories » sous « Immobilisations » et touchez « Nouvelle catégorie ».
2. Saisissez le « Code » et le « Nom ».
3. Choisissez la « Méthode d’amortissement » : « Linéaire », « Dégressif », « Unités d’œuvre » ou « Pas d’amortissement ».
4. Saisissez la « Durée d’utilité (mois) ». Pour le dégressif, saisissez aussi le « Taux annuel (%) ». Ni l’un ni l’autre n’est demandé pour les unités d’œuvre ou l’absence d’amortissement.
5. Saisissez la « Valeur résiduelle (%) », la part du coût que le bien devrait conserver à la fin.
6. Choisissez la « Classe fiscale » si vous utilisez l’amortissement fiscal, ou « Sans classe fiscale ».
7. Touchez « Enregistrer la catégorie ».

Touchez « Modifier » sur une ligne pour changer une catégorie. Touchez « Archiver » pour cesser d’en utiliser une et « Restaurer » pour la reprendre.

Pour que des achats créent des immobilisations, utilisez « Articles achetés comme immobilisations » : choisissez un « Article » et une « Catégorie » et touchez « Désigner comme immobilisation ». Les marchandises reçues ou facturées pour cet article deviennent alors des immobilisations en brouillon. Touchez « Retirer » pour retirer la désignation.
