---
id: core-add-contact
title: Add a customer or supplier
module: core
order: 40
permissions: [core.party.view, core.party.create, core.party.edit, core.party.archive]
roles: [sales_rep, accountant, procurement_officer, branch_manager, owner, admin]
screens: [/contacts/customers, /contacts/customers/new, /contacts/customers/:partyId, /contacts/suppliers, /contacts/suppliers/new, /contacts/suppliers/:partyId]
---
Customers are who you sell to; suppliers are who you buy from. A name is enough to start. Add phone numbers so the till can find the customer.

1. Open "Customers" or "Suppliers" under "Contacts". Search with "Name, tax ID or phone number", or use the "Active" and "Archived" tabs.
2. Tap "Add customer" or "Add supplier".
3. Type the "Name". Choose the "Kind", "Person" or "Organisation". Add the "Legal name" and the "Tax ID" if you have them (KRA PIN in Kenya, NIF in the DR Congo).
4. Under "Roles", keep the role the page proposes. A contact can be both customer and supplier.
5. Under "Phone numbers", tap "Add phone number" and type the number. Add "Email addresses" and "Addresses" the same way.
6. Under "Terms", choose the "Currency", the "Payment terms" in days, the "Price list" and, for a customer, the "Credit limit". Leave "Credit limit" empty for no limit.
7. Tap "Create customer" or "Create supplier".

The contact opens with a "Details" tab and a "History" tab. To change it, edit the fields and tap "Save changes". If {{app_name}} finds a contact that looks the same, it shows it above the page so you can check for a duplicate.

To remove a contact from lists and from the till, open it and tap "Archive", then "Archive contact". Their history is kept. Tap "Restore" on the "Archived" tab to bring them back.

If you may not set a credit limit directly, you can only lower it here. See "Request a credit limit change".
