---
id: restaurant-send-to-kitchen
title: Take an order and send it to the kitchen
module: restaurant
order: 20
permissions: [restaurant.order.edit, restaurant.order.send, restaurant.ticket.serve]
roles: [waiter, bartender]
screens: [pos:OrderScreen]
---
Add what the guests ask for, then send it to the kitchen or the bar.

1. Choose a category, then tap an item in "Menu" to add it. If the item has choices, pick them and tap "Add to order".
2. To change a line, tap it. You can set "Quantity", "Course", "Seat" and "Note for the kitchen", or tap "Change options". Tap "Save line".
3. Tap "Send <n> items to the kitchen". The items go to their station in a new round.
4. When you are ready for a later course, tap "Fire course <n>" to send the items held for it.
5. Under "Kitchen and bar tickets", tap "Mark served" when the station's food reaches the table.

To send a round again, tap "Re-send", pick the round, and the kitchen gets a new ticket marked re-send. If a ticket cannot print, "No kitchen printer is connected": the ticket is on the kitchen display.
