---
id: van-count-the-van
title: Compter la camionnette en fin de journée
module: van_sales
order: 30
permissions: [van_sales.reconciliation.submit]
roles: [van_rep]
screens: [pos:CountScreen]
---
Clôturez la journée en comptant le stock, les espèces et le mobile money.

1. Sur « Journée de tournée », touchez « Comptage de fin de journée ».
2. Sous « Stock du camion », saisissez « Retourné (bon état) » et « Endommagé » pour chaque article. La ligne affiche chargement = vendu + retourné + endommagé + écart. « Équilibré » s'affiche quand le compte est juste.
3. Sous « Espèces et mobile money », saisissez le montant « Compté » pour chaque devise et type. Comparez avec « Attendu ».
4. Touchez « Envoyer le comptage ».

Si le comptage est équilibré, la journée est clôturée. S'il a un écart, il attend l'approbation du superviseur ; s'il est rejeté, comptez à nouveau le camion et envoyez un nouveau comptage. Sans connexion, le comptage est enregistré et envoyé plus tard.
