---
id: restaurant-bill-and-pay
title: Présenter l'addition et encaisser
module: restaurant
order: 40
permissions: [restaurant.order.close]
roles: [waiter, bartender]
screens: [pos:BillScreen]
---
Clôturez la commande, divisez-la si les clients le demandent, puis encaissez chaque part.

1. Sur la commande, touchez « Demander l’addition » quand les clients la demandent, puis « Addition et paiement ».
2. Lisez « Articles », le service et le « Total ».
3. Choisissez un « Pourboire » : « Sans pourboire », une valeur proposée ou « Autre montant ».
4. Sous « Comment payer », choisissez « Addition entière », « Par article », « Par place » ou « À parts égales ». Pour « À parts égales », réglez « Nombre de parts ». Pour « Par article », mettez chaque article dans un groupe.
5. Touchez « Encaisser <montant> » pour chaque part et prenez le paiement.
6. Quand « Commande payée » s'affiche, touchez « Retour aux tables ». La table est de nouveau libre.

Dès qu'une part est payée, la division ne peut plus changer. Si l'article de service ou de pourboire n'est pas défini, demandez à un administrateur de le définir, puis synchronisez la caisse.
