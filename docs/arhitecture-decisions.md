# Architecture decisions

This document records the architecture, API contracts, security boundaries,
and reasons behind the main implementation choices in `php-backend-solar-pi`.

## Purpose and scope

The service is designed to receive 15-minute energy aggregates from a
Raspberry Pi, store them in MySQL/MariaDB, and expose the stored measurements
through a JSON API. The current ingestion validation requires the window start
to precede the window end, but it does not enforce an exact 15-minute duration.
The response format is inspired by the ESIT API, but the current endpoint
returns measured energy in `kWh`; it does not calculate tariff prices yet.

The future price engine remains outside the current implementation. The browser
dashboard is implemented as a progressively loaded client of the existing
authenticated read API; `public/index.php` does not query MySQL directly.

## System architecture

```text
Raspberry Pi
    |
    | POST /ingest.php
    | JSON + Bearer token
    v
public/ingest.php
    |
    | validated, prepared INSERT
    v
meters 1 ------ N meter_aggregates  (MySQL/MariaDB)
    ^
    | prepared SELECT
    |
public/api-v1.php
    ^
    | GET + Bearer token
    |
    +---------------- API client
    |
public/dashboard.js
    ^
    | loaded by the public dashboard page
    |
public/index.php
```

The application uses plain procedural PHP without a framework or Composer.
Each PHP script in `public/` is an HTTP entry point. Shared behavior lives in
`src/` and is included with paths anchored by `__DIR__`.

## Web-root security boundary

`public/` is the intended document root and the only directory that should be
reachable through HTTP. Shared source code, database helpers, and `.env` live
above it so clients cannot request those files directly.

In local development this boundary is created with:

```text
php -S 127.0.0.1:8000 -t public
```

Production Apache, nginx, or Plesk configuration must set the document root to
the same `public/` directory.

## Entry points

### `public/index.php`

Renders the dashboard controls, an initially empty measurements table, and
connection information. It does not query the database or receive the API
token server-side. Client-controlled strings rendered by PHP are escaped with
`htmlspecialchars()`.

### `public/dashboard.js`

Provides the browser behavior for the dashboard. After the user enters an API
token, meter ID, and UTC date, it calls `api-v1.php` with the token in the
`Authorization` header. A manual load starts polling; successful and retryable
outcomes schedule the next request 15 seconds after the current request
finishes.

The script updates the existing table with DOM methods and `textContent`; it
does not insert API values through `innerHTML`. A `4xx` response stops polling
until the user corrects the controls and loads again. Retryable network or
server failures leave the most recent successful table visible.

### `public/ingest.php`

Receives one aggregate per authenticated POST request. Its gates run in this
order:

1. Require `POST`.
2. Authenticate the Bearer token.
3. Decode the JSON object.
4. Validate all fields.
5. Insert through a prepared statement.
6. Translate expected database constraint errors into API responses.

Cheap validation is performed first, and request bodies are parsed only after
authentication.

### `public/api-v1.php`

Returns one meter's aggregates for one UTC day. It requires GET and Bearer
authentication, validates the date, verifies that the meter exists, and reads
slots in chronological order.

The date query uses a half-open range:

```text
[start of selected UTC day, start of next UTC day)
```

This avoids gaps and double-counting at midnight boundaries.

## Configuration

`src/config.php` provides a small dependency-free configuration loader. The
lookup order is:

1. A non-empty process environment variable.
2. A non-empty value from the root `.env` file.
3. A default passed by the caller.
4. Otherwise, log the missing key and stop with HTTP 500.

The `.env` parser supports `KEY=VALUE`, comments, and matching quotes. It is
loaded once per PHP request and cached in a function-local static variable.

The application uses these keys:

- `DB_HOST`, with `127.0.0.1` as the code default
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `API_TOKEN`

Secrets must not be committed. At the time this document was created, `.env`
was tracked by Git and `.gitignore` did not contain an effective `.env` rule.
Any credentials that have been pushed or shared should be considered exposed
and rotated. Correcting Git tracking is a separate repository change.

## Authentication decision

Both reading and ingestion use the same pre-shared Bearer token:

```http
Authorization: Bearer <API_TOKEN>
```

Load data can reveal occupancy patterns, so the read endpoint is authenticated
even though the public ESIT price API that inspired its shape is not.

The helper compares the entire header with `hash_equals()`. Missing, malformed,
and incorrect credentials therefore receive the same `401` response without
revealing which part failed.

The current design has one global token. It does not provide users, per-meter
credentials, expiry, scopes, automatic rotation, or rate limiting.

The dashboard does not embed this token in PHP or JavaScript. The user enters
it into a password input for the current page, and `dashboard.js` sends it in
the `Authorization` header. It is not included in the URL or written to
`localStorage`. Because all browser JavaScript is downloadable, the public
script must never contain a token or other secret.

Some Apache/FastCGI configurations do not expose `Authorization` to PHP as
`HTTP_AUTHORIZATION`. The tracked, correctly named `public/.htaccess` forwards
the header with `SetEnvIf`, allowing the endpoint code to read it from
`$_SERVER['HTTP_AUTHORIZATION']`. This file affects Apache only; nginx does not
interpret `.htaccess` files.

## Database design

The schema uses two normalized tables. `meters` contains entities and
`meter_aggregates` contains measurement events linked to those entities by a
foreign key. The schema is the source of truth when older planning documents
describe additional aggregate fields: the implemented API and table store
`meter_id`, `window_start_utc`, `window_end_utc`, and `energy_delta_kwh`.

### `meters`

Represents physical meter identities and their descriptive metadata. It also
acts as an allowlist: measurements cannot be stored for unknown meters.

### `meter_aggregates`

Represents events: one energy measurement for a meter and time window. Energy
uses `DECIMAL(12,6)` instead of floating point so stored counters do not drift.

Separating entities from events provides:

- One authoritative location for meter metadata.
- Database-enforced referential integrity.
- A representation for registered meters that have not reported data.
- Protection against invented meter identifiers if an API token leaks.
- A place for future meter attributes without duplicating them in history.

The foreign key uses `ON UPDATE CASCADE` so a meter ID rename carries its
history, and `ON DELETE RESTRICT` so measurement history cannot be orphaned.

## Database connection layer

`src/db.php` exposes a `db(): PDO` factory. It lazily creates and reuses one
connection within each PHP request. The `db_fetch_all()` helper prepares and
executes reusable read queries, returns associative rows, logs detailed PDO
failures server-side, and throws a generic `RuntimeException` to callers.

PDO is configured with:

- `utf8mb4` for the connection charset
- exceptions for database errors
- associative-array fetches
- native server-side prepared statements

Connection errors are logged and replaced with a generic exception. This
prevents a PDO constructor stack trace from exposing connection arguments such
as the database password.

The intended database user has only `SELECT` and `INSERT` privileges because
the running application does not need schema changes or destructive operations.

## UTC and timestamp decisions

All stored timestamps follow UTC. MySQL `DATETIME` does not itself retain a
timezone, so `_utc` column names and boundary conversion enforce the convention.

Ingestion accepts ISO 8601 timestamps with an explicit offset, validates them
with a parse-and-round-trip check, converts them to UTC, and stores
`Y-m-d H:i:s`. API output converts the stored values back to ISO 8601 with an
explicit `+00:00` offset.

This keeps storage comparisons simple while preventing clients from having to
guess the timezone.

## Idempotency decision

The tuple below is the natural idempotency key:

```text
meter_id + window_start_utc + window_end_utc
```

It is enforced by the `uq_meter_window` unique constraint. Ingestion performs
the insert directly instead of checking first, avoiding a race where two
requests both observe that no row exists.

MySQL duplicate error `1062` returns `200 already recorded`. That is considered
success because the requested measurement is already persisted. A fresh insert
returns `201 created`.

An unknown meter triggers foreign-key error `1452` and returns `422`. The
service deliberately rejects unknown meters rather than auto-registering them.

## API contracts

### Ingestion

```text
POST /ingest.php
Authorization: Bearer <API_TOKEN>
Content-Type: application/json
```

```json
{
  "meter_id": "mock-meter-001",
  "window_start_utc": "2026-07-12T10:00:00+00:00",
  "window_end_utc": "2026-07-12T10:15:00+00:00",
  "energy_delta_kwh": 0.1
}
```

Responses:

- `201`: newly stored
- `200`: already recorded
- `400`: malformed JSON or invalid fields
- `401`: missing or invalid token
- `405`: wrong method, with an `Allow` header
- `422`: unknown meter
- `500`: unexpected server or database failure

### Daily meter data

```text
GET /api-v1.php?date=YYYY-MM-DD&meter_id=mock-meter-001
Authorization: Bearer <API_TOKEN>
```

The date defaults to today in UTC and `meter_id` currently defaults to
`mock-meter-001`. A successful response contains an ordered `slots` array; an
empty day is a successful `200` with an empty array.

The response intentionally includes `"unit": "kWh"` at two levels. The
top-level field describes the unit of the complete dataset, while each slot
also carries its unit so the individual value remains self-describing.

```json
{
  "meter_id": "mock-meter-001",
  "date": "2026-07-12",
  "resolution_minutes": 15,
  "unit": "kWh",
  "slots": [
    {
      "start_timestamp": "2026-07-12T10:00:00+00:00",
      "end_timestamp": "2026-07-12T10:15:00+00:00",
      "value": 0.1,
      "unit": "kWh"
    }
  ]
}
```

Responses:

- `200`: slots, possibly empty
- `400`: invalid date
- `401`: missing or invalid token
- `404`: unknown meter
- `405`: wrong method, with an `Allow` header
- `500`: unexpected server or database failure

## Error handling

`src/api-helper.php` centralizes JSON responses and method/authentication gates
so endpoint failures use consistent status codes and `Content-Type` headers.

Ingestion catches expected PDO errors and hides internal SQL details from the
client. Unexpected failures are logged and returned as generic errors.

The GET endpoint wraps meter lookup, aggregate retrieval, and outbound row
conversion in a `Throwable` boundary. PDO query failures and the generic
`RuntimeException` produced by `db()` are logged server-side and returned as a
generic JSON `500` response. Method, authentication, and request validation
remain before this boundary, so invalid callers still receive their specific
`405`, `401`, or `400` responses without attempting database work.

Production PHP should still use `display_errors=Off` and `log_errors=On` as
defense in depth.

## Deployment model

The documented production target is Plesk with TLS and `public/` configured as
the document root. Server-specific `.env` values should be installed outside
version control with restrictive file permissions.

`public/dashboard.js` is intentionally a public static asset because the
browser must download it. Files containing server-side implementation details
or secrets remain outside `public/`. Backup PHP files must also remain outside
the document root because any PHP file placed there can be requested directly.

TLS verification must remain enabled whenever credentials are transmitted.
Using curl's `-k` option with an API token defeats certificate verification and
can expose the token.

## Current limitations and future work

- The dashboard displays one meter and one UTC day at a time.
- The user must enter the shared API token after opening the page; there is no
  dashboard login or server-side session.
- Dashboard updates use 15-second polling rather than server push.
- There is no price engine or tariff-price table.
- Authentication uses one global shared token.
- There is no rate limiting or audit log.
- There is no automated test suite or Composer configuration.
- Database schema changes are managed manually rather than through migrations.
