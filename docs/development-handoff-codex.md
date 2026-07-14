# Development Handoff — Codex

## How to work with the user

The user wants to implement application code personally. For every development
step:

1. Present only one small, reviewable step.
2. State the exact file and insertion or replacement location.
3. Show the complete code needed for that step before it is implemented.
4. Include useful comments explaining decisions and non-obvious behavior.
5. Explain why the approach fits this repository.
6. Give exact test commands and expected results.
7. Wait for the user's result and questions before continuing.

Do not modify application files unless the user explicitly asks. Documentation
may be changed only when explicitly requested. Do not suggest removing `.env`
from Git for now: the user intentionally keeps it tracked for easier local
development. Never print its secret values.

## Current implementation

- Plain PHP 8 application with MySQL/MariaDB and no Composer dependencies.
- `public/ingest.php` validates and stores authenticated energy aggregates.
- `public/api-v1.php` reads database-backed daily energy slots.
- Both endpoints use the shared Bearer token from configuration.
- `src/db.php` provides the shared PDO connection.
- `public/.htaccess` is correctly named and forwards `Authorization` for
  Apache/FastCGI.
- The API intentionally returns `"unit": "kWh"` both at response level and
  inside each slot.
- `public/index.php` is still an informational page rather than a real meter
  dashboard.

## Current development task

Build the database-backed dashboard in `public/index.php` incrementally.

### Pending step 1: load registered meters

The user has been instructed to add this immediately after
`declare(strict_types=1);`:

```php
require_once __DIR__ . '/../src/db.php';

/*
 * Load every registered meter.
 *
 * Begin with a simple query before joining measurement data. This confirms
 * that the dashboard can access the database and provides its base records.
 */
$sql = '
    SELECT
        meter_id,
        name,
        description,
        created_at_utc
    FROM meters
    ORDER BY meter_id
';

$meters = db()->query($sql)->fetchAll();
```

After the existing `e()` function and before `?>`, the user should temporarily
add:

```php
/* Temporary development output; replace it with the table in a later step. */
echo '<pre>';
echo e(print_r($meters, true));
echo '</pre>';
```

Test with:

```powershell
php -S 127.0.0.1:8000 -t public
```

Then open `http://127.0.0.1:8000/`. Expected: the page displays the registered
meter array, including `mock-meter-001`. This step is pending; do not assume it
has been implemented until the user confirms the result.

## Planned dashboard sequence

After step 1 succeeds:

1. Replace the simple query with a `LEFT JOIN` that retrieves the latest
   aggregate while retaining registered meters with no readings.
2. Replace temporary debug output with an escaped HTML table.
3. Add clear “no readings” presentation for silent meters.
4. Add controlled database-error handling without exposing SQL or credentials.
5. Add an optional 15-second page refresh.
6. Test meters with readings, meters without readings, output escaping, and
   database failure behavior.

Do not present all implementation code at once. Continue one confirmed step at
a time.
