---
id: fuel-set-up-the-forecourt
title: Configurer la station
module: fuel
order: 60
permissions: [fuel.setup.view, fuel.setup.manage, fuel.settings.view, fuel.settings.edit]
roles: [owner, station_manager]
screens: [/fuel/setup, /fuel/tanks/:tankId]
---
Décrivez une fois vos stations, produits carburant, cuves, pompes et pistolets, puis réglez les tolérances. Seul le Propriétaire peut modifier les tolérances.

1. Ouvrez « Configuration de la station ». Elle comporte les onglets « Stations », « Produits carburant », « Cuves », « Pompes et pistolets » et « Paramètres ».
2. Dans « Stations », touchez « Nouvelle station », choisissez l'emplacement qui est une station-service et la devise de ses prix, puis touchez « Créer la station ». Ne changez la devise qu'avant le premier prix ou service.
3. Dans « Produits carburant », touchez « Nouveau produit carburant », choisissez un article en stock dont l'unité de base est le litre, le « Type de carburant » et, si vous voulez, la « Qualité ». Touchez « Créer le produit carburant ».
4. Dans « Cuves », touchez « Nouvelle cuve », choisissez la station et le produit, saisissez le « Code » et la « Capacité », puis touchez « Créer la cuve ».
5. Dans « Pompes et pistolets », touchez « Nouvelle pompe », puis « Créer la pompe ». Sur la pompe, touchez « Ajouter un pistolet », choisissez la cuve où il puise et touchez « Créer le pistolet ».
6. Dans « Paramètres », saisissez les tolérances que votre société accepte, puis touchez « Enregistrer les tolérances ». Elles commencent toutes à zéro : chaque écart demande donc une approbation tant que vous ne les réglez pas.

## Donner un barème à une cuve

Un jaugeage en cm ne devient des litres que par le barème de la cuve.

1. Dans « Cuves », ouvrez la cuve.
2. Sous « Barèmes de jaugeage », touchez « Ajouter un barème ».
3. Remplissez les « Lignes du barème » d'après le tableau de la cuve : un jaugeage et ses litres sur chaque ligne. Touchez « Ajouter une ligne » pour en ajouter. Les deux colonnes doivent monter d'une ligne à l'autre.
4. Laissez « En vigueur à partir du » vide pour commencer maintenant, puis touchez « Enregistrer le barème ».
5. Pour l'essayer, saisissez un jaugeage sous « Du jaugeage aux litres » et touchez « Convertir ». Rien n'est enregistré.

Un barème ne se modifie pas : ajoutez-en un nouveau. Une station, un produit, une cuve, une pompe ou un pistolet archivé reste dans les registres ; archivez les pistolets d'une cuve avant la cuve, et fermez le service avant d'archiver une station ou un pistolet.
