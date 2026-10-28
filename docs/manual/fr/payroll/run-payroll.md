---
id: payroll-run-payroll
title: Faire la paie
module: payroll
order: 10
permissions: [payroll.run.view, payroll.run.prepare, payroll.run.post, payroll.payment.export]
roles: [payroll_officer]
screens: [/payroll/runs, /payroll/runs/:runId]
---
Les valeurs légales, comme les barèmes d’impôt, les taux de cotisation et les prélèvements, viennent du pack pays et ne sont jamais devinées. Si l’une manque, la paie est refusée et la page liste ce qui manque ; l’équipe de la plateforme le confirme dans le pack.

1. Ouvrez « Paies » sous « Paie » et touchez « Nouvelle paie ».
2. Choisissez la « Société » et le « Groupe de paie ». Choisissez « Une date de la période » et, si besoin, la « Date de paiement ».
3. Touchez « Créer la paie », puis « Calculer ».
4. Lisez les totaux, la comparaison avec la dernière paie comptabilisée et les « Lignes ». Corrigez toute ligne en erreur, puis touchez « Recalculer ». Les erreurs bloquent l’étape suivante.
5. Touchez « Soumettre pour approbation ». Les approbateurs décident depuis leur boîte d’approbations. La personne qui a préparé ou soumis la paie ne peut pas l’approuver.
6. Quand la paie est approuvée, touchez « Comptabiliser la paie » et confirmez. Cela verrouille la paie et émet les bulletins.
7. Pour payer, choisissez le « Modèle de fichier » et touchez « Télécharger le fichier de paiement ».

Une paie comptabilisée n’est jamais modifiée. Pour corriger une erreur, touchez « Rectifier la paie » et créez une paie de correction. Pour abandonner une paie non comptabilisée, touchez « Annuler la paie ».
