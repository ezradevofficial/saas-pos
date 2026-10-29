---
id: fuel-record-a-delivery
title: Enregistrer une livraison de carburant
module: fuel
order: 35
permissions: [fuel.delivery.record, fuel.delivery.view]
roles: [shift_supervisor, station_manager]
screens: [/fuel/deliveries, /fuel/deliveries/:deliveryId]
---
Enregistrez un camion-citerne qui dépote dans une cuve. Le stock est passé d'après ce que les jaugeages ont mesuré, pas d'après le bon de livraison : jaugez donc la cuve avant et après.

1. Jaugez la cuve avant que le camion dépote et notez l'index en cm.
2. Après le dépotage, jaugez de nouveau la cuve et notez l'index.
3. Ouvrez « Livraisons de carburant » et touchez « Enregistrer une livraison ».
4. Choisissez la « Station » si vous travaillez sur plusieurs, puis la « Cuve ».
5. Choisissez le « Fournisseur » si vous le connaissez.
6. Saisissez le « Numéro du bon de livraison » et les « Litres du bon ».
7. Saisissez le « Jaugeage avant » et le « Jaugeage après », tous deux en cm.
8. Saisissez le « Coût au litre ». Il est dans la devise de la station, et le stock est valorisé à ce coût.
9. Touchez « Enregistrer la livraison ». Le message « Livraison FD-… enregistrée. » indique les litres reçus. Touchez « Ouvrir la livraison » pour la lire.

Les litres reçus sont le jaugeage après moins le jaugeage avant, plus les litres éventuellement vendus pendant le dépotage. S'ils sont inférieurs au bon, la livraison indique le manquant. Elle est enregistrée telle que mesurée ; réclamez le manquant au fournisseur en dehors de {{app_name}}. Une livraison ne peut plus être modifiée ensuite. Demandez à un responsable de corriger une erreur par un ajustement de stock.
