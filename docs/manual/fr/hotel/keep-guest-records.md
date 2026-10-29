---
id: hotel-keep-guest-records
title: Tenir les fiches des clients
module: hotel
order: 25
permissions: [hotel.guest.view, hotel.guest.manage, hotel.guest.identity]
roles: [receptionist]
screens: [/hotel/guests, /hotel/guests/:guestId]
---
Les fiches des clients contiennent des données personnelles. Les numéros de pièce d'identité sont masqués tant qu'une personne autorisée ne les affiche pas.

1. Ouvrez « Clients ». Cherchez par nom, téléphone ou e-mail.
2. Ouvrez un client pour lire les « Détails » et voir ses séjours avec « Voir les séjours de ce client ».
3. Pour modifier une fiche, touchez « Modifier le client ». Mettez à jour le « Téléphone », l'« E-mail », la « Nationalité », le « Type de pièce d'identité », le « Numéro de pièce d'identité ou de passeport », la « Date de naissance », l'« Adresse » ou les « Notes », puis touchez « Enregistrer le client ». Laissez le numéro vide pour conserver celui qui est enregistré.
4. Pour lire un numéro de pièce en entier, touchez « Afficher ». L'affichage est consigné dans le journal d'audit. Touchez « Masquer » ensuite.
