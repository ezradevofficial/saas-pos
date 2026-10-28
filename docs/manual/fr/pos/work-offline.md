---
id: pos-work-offline
title: Vendre sans connexion
module: pos
order: 130
permissions: [pos.sale.create]
roles: [cashier, branch_manager]
screens: [pos:SellScreen, pos:PaymentScreen]
---
La caisse continue à vendre quand la connexion tombe. Elle peut vendre au moins 7 jours hors ligne.

## À quoi s'attendre

1. L'état en haut passe à « Hors ligne · la vente continue ». L'écran de vente ajoute une note : les ventes sont enregistrées sur la caisse et envoyées à la reconnexion.
2. Vendez comme d'habitude. Les espèces et les codes M-Pesa saisis à la main fonctionnent hors ligne. Une demande de paiement sur le téléphone du client demande une connexion : utilisez le code de son message.
3. Chaque vente prend son numéro de ticket dans une plage que la caisse détient déjà.
4. Quand la connexion revient, la caisse envoie les ventes toute seule. L'état passe à « Synchronisation… », puis à « En ligne · tout est synchronisé ».

## Ce qui demande une connexion

- Changer un code PIN, et vérifier un code PIN que la caisse ne peut pas vérifier hors ligne.
- Les nouveaux prix, produits et clients. La caisse continue à utiliser les dernières données reçues.
- L'envoi des ventes à l'administration fiscale. Elles sont mises en file et envoyées après l'envoi par la caisse.

## Si la caisse n'a plus de numéros de ticket

La caisse indique qu'elle n'a plus de numéros de ticket. Connectez-la à Internet pour que le serveur lui en donne, puis terminez la vente.

N'effacez pas les données de l'application et ne dissociez pas la caisse tant que des ventes attendent d'être envoyées.
