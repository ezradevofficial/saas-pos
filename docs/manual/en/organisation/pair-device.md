---
id: organisation-pair-device
title: Add and pair a till
module: core
order: 70
permissions: [core.device.view, core.device.create, core.device.pair]
roles: [owner, admin]
screens: [/settings/organisation, pos:PairingScreen]
---
A till is a device paired to one location with a one-time code.

## In the back office

1. Open Settings, then "Organisation".
2. On the location, tap "Devices".
3. Tap "Add device".
4. Type the "Device name" that people recognise, such as Till 1.
5. Tap "Add and get pairing code".
6. Write down the "Pairing code". You will not see it again. It works once and expires after 15 minutes. If it expires, tap "Get pairing code" for a new one.

To set the device's "Device code" (up to 10 capital letters or digits, such as T01, printed in receipt numbers), tap "Edit" beside it.

## On the till

1. Open the {{app_name}} app. It shows "Pair this till".
2. Type the "Pairing code". It has 8 letters and digits.
3. Type the "Till name".
4. Tap "Pair till".

The till then shows which location it sells for. Staff can sign in once it has synced.
