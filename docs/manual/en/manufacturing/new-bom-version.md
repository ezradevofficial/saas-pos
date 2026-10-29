---
id: mfg-new-bom-version
title: Change a BOM with a new version
module: manufacturing
order: 2
permissions: [manufacturing.bom.view, manufacturing.bom.manage]
roles: [admin, owner]
screens: [/manufacturing/boms, /manufacturing/boms/:bomId, /manufacturing/bom-versions/:versionId]
---
A published version never changes. To change a BOM, make a new version and publish it. New orders and sales then use it from its date; orders already planned keep their version.

1. Open "BOMs and recipes" under "Manufacturing" and open the BOM.
2. Under "Versions", read the version "In force" and the date it applies from.
3. Tap "New version". Choose "Copy from" a version, or "Start empty", and tap "Create version".
4. Tap "Open the draft". Change the lines, the "One batch makes" quantity or the "Labour minutes per batch". Use the arrows to move a line up or down and "Remove" to drop one.
5. Tap "Save draft". Save before publishing: the page asks you to.
6. Tap "Publish". Choose "In force from". It must be later than the last published version’s date; empty means now. Confirm.

To stop using a BOM, tap "Archive" on its page and confirm. Orders already planned keep their version, and a new BOM of the item can be made afterwards. An archived BOM takes no new version.
