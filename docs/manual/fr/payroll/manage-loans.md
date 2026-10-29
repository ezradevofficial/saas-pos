---
id: payroll-manage-loans
title: Enregistrer et solder un prêt à un employé
module: payroll
order: 8
permissions: [payroll.loan.view, payroll.loan.manage]
roles: [payroll_officer, owner]
screens: [/payroll/loans, /payroll/loans/:loanId]
---
Chaque paie retient la mensualité du prêt jusqu’au remboursement complet. Aucun intérêt n’est calculé.

1. Ouvrez « Prêts » sous « Paie ». Cherchez avec « Rechercher des prêts » et filtrez par statut, par exemple « Actif » ou « Soldé ».
2. Touchez « Nouveau prêt ».
3. Choisissez l’« Employé » par numéro ou nom, et le « Genre » : « Prêt » ou « Avance sur salaire ». Ajoutez une « Référence » si besoin.
4. Saisissez le « Capital » et la « Mensualité ». La « Devise » est la devise de paiement de l’employé.
5. Réglez « Décaissé le ». Réglez « Première retenue le », ou laissez vide pour commencer à la prochaine paie.
6. Ajoutez une « Remarque » si besoin, puis touchez « Enregistrer le prêt ».

Ouvrez un prêt pour lire son « Restant dû » et l’« Historique des écritures ». Les écritures ne sont jamais modifiées ; une erreur est contre-passée.

Pour un prêt actif :

- Touchez « Modifier », changez les champs et touchez « Enregistrer les modifications ».
- Touchez « Solder le prêt », choisissez le « Mode de solde », « Retenir en totalité sur la prochaine paie » ou « Payé hors paie », réglez la date et confirmez. Avec le premier choix, la prochaine paie retient tout le restant dû.
- Touchez « Annuler le prêt », saisissez le « Motif » et confirmez. Seul un prêt sans aucun remboursement peut être annulé ; sinon, soldez-le.

La paie prend ensuite la retenue d’elle-même. Voir « Faire la paie ».
