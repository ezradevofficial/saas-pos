---
id: accounting-post-journal
title: Saisir et comptabiliser une écriture
module: accounting
order: 20
permissions: [accounting.journal.view, accounting.journal.create, accounting.journal.approve, accounting.journal.reverse]
roles: [accountant]
screens: [/accounting/journals, /accounting/journals/new, /accounting/journals/:journalId]
---
Une écriture manuelle enregistre ce qu’aucun autre document ne génère à votre place.

1. Ouvrez « Écritures » sous « Comptabilité » et touchez « Nouvelle écriture ».
2. Choisissez la « Date ». Ajoutez une « Référence » et un « Mémo » si utile.
3. Sur chaque ligne, choisissez le « Compte » et saisissez un montant en « Débit » ou en « Crédit ». Ajoutez un « Libellé » si utile. Pour une autre devise, choisissez la « Devise » et vérifiez le taux.
4. Touchez « Ajouter une ligne » jusqu’à ce que l’écriture soit complète. Vérifiez que « Écart » indique « Équilibrée ».
5. Touchez « Enregistrer le brouillon ». Utilisez « Modifier » pour changer un brouillon, ou « Annuler l’écriture » pour l’abandonner.
6. Touchez « Soumettre à l’approbation ». L’écriture est « En attente d’approbation » ; touchez « Ouvrir les approbations » pour la suivre.
7. Une fois l’écriture approuvée, touchez « Comptabiliser ».

Pour annuler une écriture comptabilisée, ouvrez-la, touchez « Contre-passer », choisissez la « Date de contre-passation » et confirmez. Une nouvelle écriture inverse chaque ligne et l’originale reste inchangée.
