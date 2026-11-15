# Architecture decisions

This document records the architecture, API contracts, security boundaries, and
reasons behind the main implementation choices in `php-backend-solar-pi`.

It describes the code as it actually is. Where an older planning document
disagrees (`backend_php_project_plan.md` in particular), this file and
`db/schema.sql` are the source of truth.

## Purpose and scope

The service receives energy readings from a Raspberry Pi, stores them in
MySQL/MariaDB, and exposes them through a JSON API that feeds two browser
views: a measurements table and an interactive chart.

A reading is a **counter snapshot**, not a pre-aggregated window. The Pi sends
the meter's current total, its previous total, and the difference between them,
each with the timestamp of the reading. The backend does not enforce a
15-minute cadence; it stores whatever instants the Pi reports and derives
intervals from consecutive rows at read time.

There is no price engine and no tariff table. The response format was inspired
by the ESIT API's shape, but every value the service returns is measured energy
in `kWh`.

## System architecture

```text
Raspberry Pi
    |
    | POST /ingest.php          JSON + Bearer API_TOKEN_INGEST
    v
public/ingest.php
    |
    | validated, prepared INSERT
    v
devices 1--N meters 1--N meter_readings          (MySQL/MariaDB)
                  ^              ^
                  |  prepared SELECT via src/meter-readings.php
                  |              |
       public/v1/list-meters.php  public/v1/get-meter-data.php
                  ^              ^
                  |              |
                  |   Bearer API_TOKEN_GET_USAGE ....... API client / dashboard.js
                  |              |
                  +-- session cookie ................... graph.js
                                                            ^
                                                            |
                                          public/graph.php (require_login)
                                                            ^
                                                            |
                                          public/index.php (login form)
```

The application is plain procedural PHP: no framework, no Composer, no
autoloader. Each PHP file in `public/` is an HTTP entry point — a script that
executes top to bottom when requested. Files in `src/` are libraries: they
define functions and execute nothing. Entry points include them with
`require_once` and `__DIR__`-anchored paths.

A consequence worth stating plainly: two entry points can never share code with
each other directly. Anything used by more than one endpoint has to move into
`src/`.

## Web-root security boundary

`public/` is the document root and the only directory reachable over HTTP.
`src/`, `.env`, and `db/` sit above it, so a browser cannot request them even by
guessing the path.

Locally this boundary comes from the `-t` flag:

```text
php -S 127.0.0.1:8000 -t public
```

Production Apache, nginx, or Plesk must point the document root at the same
`public/` directory. Backup copies of PHP files must not be left inside it: any
`.php` file placed there can be requested directly.

## Entry points

### `public/index.php`

Landing page. Three responsibilities in one file:

1. **Sign out** — `?logout=1` destroys the session and redirects (303).
   A state-changing GET is not ideal in principle, but the worst case for
   logout is a harmless inconvenience, and it saves a whole file.
2. **Sign in** — a POST of `password` back to the same URL, rate-limited, then
   `password_verify()` against `DASHBOARD_PASSWORD_HASH`, then a 303 redirect.
   POST → redirect → GET means a page refresh does not re-submit the password.
3. **Render** — the login form or the signed-in links, the token dashboard
   controls, connection information, and an API table.

The page never queries `meter_readings` and never sees an API token
server-side. Every client-controlled string it prints (the user agent above
all) goes through `htmlspecialchars()` via the local `e()` helper.

### `public/dashboard.js`

The table dashboard. The user types the API token into a password input; the
script sends it in the `Authorization` header to `/v1/get-meter-data.php`. The
token is never placed in the URL and never written to `localStorage`.

The first load is manual. After that, each completed request schedules the next
one 15 seconds later with `setTimeout` — not `setInterval`, so a slow network
cannot stack overlapping requests. A `4xx` stops polling until the user corrects
the controls; a network or `5xx` failure leaves the last good table on screen.

Rows are built with `createElement` and `textContent`, never `innerHTML`.

### `public/graph.php`

The chart page. Server-side it does almost nothing: `require_login()`, then emit
HTML. All data work happens in `graph.js` against the JSON API, so the page
itself never touches the database.

It defines the colour tokens as CSS custom properties in one `:root` block, with
a `prefers-color-scheme: dark` override whose values are *selected* for the dark
surface rather than auto-flipped. `graph.js` reads those tokens with
`getComputedStyle`, so the palette has exactly one definition.

### `public/graph.js`

**Two charts, stacked, sharing one time axis.** Power per phase in watts on
top; the cumulative import and export counters in kWh below.

Two charts rather than two y-axes on one chart. Watts are a *rate* and kWh an
*accumulation*; plotting them together on independently scaled axes lets the
chart invent a correlation the data does not contain. Separate plots with a
shared x-axis and linked zoom is the honest form of the same comparison.
`echarts.connect()` ties them together, so dragging either slider moves both.

The one real asymmetry between the two is the y-axis:

- **Power uses the default**, which includes zero. That is correct here: 0 W is
  a meaningful baseline, and a negative value means the phase is exporting, so
  the zero line carries information.
- **Energy sets `scale: true`.** A lifetime counter sitting at 1000.2 → 1000.6
  would otherwise render as a dead-flat stroke against a 0–1000 axis, with all
  its variation squashed invisible. `scale: true` fits the axis to the data.

That single setting is the difference between a readable energy chart and one
that looks broken, and it is the first thing to check if the lines appear flat.

An earlier version drew bars for per-interval energy with a cumulative line
over them. Both went when the backend stopped deriving anything: bars
represented intervals, and there are none.

Supporting decisions:

- `useUTC: true`, so axis labels agree with the API and the database instead of
  silently shifting to the viewer's timezone.
- A legend is always present. With more than one series, identity must never
  rest on colour alone.
- No line smoothing: energy accumulates in steps, and a curve would draw values
  between readings that were never measured.
- `sampling: 'lttb'` thins the drawn points at wide ranges while keeping the
  shape. It affects rendering only, never the data.
- `dataZoom` in both `inside` (wheel/pinch) and `slider` form. The slider also
  shows *where* in the whole range the current view sits, which a wheel alone
  does not.
- The full option object is set once. Later refreshes send only series data, so
  `setOption`'s merge leaves the user's zoom position alone.
- Chart chrome wears text colours, never series colours.

Refresh is the same 15-second `setTimeout` pattern as the dashboard, plus a
`visibilitychange` listener: a background tab skips its request, and returning
to the tab refreshes immediately instead of waiting out the interval.

### `public/ingest.php`

One reading per authenticated POST. Gates, in order:

1. `require_method('POST')` — the cheapest check, so wrong verbs cost nothing.
2. `require_rate_limit('ingest', …)`.
3. `require_bearer_token(…, config('API_TOKEN_INGEST'))`.
4. `read_json_body(16_384)` — body parsing happens only for authenticated
   callers, and only up to 16 KiB.
5. Field validation, collecting **all** problems before answering. A client
   fixing its payload wants the full list, not one complaint per round trip.
6. A registered-`(device_id, meter_id)`-pair check → `422`.
7. `INSERT`, then translation of the database's verdicts.

### `public/v1/get-meter-data.php`

One meter's readings over a UTC date range. Gates: method → rate limit → dual
authentication → range parsing → `meter_id` → meter existence → query.

`?date=` is shorthand for `from`+`to`; all three omitted means today in UTC.
`to` names an inclusive day, converted internally to a half-open upper bound.
A 31-day cap is a guard, not a preference: an unbounded range would pull the
whole history into memory and across the wire.

An unknown meter is `404` here, while ingestion answers `422` for the same
situation. The verbs differ: ingestion says "please store this" — understood
but unprocessable; a read says "show me X" and there is no X.

### `public/v1/list-meters.php`

Every registered meter, so a client can discover meters instead of having them
typed in. Same gate order and same dual authentication as above, but its own
rate-limit bucket name so a page load does not spend the chart's polling
allowance.

## Configuration

`src/config.php` is a dependency-free loader. Lookup order:

1. A non-empty process environment variable.
2. A non-empty value from the root `.env`.
3. A default passed by the caller.
4. Otherwise: log the missing key, `500`, stop.

The `.env` parser handles `KEY=VALUE`, `#` comments, and matching quotes. It is
read once per request and cached in a function-local `static`.

`config_positive_int()` wraps `config()` for numeric settings. It validates with
`FILTER_VALIDATE_INT` and a `min_range` of 1, because a plain `(int)` cast turns
`"abc"` into `0` and would silently disable a rate limit. It logs only the key,
never the value.

Keys in use:

| Key | Purpose |
|---|---|
| `DB_HOST` | database host (code default `127.0.0.1`) |
| `DB_NAME`, `DB_USER`, `DB_PASS` | database connection |
| `API_TOKEN_INGEST` | write side: `ingest.php` |
| `API_TOKEN_GET_USAGE` | read side: both `v1/` endpoints |
| `DASHBOARD_PASSWORD_HASH` | `password_hash()` output for the browser login |
| `RATE_LIMIT_API_MAX_REQUESTS`, `RATE_LIMIT_WINDOW_SECONDS_API` | read endpoints (defaults 60 / 60) |
| `RATE_LIMIT_INGEST_MAX_REQUESTS`, `RATE_LIMIT_WINDOW_SECONDS_INGEST` | ingestion (defaults 20 / 60) |
| `RATE_LIMIT_LOGIN_MAX_REQUESTS`, `RATE_LIMIT_WINDOW_SECONDS_LOGIN` | login form (defaults 5 / 60) |

At the time of writing, `.env` is still tracked by Git and `.gitignore` contains
a comment about secrets but no `.env` rule. Any credential that has been pushed
should be considered exposed and rotated. See "Known issues".

## Authentication decisions

### Two tokens, not one

Writing readings and reading them are different privileges, so they use
different credentials. A leaked read token cannot forge measurements; a leaked
Pi token cannot be used to harvest history.

`require_bearer_token()` compares the **entire** `Authorization` header with
`hash_equals()`. Comparing the whole header means a missing header arrives as
`''` and fails the same comparison — one condition covers absent, malformed, and
wrong, and all three get the identical `401`. `hash_equals()` rather than `===`
because a normal comparison stops at the first wrong byte, which leaks the
token to anyone with a stopwatch.

### Sessions for humans, tokens for machines

Energy data reveals occupancy patterns, so reads are authenticated even though
the public API that inspired their shape is not. But a browser should not have
to hold an API token: `src/session.php` provides a password login with a session
cookie set `HttpOnly` (JavaScript cannot read it, so an XSS bug cannot steal
it), `SameSite=Lax` (free CSRF defence), and `Secure` whenever the request is
over HTTPS — detected both via `$_SERVER['HTTPS']` and the forwarded port,
because Plesk's nginx proxy reports it either way.

`log_in()` calls `session_regenerate_id(true)`: an attacker who planted a known
session ID before login is left holding a worthless one.

### One endpoint, two schemes

Both read endpoints accept either credential:

```php
if (!(isset($_COOKIE[session_name()]) && is_logged_in())) {
    require_bearer_token($_SERVER['HTTP_AUTHORIZATION'] ?? '', config('API_TOKEN_GET_USAGE'));
}
```

Same data, same rules, two ways of proving who you are — so one endpoint, not
two. The session is consulted only when a cookie was actually presented;
otherwise every Pi request would start a session and litter the server with
junk session files.

The gate that protects HTML differs from the gate that protects JSON:
`require_login()` sends a human a `303` to the login page, while the API answers
`401`. Same rule, different audience — JavaScript can branch on a status code, a
person needs a page.

### Header forwarding

Some Apache/FastCGI configurations strip `Authorization` before PHP sees it.
`public/.htaccess` re-injects it with `SetEnvIf` so endpoints can read
`$_SERVER['HTTP_AUTHORIZATION']`. This affects Apache only; nginx ignores
`.htaccess`.

## Rate limiting

`src/rate-limit.php` implements a fixed-window limiter keyed on
`sha256(endpointName . "\0" . clientAddress)`. Counters are JSON files under the
OS temporary directory — outside `public/`, and requiring no database query to
decide whether a request may proceed.

Decisions worth naming:

- **Per endpoint name.** Each endpoint gets an independent allowance, so
  dashboard polling cannot exhaust the ingestion budget.
- **`REMOTE_ADDR`, not `X-Forwarded-For`.** A forwarded header is client-supplied
  and trivially spoofed unless a known, trusted proxy overwrites it.
- **Before authentication.** Failed token guesses must consume the allowance,
  or the limiter does not protect the token.
- **`flock(LOCK_EX)` around read-modify-write.** Without it two simultaneous
  requests can both read the same old count.
- **Fail open.** If the directory or file cannot be opened, the limiter logs and
  returns rather than rejecting traffic. Availability is preferred over
  enforcement here; the log records that protection was reduced.
- Denied requests do not increment the counter, and `429` carries `Retry-After`.

## Database design

Three tables: `devices` → `meters` → `meter_readings`.

Every table pins `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci` explicitly rather than inheriting the database
default. This is a deployment lesson: the Plesk server's default was `utf8mb3`,
the broken 3-byte subset, and the tables silently inherited it. Converting after
the fact trips over the foreign key (error 1833), so the tables had to be
dropped and rebuilt. Defaults are hidden dependencies.

### `devices`

The gateway — one Raspberry Pi. Metadata plus timestamps.

### `meters`

Physical meter identities, each owned by a device. `meter_id` is the primary key
and globally unique, but the table also carries
`UNIQUE KEY uq_meters_device_meter (device_id, meter_id)` so that
`meter_readings` can reference the *ownership pair* as one unit.

`meters` is also the allowlist: readings cannot be stored for an unregistered
meter, which is what stops a leaked ingest token from inventing meter IDs.

### `meter_readings`

One row per reading: `pi_time_utc`, plus `import_kwh` and `export_kwh` as
`DECIMAL(12,6)`, and `power_l1_w` / `power_l2_w` / `power_l3_w` as
`DECIMAL(10,3)`.

The two energy columns are **raw cumulative counters**, stored exactly as the
meter's registers report them. Nothing derived is ever written: a raw counter
can always be re-derived into intervals later, including after a bug is fixed,
while a stored delta cannot be un-mixed back into a counter if the arithmetic
was ever wrong.

The power columns are a different kind of quantity — a rate rather than an
accumulation — so they are never summed and never differenced against the
previous row. Averaging is the only honest aggregation for them.

Decimal rather than float because
these are counters, and float rounding drift accumulates.

The composite foreign key on `(device_id, meter_id)` prevents a registered
gateway from submitting readings for another gateway's meter — a plain
`meter_id` reference could not express that.

`ON UPDATE CASCADE` so renaming a meter carries its history along;
`ON DELETE RESTRICT` so measurement history can never be orphaned or silently
destroyed.

Separating entities from events buys: one authoritative place for meter
metadata, database-enforced referential integrity, a representation for
registered meters that have not yet reported, and somewhere to put future meter
attributes without duplicating them across history.

## Database connection layer

`src/db.php` exposes `db(): PDO`, which lazily creates and reuses one connection
per request, and `db_fetch_all()` for prepared reads.

PDO options: `utf8mb4` on the connection, exceptions for errors, associative
fetches, and `EMULATE_PREPARES => false` so the server does the preparing. That
last one is what makes a placeholder a genuine value rather than client-side
string interpolation.

A connection failure is logged and re-thrown as a **fresh** `RuntimeException`,
deliberately without chaining the original. A `PDOException`'s stack trace
contains `PDO::__construct`'s arguments — including the database password — so
even with `display_errors=On` the credential cannot leak. `db_fetch_all()`
applies the same pattern to query failures.

The application's database user needs only `SELECT` and `INSERT`. The running
service performs no schema changes and no destructive operations.

## UTC and timestamp decisions

Everything stored is UTC. MySQL `DATETIME` carries no timezone, so the
convention is enforced at the boundaries and documented by the `_utc` column
suffix.

Inbound: `parse_utc_datetime()` accepts any ISO 8601 form with an explicit
offset (including `Z`), validates it with a parse-and-format-back round trip,
converts to UTC, and returns `Y-m-d H:i:s`.

Outbound: `format_utc_atom()` reverses it, re-attaching `+00:00` so clients
never have to guess.

The round trip is not decoration. `DateTimeImmutable::createFromFormat()`
happily accepts `2026-02-31` and rolls it silently to March 3rd. Parsing,
formatting back, and demanding equality is what rejects it. `parse_utc_date()`
applies the same trick to `YYYY-MM-DD`, with `!` in the format string zeroing
every unmentioned field so the result is exactly midnight UTC.

## Reads return raw rows

`fetch_meter_readings()` returns one entry per stored row. Nothing is derived
anywhere in the backend.

An earlier design returned *intervals*: each entry spanned
`[previous reading, this reading)` and carried the energy measured across that
span. That required a second query per request — a lookback for the single
reading before `range_start`, because the first interval's start lies outside
the requested range — and it forced a decision about what a decreasing counter
meant after a meter swap.

Dropping it removed both. One query instead of two, no fence-post problem, and
a counter reset simply shows as a visible step in the line rather than being
reported as a gap. The derivation can always be added back on top of the raw
counters if it is ever wanted; the reverse is not true.

The read loop still does two things, and they are boundary conversions rather
than logic: `format_utc_atom()` re-attaches the `+00:00` that MySQL `DATETIME`
cannot store, and the `DECIMAL` columns — which PDO returns as strings — are
cast to floats so the JSON carries numbers.

## Idempotency decision

The natural identity of a reading is:

```text
device_id + meter_id + pi_time_utc
```

enforced by `UNIQUE KEY uq_meter_readings`. Ingestion inserts directly rather
than checking first — a check-then-insert has a race where two concurrent
retries both observe that no row exists. A unique key cannot be raced.

The insert's outcome is then translated:

- MySQL error `1062` (duplicate) → `200 already recorded`. The Pi retried a POST
  whose response was lost; the row it wanted stored *is* stored. Success, and
  `200` rather than `201` because nothing was created this time.
- MySQL error `1452` (foreign key) → `422`. The meter is not registered, and the
  service deliberately rejects unknown meters instead of auto-creating them.
- Anything else → logged server-side, generic `500` outward. No SQL and no
  schema names ever reach the client.

This matters more than it looks: the Pi's `push_to_cloud.py` has known bugs in
how it records upload success, so it cannot be relied on to avoid re-sending.
The unique key is doing load-bearing work, not belt-and-braces.

## API contracts

### `POST /ingest.php`

```text
Authorization: Bearer <API_TOKEN_INGEST>
Content-Type: application/json
```

```json
{
  "device_id": "gateway-001-abcd1234",
  "meter_id": "meter-001",
  "ts_utc": "2026-11-15T18:05:11+00:00",
  "import_kwh": 0.244,
  "export_kwh": 0.0,
  "power_l1_w": 20.091,
  "power_l2_w": 0.0,
  "power_l3_w": 0.0
}

The two counters must be non-negative — they are totalizers. The three power
values may be **negative**: a phase that is exporting reads negative watts, and
rejecting that would discard precisely what a solar installation exists to
measure. That asymmetry is the one real rule in ingest validation.
```

`201` created · `200` already recorded · `400` invalid payload (with a `details`
array naming every failed field) · `401` · `405` with `Allow` · `413` body over
16 KiB · `422` unregistered device/meter pair · `429` with `Retry-After` · `500`.

### `GET /v1/get-meter-data.php`

```text
?meter_id=meter-001 & (date=YYYY-MM-DD | from=YYYY-MM-DD&to=YYYY-MM-DD)
Authorization: Bearer <API_TOKEN_GET_USAGE>   — or a session cookie
```

```json
{
  "meter_id": "meter-001",
  "from": "2026-11-15",
  "to": "2026-11-15",
  "readings": [
    {
      "timestamp": "2026-11-15T19:00:00+00:00",
      "import_kwh": 1.0,
      "export_kwh": 0.1,
      "power_l1_w": 230.5,
      "power_l2_w": 0.0,
      "power_l3_w": 0.0
    }
  ]
}
```

One database row is one entry.

There is **no `unit` field**. A response carrying both kWh and watts cannot be
described by a single label — whatever it said would be a false statement about
half the payload — and a per-field units map would only restate what the field
name suffixes already say. Earlier drafts shed a duplicated per-slot `unit` and
a `source` flag that could only ever say "database"; this is the same rule
applied once more.

`200` (possibly empty `readings`) · `400` bad date, reversed range, range over
31 days, or missing `meter_id` · `401` · `404` unknown meter · `405` · `429` ·
`500`.

### `GET /v1/list-meters.php`

```json
{"meters":[{"meter_id":"meter-001","name":"Main meter","description":"Import/export + 3-phase power"}]}
```

Ordered by `name, meter_id`. The order is deterministic on purpose: a client
that assigns each meter a colour by list position needs the position to be
stable across requests.

`200` · `401` · `405` · `429` · `500`.

## Error handling

`src/api-helper.php` centralises every exit path through `respond()`, so all
endpoints emit the same JSON shape with the same `Content-Type`. `respond()` is
typed `: never`, which is why code after it is unreachable by construction
rather than by convention.

Endpoints wrap database work in a `Throwable` boundary — not just
`PDOException`, so the generic `RuntimeException` from `db()` is caught too.
Method, authentication, and input validation all run *before* that boundary, so
an invalid caller still receives its specific `405`, `401`, or `400` without any
database work being attempted.

Production PHP should still run `display_errors=Off` and `log_errors=On` as
defence in depth.

## Deployment model

The target is Plesk with TLS and `public/` as the document root. Server `.env`
values are installed outside version control with restrictive permissions.

`dashboard.js`, `graph.js`, and `vendor/echarts.min.js` are intentionally public
static assets — the browser must download them. ECharts is **vendored rather
than loaded from a CDN**: a third-party script tag on an authenticated page is a
supply-chain dependency, and the page works offline this way.

Because all browser JavaScript is downloadable, no script may ever contain a
token or other secret.

TLS verification stays enabled whenever credentials are transmitted. Using
curl's `-k` with a real token defeats certificate verification and can expose
the token.

## Known issues

Verified by running the application.

1. **The API table on `public/index.php` is stale**: it shows
   `/get-meter-data.php` without the `/v1` prefix, describes the endpoint as
   "15-minute price slots (mock data for now)", and omits `/ingest.php` and
   `/v1/list-meters.php`.
2. **Secrets are tracked by Git.** `.env` and `body.json` are both committed,
   and `.gitignore` has the comment but not the `.env` rule. Anything already
   pushed should be considered exposed and rotated.
3. **The meter picker is half-built.** `fetch_meters()` and
   `/v1/list-meters.php` exist and work, but no page calls them.
4. **`AGENTS.md` and `docs/backend_php_project_plan.md` still describe
   `api-v1.php`** and a two-table schema with `window_start_utc` /
   `window_end_utc` columns. Neither has existed for two redesigns.

### Fixed

- **The login rate limiter returned `500` rather than `429`.** `index.php`
  never loaded `api-helper.php`, so `respond()` was undefined and
  `require_rate_limit()` fatalled on the 6th attempt within a minute. The
  attempt was still blocked, so the protection held, but the status was wrong
  and each hit wrote a stack trace. `rate-limit.php` now requires
  `api-helper.php` itself, which fixes it for every caller rather than for one
  — a dependency is better satisfied by the file that has it than by every
  file that uses it.
- **`public/graph.php` linked to `/logout.php`**, which never existed.
- **A meter's first-ever reading was rejected.** `energy_last_kwh` was
  `NOT NULL` while the Pi sent `null` for it. Both the column and the field are
  gone with the payload change.
- **`dashboard.js` read `slot.unit`**, which the API had stopped sending.

## Current limitations and future work

- Both browser views show one meter at a time.
- The table dashboard still asks the user to paste an API token, even though a
  session login now exists. Only the chart page uses the session.
- Updates are 15-second polling, not server push.
- One shared token per direction: no users, per-meter credentials, expiry,
  scopes, or rotation.
- Rate-limit counters live in the OS temp directory, so they reset when it is
  cleaned and are not shared across multiple application servers.
- No price engine or tariff table.
- No audit log.
- No automated test suite; verification is manual `curl` plus `php -l`.
- Schema changes are applied by hand, not through migrations.
