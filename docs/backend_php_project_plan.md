# PHP Backend for IoT Metering — Project Plan

## Context

This is the server-side counterpart to the Raspberry Pi metering project
(see `doc/raspberry_pi_iot_metering_project_requirements.md`).

Per that spec, the Pi's future `aggregator.py`:

1. calculates a 15-minute aggregate per meter,
2. saves it locally in the Pi's SQLite DB,
3. sends it to a REST API over HTTPS using HTTP POST,
4. includes an idempotency key so retries don't create duplicates.

This document describes the backend that receives those POSTs and displays
the data on a web dashboard.

---

## Decisions made

- **Purpose of "shows it on the screen"**: a web dashboard in the browser
  (not a physical display, not just console logging).
- **Where it runs**: a separate server from the Raspberry Pi (matches the
  requirements doc's client/server split — Pi POSTs over the network).
- **Stack**: PHP (no framework, no Composer dependencies needed for v1).
- **Database**: MySQL/MariaDB.
- **Persistence**: store every received aggregate in SQL (not memory-only),
  so the dashboard can show history and survive restarts.
- **Location**: separate project folder, independent of the `IoT-metering`
  Pi repo (e.g. `~/IoT-metering-backend`), since it's a different deployable
  running on different hardware.
- **Auth**: Bearer token. The Pi sends `Authorization: Bearer <token>`;
  the backend checks it against a secret configured via `.env`
  (matches the requirements doc's `IOT_API_TOKEN` idea — never hardcode
  secrets in code).

---

## Project skeleton

```text
IoT-metering-backend/
├── .env              (secrets — DB creds + API token, never commit)
├── .gitignore        (ignore .env)
├── src/
│   ├── config.php    (loads .env, defines DB + token config)
│   └── db.php        (PDO connection helper)
├── public/           (web root / DocumentRoot)
│   ├── index.php      (dashboard page)
│   └── ingest.php     (POST endpoint the Pi calls)
└── db/
    └── schema.sql     (MySQL table definitions)
```

---

## Database schema (`db/schema.sql`)

One table, mirroring the Pi's `meter_aggregates` table so the incoming
JSON payload maps 1:1 onto columns:

```sql
CREATE TABLE IF NOT EXISTS meter_aggregates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    meter_id VARCHAR(100) NOT NULL,
    window_start_utc DATETIME NOT NULL,
    window_end_utc DATETIME NOT NULL,
    number_of_readings INT NOT NULL,
    first_energy_kwh DECIMAL(12,6) NOT NULL,
    last_energy_kwh DECIMAL(12,6) NOT NULL,
    energy_delta_kwh DECIMAL(12,6) NOT NULL,
    average_power_w DECIMAL(12,3) NOT NULL,
    received_at_utc DATETIME NOT NULL,
    UNIQUE KEY uq_meter_window (meter_id, window_start_utc, window_end_utc)
);
```

**Why the `UNIQUE KEY` exists — design note:**
Its primary purpose is *correctness*, not query speed. If the Pi retries a
POST (e.g. the response was lost but the insert already succeeded), this
constraint makes the retried insert fail harmlessly instead of creating a
duplicate row that would corrupt energy totals. "Insert and let the DB
reject the dupe" is the idempotency strategy.

As a secondary effect, it also is an index, which speeds up lookups by
`meter_id` + time window (e.g. the dashboard's "latest per meter" query).

**What it costs (Q&A learning):**
- In InnoDB, the `PRIMARY KEY` (`id`) is the *clustered index* — the table
  rows themselves are physically stored ordered by it. A `UNIQUE KEY` on
  other columns is a separate *secondary index*: its own B-tree, storing
  the indexed columns (`meter_id`, `window_start_utc`, `window_end_utc`)
  plus a pointer back to the primary key. That's genuinely extra disk
  space, and MySQL caches index pages in the buffer pool, so it's extra
  memory pressure too.
- Every insert has to (1) check this index for an existing match — the
  uniqueness check — and (2) write a new entry into its B-tree, on top of
  writing the row itself. So each insert does more work than it would with
  no index at all.
- None of this matters in practice here: write volume is roughly *one row
  per meter per 15 minutes*. The extra work per insert is microseconds and
  irrelevant at this scale. This tradeoff (index maintenance cost vs. query
  speed) only starts to bite at high write throughput — thousands of
  inserts/sec — which this system never approaches. So: correct the
  intuition "index = faster reads, slower/heavier writes" is right in
  general, but here you're not really paying for speed — you're paying a
  tiny, irrelevant cost for a correctness guarantee, and getting faster
  lookups as a free bonus.

---

## Config + DB connection

- `src/config.php` reads `.env` (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`,
  `API_TOKEN`) — either via `getenv()` if set as real environment variables,
  or a small manual `.env` parser.
- `src/db.php` opens a `PDO` connection:
  `mysql:host=...;dbname=...;charset=utf8mb4` with
  `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`.

`.env` should never be committed (add to `.gitignore`) and should have
restricted permissions (`chmod 600 .env`), same as the Pi side.

---

## Ingest endpoint (`public/ingest.php`)

This is "the backend accepting the request." Logic:

1. Reject anything but `POST` → `405`.
2. Read `Authorization: Bearer <token>` header, compare against `API_TOKEN`
   using `hash_equals()` (not `==`, to avoid timing attacks) → `401` if
   missing/wrong.
3. `json_decode(file_get_contents('php://input'), true)`.
4. Validate required fields are present and the right type (string/numeric)
   → `400` if not.
5. Insert into `meter_aggregates`. Catch the duplicate-key error and return
   `200 OK` ("already recorded") instead of `500`.
6. On a fresh insert, return `201 Created`.

### Expected request payload (from the Pi's future `aggregator.py`)

```json
{
  "meter_id": "mock-meter-001",
  "window_start_utc": "2026-06-27T10:00:00+00:00",
  "window_end_utc": "2026-06-27T10:15:00+00:00",
  "number_of_readings": 15,
  "first_energy_kwh": 1000.000,
  "last_energy_kwh": 1000.100,
  "energy_delta_kwh": 0.100,
  "average_power_w": 400.0
}
```

This mirrors the Pi's `meter_aggregates` table
(`utility/db_meters.py` schema / requirements doc) field for field.

---

## Dashboard (`public/index.php`)

Query the latest row per `meter_id`:

```sql
SELECT ma.*
FROM meter_aggregates ma
INNER JOIN (
    SELECT meter_id, MAX(window_end_utc) AS max_end
    FROM meter_aggregates
    GROUP BY meter_id
) latest
  ON ma.meter_id = latest.meter_id AND ma.window_end_utc = latest.max_end
ORDER BY ma.meter_id;
```

Render as an HTML table. Add `<meta http-equiv="refresh" content="15">` for
a simple "live" feel without needing JavaScript.

---

## Local testing steps

1. Create the DB/user, then load the schema:
   `mysql -u root -p < db/schema.sql`
2. Start PHP's built-in dev server from the project root:
   `php -S 0.0.0.0:8000 -t public`
3. Send a sample POST:
   ```bash
   curl -X POST http://localhost:8000/ingest.php \
     -H "Authorization: Bearer <token>" \
     -H "Content-Type: application/json" \
     -d '{"meter_id":"mock-meter-001","window_start_utc":"2026-06-27T10:00:00+00:00","window_end_utc":"2026-06-27T10:15:00+00:00","number_of_readings":15,"first_energy_kwh":1000.000,"last_energy_kwh":1000.100,"energy_delta_kwh":0.100,"average_power_w":400.0}'
   ```
   Expect `201` on first call, `200` on a repeat of the exact same call
   (idempotency check).
4. Open `http://localhost:8000/` in a browser and confirm the row appears.

---

## Integration with the Pi side (future work)

Once this backend works end-to-end, the Pi's `aggregator.py` (not yet
written) will POST to it using `IOT_API_URL` / `IOT_API_TOKEN` read from
the Pi's own `.env`, over HTTPS in production. Modbus TCP itself must stay
on the local trusted network and never be exposed to the internet — only
this REST API crosses that boundary.

---

## Open items / next steps

- [ ] Scaffold the folder structure above.
- [ ] Write `db/schema.sql` and load it into MySQL.
- [ ] Write `src/config.php` and `src/db.php`.
- [ ] Write `public/ingest.php` (auth check, validation, idempotent insert).
- [ ] Write `public/index.php` (dashboard query + HTML table).
- [ ] Test locally with `php -S` + `curl` as above.
- [ ] Later: write the Pi's `aggregator.py` to actually call this endpoint.
- [ ] Later: deploy behind a real web server (Apache/Nginx) with HTTPS.
