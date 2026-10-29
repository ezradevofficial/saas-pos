---
id: custom-fields-add
title: Add a custom field
module: core
order: 510
permissions: [core.custom_field.view, core.custom_field.manage]
roles: [owner, admin]
screens: [/settings/custom-fields]
---
A custom field records one more detail on every item, customer or supplier, such as a licence number.

1. Open Settings, then "Custom fields".
2. Choose the record the field belongs to with the tabs: "Items" or "Customers and suppliers".
3. Tap "Add field".
4. Type the "Label" people will see, and check the "Key". The key is used in formulas, filters and exports. It can't change once the field exists.
5. Choose the "Type", for example "Text", "Number", "Date", "One choice" or "Formula". The type can't change later. For a choice field, add each choice with "Add choice".
6. Set "Required" if a record can't be saved without it, and "Unique" if no two records may share a value.
7. Under "Who can see it" and "Who can change it", choose which roles see and change it. None chosen means everyone who can see or edit the record.
8. Tick "Show on the point of sale" if tills should show it.
9. Tap "Save changes".
