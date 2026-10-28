---
id: fuel-open-and-close-shift
title: Ouvrir et clôturer un poste à la piste
module: fuel
order: 10
permissions: [fuel.shift.open, fuel.shift.close]
roles: [forecourt_attendant, shift_supervisor]
screens: [pos:FuelHome, pos:OpenFuelShift, pos:CloseFuelShift]
---
Un poste commence par les index d'ouverture des compteurs et se termine par les index de fermeture et ce que remet chaque pompiste.

1. Ouvrez le menu de la caisse et choisissez « Piste ». Si aucun poste n'est ouvert, touchez « Ouvrir le poste ».
2. Marquez chaque pistolet « Utilisé ». Saisissez son « Index d’ouverture du compteur » et choisissez le « Pompiste » qui le tient.
3. Chaque pompiste confirme avec son propre PIN : touchez « Confirmer <nom> », saisissez le PIN et touchez de nouveau « Confirmer <nom> ».
4. Ajoutez les « Jaugeages d’ouverture (facultatif) » si vous avez mesuré les cuves. Touchez « Ouvrir le poste ».
5. À la fin, touchez « Clôturer le poste ». Saisissez l'« Index de fermeture du compteur » de chaque pistolet, et les « Litres d’essai de pompe » si vous en avez reversé.
6. Sous « Remis par chaque pompiste », saisissez les « Espèces comptées ». Zéro est permis. Les autres modes partent de ce que la caisse a enregistré.
7. Ajoutez une « Note pour le responsable (facultatif) » et touchez « Clôturer le poste ». Sur « Poste clôturé », touchez « Retour à la piste ».

Un index inférieur au précédent est refusé : vérifiez-le, ou demandez une remise à zéro de compteur à un chef de poste. L'écart affiché n'est qu'un aperçu ; le serveur recompte et décide.
