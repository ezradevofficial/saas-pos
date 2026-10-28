---
id: salon-walk-ins-and-queue
title: Accueillir les clients sans rendez-vous
module: salon
order: 20
permissions: [salon.queue.view, salon.queue.manage, salon.appointment.create]
roles: [salon_receptionist, salon_manager]
screens: [pos:QueueScreen, pos:WalkInDialog]
---
Un client sans réservation obtient un fauteuil tout de suite ou rejoint la file.

1. Sur « Rendez-vous », touchez « Nouveau client sans rendez-vous », ou ouvrez « File d’attente » depuis le menu de la caisse et touchez « Ajouter un client ».
2. Saisissez le « Nom » et, si vous le souhaitez, le « Téléphone (facultatif) ».
3. Choisissez les « Prestations ». Sous « Personnel », choisissez une personne ou « Quelqu’un de libre ».
4. Touchez « Réserver maintenant » pour prendre un créneau libre, ou « Ajouter à la file » si personne n'est libre.
5. Quand un fauteuil se libère, ouvrez « File d’attente » et touchez « Installer » à côté du premier client. Touchez « Retirer » pour un client qui part.

La file indique depuis combien de temps chaque client attend et une estimation de l'attente.
