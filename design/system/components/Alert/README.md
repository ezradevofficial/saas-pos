# Alert

A banner that explains something about the current page: a limit, a failure, a confirmation.

**Props the consumer provides:** `tone` (`info`, `success`, `warning`, `danger`), `title`, `children` (what to do next), optional `action` (one Button).

**Do:** say what happened and what to do; one alert per page section.
**Don't:** use for field validation (use TextField `error`); stack several alerts.
