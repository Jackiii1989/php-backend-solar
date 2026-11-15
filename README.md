# php-backend-solar-pi

PHP backend for a Raspberry Pi IoT metering project. The Pi's
`push_to_cloud.py` POSTs meter readings over HTTPS; this service stores them in
MySQL/MariaDB and serves them back as JSON to a browser dashboard and an
interactive chart.

Design rationale and API contracts:
[`docs/arhitecture-decisions.md`](docs/arhitecture-decisions.md).

## Project structure

```text
php-backend-solar-pi/
|-- .env                         Local configuration and secrets
|-- db/
|   `-- schema.sql               MySQL/MariaDB schema (devices, meters, meter_readings)
|-- docs/                        Requirements, plans, migration runbook, notes
|-- public/                      Web document root
|   |-- .htaccess                Forwards Authorization on Apache/FastCGI
|   |-- index.php                Landing page, login form, token dashboard
|   |-- dashboard.js             Table dashboard (token in a password field)
|   |-- graph.php                Chart page (session-gated)
|   |-- graph.js                 Two ECharts charts: power (W), energy (kWh)
|   |-- ingest.php               POST endpoint the Pi calls
|   |-- vendor/echarts.min.js    Vendored chart library (no CDN)
|   `-- v1/
|       |-- get-meter-data.php   GET readings for one meter over a date range
|       `-- list-meters.php      GET the registered meters
`-- src/                         Never reachable over HTTP
    |-- config.php               Environment/.env loader
    |-- db.php                   PDO factory + db_fetch_all()
    |-- api-helper.php           JSON responses, auth, method, time parsing
    |-- meter-readings.php       Meter/reading queries
    |-- rate-limit.php           Fixed-window per-IP rate limiter
    `-- session.php              Browser session helpers
```

## Requirements

- PHP 8.0 or newer (developed against 8.4)
- PHP PDO MySQL extension (`pdo_mysql`)
- MySQL or MariaDB
- `curl.exe` when using the PowerShell examples

## Configuration

Create `.env` in the repository root. Every key below is read through
`src/config.php`, which checks process environment variables first, then `.env`.

```dotenv
DB_HOST=localhost
DB_NAME=iot_metering
DB_USER=iot_meter_backend
DB_PASS=replace-with-local-password

# Two separate tokens: writing readings and reading them are different
# privileges, so they get different credentials.
API_TOKEN_INGEST=replace-with-a-long-random-token
API_TOKEN_GET_USAGE=replace-with-a-different-long-random-token

# Browser login for the dashboard and the graph page.
DASHBOARD_PASSWORD_HASH=replace-with-a-password_hash-output

# Rate limits. Each endpoint keeps its own counter per client IP.
RATE_LIMIT_WINDOW_SECONDS_API=60
RATE_LIMIT_API_MAX_REQUESTS=60
RATE_LIMIT_WINDOW_SECONDS_INGEST=60
RATE_LIMIT_INGEST_MAX_REQUESTS=20
RATE_LIMIT_WINDOW_SECONDS_LOGIN=60
RATE_LIMIT_LOGIN_MAX_REQUESTS=5
```

The rate-limit keys are optional; the code defaults are the values shown.

Generate an API token:

```powershell
php -r "echo bin2hex(random_bytes(32));"
```

Generate the dashboard password hash (paste the whole `$2y$...` string,
including the dollar signs, into `.env`):

```powershell
php -r "echo password_hash('choose-a-password', PASSWORD_DEFAULT);"
```

## Database setup

Import the schema:

```powershell
Get-Content db\schema.sql | mysql.exe -u root -p
```

Create a least-privilege application user:

```powershell
mysql.exe -u root -p -e "CREATE USER IF NOT EXISTS 'iot_meter_backend'@'localhost' IDENTIFIED BY 'replace-with-local-password'; GRANT SELECT, INSERT ON iot_metering.* TO 'iot_meter_backend'@'localhost';"
```

Register a development device and meter. A meter belongs to a device, and
`meter_readings` references the `(device_id, meter_id)` pair — so the device row
must exist first, and the pair must match exactly what the Pi sends:

```powershell
mysql.exe -u root -p -e "INSERT INTO iot_metering.devices (device_id, name, description, created_at_utc) VALUES ('gateway-001-abcd1234', 'Gateway 001', 'Solar site', UTC_TIMESTAMP());"

mysql.exe -u root -p -e "INSERT INTO iot_metering.meters (meter_id, device_id, name, description, created_at_utc) VALUES ('meter-001', 'gateway-001-abcd1234', 'Main meter', 'Import/export + 3-phase power', UTC_TIMESTAMP());"
```

Inspect what is registered:

```powershell
mysql.exe -u root -p -e "SELECT meter_id, device_id, name FROM iot_metering.meters;"
```

Test the configured connection through the application's own loader:

```powershell
php -r "require 'src/db.php'; print_r(db()->query('SELECT * FROM meters')->fetchAll());"
```

## Start the local server

From the repository root:

```powershell
php -S 127.0.0.1:8000 -t public
```

Then open `http://127.0.0.1:8000/`.

`-t public` is not cosmetic: it makes `public/` the document root, which is the
security boundary. `src/`, `.env`, and `db/` sit above it and cannot be
requested by a browser.

## Using the application

1. Open `http://127.0.0.1:8000/` and sign in with the password whose hash is in
   `DASHBOARD_PASSWORD_HASH`. The session cookie is HttpOnly and SameSite=Lax.
2. **Table dashboard** (same page): paste `API_TOKEN_GET_USAGE`, choose a meter
   ID and a UTC date, and press *Load measurements*. This part authenticates
   with the token, not the session, so it works without signing in.
3. **Chart** (`/graph.php`): opens from the link shown after sign-in. It is
   gated by the session — anonymous visitors are redirected to the login page —
   and calls the API with the session cookie, so no token is typed anywhere.
   Bars are energy per interval, the line is cumulative energy over the visible
   range, both in kWh, and it refreshes every 15 seconds.

## API

Both read endpoints accept **either** a Bearer token **or** a logged-in session
cookie. Ingestion accepts the token only.

Set a token for the examples:

```powershell
$READ = "<API_TOKEN_GET_USAGE from .env>"
$WRITE = "<API_TOKEN_INGEST from .env>"
```

### `GET /v1/list-meters.php`

Every registered meter. Handy for checking what the database knows about;
intended for a meter picker that is not built yet.

```powershell
curl.exe -i "http://127.0.0.1:8000/v1/list-meters.php" -H "Authorization: Bearer $READ"
```

```json
{"meters":[{"meter_id":"meter-001","name":"Main meter","description":"Import/export + 3-phase power"}]}
```

Statuses: `200`, `401`, `405`, `429`, `500`.

### `GET /v1/get-meter-data.php`

One meter's readings over a UTC date range, exactly as stored.

```powershell
# One day
curl.exe -i "http://127.0.0.1:8000/v1/get-meter-data.php?date=2026-11-15&meter_id=meter-001" -H "Authorization: Bearer $READ"

# A range (inclusive days, maximum 31)
curl.exe -i "http://127.0.0.1:8000/v1/get-meter-data.php?from=2026-11-09&to=2026-11-15&meter_id=meter-001" -H "Authorization: Bearer $READ"
```

`meter_id` is required. `date` is shorthand for `from`+`to`; omitting all three
means today in UTC.

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

One database row is one entry. Nothing is derived: `import_kwh` and
`export_kwh` are the meter's raw cumulative counters, and the three power
values are instantaneous, so a negative one means that phase is exporting.

There is no top-level `unit` field. One response carries both kWh and watts,
so no single label could be honest about both — the units live in the field
names instead.

Statuses: `200` (possibly empty `readings`), `400` (bad date, reversed range,
range over 31 days, missing `meter_id`), `401`, `404` (unknown meter), `405`,
`429`, `500`.

### `POST /ingest.php`

One reading per request, from the Pi.

Create `payload.json` in the repository root:

```powershell
@'
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
'@ | Set-Content -Encoding ascii payload.json
```

`-Encoding ascii` avoids the UTF-8 byte-order mark that Windows PowerShell's
older `utf8` encoding prepends, which would make the body invalid JSON.

Confirm the file parses before sending it:

```powershell
Get-Content -Raw payload.json | ConvertFrom-Json | Format-List
```

Send it:

```powershell
curl.exe -i -X POST "http://127.0.0.1:8000/ingest.php" -H "Authorization: Bearer $WRITE" -H "Content-Type: application/json" --data-binary "@payload.json"
```

Expect `201 {"status":"created"}` the first time and
`200 {"status":"already recorded"}` on an exact repeat — the unique key on
`(device_id, meter_id, pi_time_utc)` makes retries harmless.

`import_kwh` and `export_kwh` must be non-negative — they are totalizers. The
three `power_l*_w` values may be **negative**: a phase that is exporting reads
negative watts, and rejecting that would discard exactly what this project
measures.

Statuses: `201`, `200` (duplicate), `400` (invalid payload, with a `details`
list naming every failed field), `401`, `405`, `413` (body over 16 KiB), `422`
(the `device_id`/`meter_id` pair is not registered), `429`, `500`.

## Checks

Syntax-check every application file:

```powershell
Get-ChildItem src,public -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

## Known issues

Verified by running the code.

- The API table on `public/index.php` shows the old path
  `/get-meter-data.php` (the real one is `/v1/get-meter-data.php`), describes
  it as "price slots (mock data)", and does not list the other endpoints.
- `.env` and `body.json` are tracked by Git, and `.gitignore` contains a
  comment about secrets but no `.env` rule. Any credential that has been
  pushed should be considered exposed and rotated.
- The meter-picker feature is half-built: `fetch_meters()` and
  `/v1/list-meters.php` exist and work, but no page calls them yet.
- `docs/arhitecture-decisions.md` still describes the pre-migration design.
  Its replacement is sitting beside it as `arhitecture-decisions (2).md`.

### Fixed since the last review

- The login rate limiter returned `500` instead of `429`, because
  `index.php` never loaded `api-helper.php` and `respond()` was undefined.
  `rate-limit.php` now requires it directly, which fixes it for every caller
  rather than one.
- `public/graph.php` linked to `/logout.php`, which has never existed.
- A meter's first-ever reading was rejected: `energy_last_kwh` was `NOT NULL`
  while the Pi sent `null`. Both the column and that field are gone.
- `dashboard.js` read `slot.unit`, which the API had stopped sending.
