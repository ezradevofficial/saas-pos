---
id: salon-walk-ins-and-queue
title: Welcome walk-in clients
module: salon
order: 20
permissions: [salon.queue.view, salon.queue.manage, salon.appointment.create]
roles: [salon_receptionist, salon_manager]
screens: [pos:QueueScreen, pos:WalkInDialog]
---
Clients without a booking either get a chair now or join the queue.

1. On "Appointments", tap "New walk-in", or open "Walk-in queue" from the till menu and tap "Add a walk-in".
2. Type the "Name" and, if you wish, the "Phone (optional)".
3. Choose the "Services". Under "Staff", pick a person or "Anyone free".
4. Tap "Book now" to take a free slot, or "Add to queue" if nobody is free.
5. When a chair frees up, open "Walk-in queue" and tap "Seat" next to the first client. Tap "Remove" for a client who leaves.

The queue shows how long each client has waited and an estimate of the wait.
