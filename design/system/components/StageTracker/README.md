# StageTracker

Shows where a document is in its process flow, as configured in the workflow builder.

**Props the consumer provides:** `stages` (names from the tenant's flow, in order), `current`, optional `blocked` (rejected or a failed condition).

**Do:** use the tenant's own stage names; it scrolls sideways on phones.
**Don't:** use for wizard steps in forms or for tabs.
