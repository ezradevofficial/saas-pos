---
id: organisation-archive-and-restore
title: Archive and restore companies, branches and locations
module: core
order: 60
permissions: [core.company.archive, core.branch.archive, core.location.archive]
roles: [owner, admin]
screens: [/settings/organisation]
---
Records are never deleted. Archiving hides a company, branch or location from daily work and keeps its history.

## Archive

1. Open Settings, then "Organisation".
2. Tap "Archive" beside the company, branch or location.
3. Read the message, then confirm. To change your mind, tap "Keep it active".

## Restore

1. Switch on "Show archived".
2. Tap "Restore" beside the record.

## When it is refused

- You cannot archive your only active company, branch or location. Add another first.
- You cannot archive a company that still has active branches, or a branch that still has active locations. Archive the inner ones first.
- You cannot archive a location while devices are paired, waiting to pair or suspended there. Unpair them first.
- To restore a branch, its company must be active. To restore a location, its branch must be active.
