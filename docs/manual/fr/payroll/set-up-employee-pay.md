---
id: payroll-set-up-employee-pay
title: Paramétrer la paie d’un employé
module: payroll
order: 5
permissions: [payroll.employee.view, payroll.employee.manage]
roles: [payroll_officer, owner]
screens: [/payroll/employees, /payroll/employees/:employeeId]
---
Avant qu’une paie puisse verser quoi que ce soit à quelqu’un, il lui faut un groupe de paie. Le salaire de base vient du contrat dans les RH et n’est jamais ressaisi dans la paie.

1. Ouvrez « Paramétrage » sous « Paie ». La liste montre pour chaque employé le « Groupe de paie », la « Devise », le « Mode de paiement » et le « Statut ».
2. Si un employé manque, touchez « Synchroniser depuis les RH » suivi du nom de la société. Les employés viennent des RH.
3. Cherchez avec « Rechercher des employés ». Utilisez « Groupe de paie attribué » et choisissez « Pas encore de groupe de paie » pour trouver ceux qui sont exclus des paies.
4. Touchez un employé. « Contrat en vigueur » montre la rémunération issue des RH. S’il indique « Aucun contrat dans les RH pour l’instant », ajoutez le contrat dans les RH : les paies ne versent rien d’ici là.
5. Sous « Paramétrage de la paie », choisissez le « Groupe de paie ». Les employés sans groupe de paie sont exclus des paies.
6. Gardez la « Devise de paiement » du contrat, sauf si la paie est versée dans une autre devise. Choisissez le « Mode de paiement ». Saisissez les « Personnes à charge » et les « Enfants » en nombres entiers de 0 à 50.
7. Activez « Suspendre la paie (exclure des paies) » pour exclure la personne jusqu’à la levée de la suspension.
8. Touchez « Enregistrer le paramétrage ».

Pour verser une indemnité ou prélever une retenue, utilisez « Rubriques de paie » sur la même page :

1. Touchez « Ajouter une rubrique », choisissez la « Rubrique de paie » et le « Montant » avec sa « Devise ».
2. Choisissez le « Versement » : « Chaque période », à partir de la date « Du » et jusqu’à la date « Jusqu’au » (laissez « Jusqu’au » vide pour continuer à la verser), ou « Une fois », dans la période qui contient « Une date de la période ».
3. Ajoutez une « Remarque » si besoin, puis touchez « Enregistrer la rubrique ».

Touchez « Modifier » pour changer une rubrique ou « Archiver » pour l’arrêter. Les paies déjà comptabilisées la conservent. Si les montants sont masqués, votre rôle ne peut peut-être pas voir la rémunération.
