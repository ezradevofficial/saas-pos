---
id: fiscal-set-up
title: Connect a company to the tax authority
module: core
order: 790
permissions: [core.fiscal.view, core.fiscal.edit, core.fiscal.configure]
roles: [owner, admin]
screens: [/settings/fiscal]
---
Once switched on, every sale, refund and void of the company is sent to KRA eTIMS or the DRC DGI. The authority's answer is kept with each document.

Warning: this sends real tax documents to the authority, and they can't be unsent. Enter the registration exactly as the authority registered the business, and test on the authority's test environment before you switch sending on. Only an Owner or Admin can change the registration and the credentials. Never share the access token or communication key.

1. Open Finance, then "Tax authority".
2. Choose the company in the company switcher.
3. Under "Settings", choose the "Authority" for the company's country.
4. Fill in the registration: "Taxpayer PIN", "Branch ID at the authority" and "Device serial". Changing any of these needs the device to be initialised again.
5. Enter the credentials. They are stored encrypted and never shown again.
6. Under "Default codes", set the default item classification, packaging unit and quantity unit codes used for items with no codes of their own.
7. Tap "Save settings". Until the settings are saved, "Initialise device" and the sending switch are not available.
8. Tap "Initialise device", at the top right of the card. The page confirms when the authority initialised the device.
9. Switch on "Send documents to the authority".

The page lists what is still needed until the settings are complete. Follow documents after sending with "Follow documents sent to the tax authority".
