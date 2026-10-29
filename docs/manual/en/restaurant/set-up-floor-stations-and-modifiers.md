---
id: restaurant-set-up-floor-stations-and-modifiers
title: Set up the floor plan, stations and modifiers
module: restaurant
order: 70
permissions: [restaurant.floor.view, restaurant.floor.manage, restaurant.station.view, restaurant.station.manage, restaurant.modifier.view, restaurant.modifier.manage]
roles: [owner, admin, branch_manager]
screens: [/restaurant/floor, /restaurant/stations, /restaurant/modifiers]
---
Describe where guests sit, where food is prepared and what choices a guest can make on an item.

1. Open "Floor plan" and choose the location. Tap "Add area" and name it, for example "Terrace". Then tap "Add table".
2. For each table, type the "Name", choose the "Area", the number of "Seats" and the "Shape". Set "Left", "Top", "Width" and "Height" (0 to 99) to place it on "Plan of the area". Tap "Save table".
3. To set a table's status by hand, edit the table and choose "Status set by hand". To remove a table, tap "Archive table".
4. Open "Stations". Tap "Add station", type the "Name", choose the "Kind" (kitchen, bar or other) and the "Printer name". Choose the "Item categories" it prepares. Switch on "Default station for items without a routed category" for one station. Tap "Save station".
5. Open "Modifiers". Tap "Add modifier group", type the "Name", choose the "Currency", and set "Fewest choices" and "Most choices".
6. Under "Choices", tap "Add choice" for each option, with its "Choice name" and "Price added". Under "Items" or "Item categories", pick what the group applies to. Tap "Save modifier group".

An archived station sends its items to the default station. An archived modifier group no longer shows at the till.
