# SyncStatus

Shows whether a POS device is online and how many offline sales are waiting to upload. Always visible in the POS header.

**Props the consumer provides:** `state` (`online`, `syncing`, `offline`), optional `pending` count, optional translated `labels`.

**Do:** reassure: offline means selling continues.
**Don't:** hide it, or show it only as a coloured dot.
