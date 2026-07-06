# PHP Backend for IoT Metering — Project Plan

## Context

This is the server-side counterpart to the Raspberry Pi metering project
(see `docs/raspberry_pi_iot_metering_project_requirements.md`).

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
- **Auth**: Bearer token. The Pi sends `Authorization: Bearer <token>`;
  the backend checks it against a secret configured via `.env`
  (never hardcode secrets in code).
- **Schema (revised from v1): normalized two-table design.** The original
  plan had a single `meter_aggregates` table where the meter existed only
  as a string column. Revised to `meters` (entities) + `meter_aggregates`
  (events) linked by a foreign key. Rationale below.
- **DB user**: the PHP app connects as a least-privilege user
  (`SELECT, INSERT` only) — the app never needs `DELETE`/`DROP`/`ALTER`,
  so a compromised app can't run them either.

### Why the two-table design (entities vs. events)

A **meter** is an entity (a physical thing with a name and a lifetime);
an **aggregate** is an event (a fact about one meter in one 15-minute
window). Giving each its own table buys:

1. **One place for meter facts** — display name, description, etc. exist
   exactly once; no update anomalies.
2. **Integrity enforced by the DB** — the FK makes "no aggregate without
   a registered meter" a rule that no PHP bug can bypass.
3. **Absence is representable** — a registered meter with no data is a
   natural state (`LEFT JOIN` finds it). For a monitoring system, a
   silent meter is exactly the thing to notice. The single-table design
   literally could not express this.
4. **An allowlist** — a leaked API token alone isn't enough to pollute
   the data with invented meter IDs; unknown IDs bounce off the FK.
5. **Room to grow** — future per-meter attributes (location, tariff,
   enabled flag) are columns on the small table; the measurement history
   never needs schema surgery.

Costs accepted: one JOIN in the dashboard query, meters must be
registered before their data is accepted, and `ingest.php` must handle
one more error path (FK violation 1452).

Note: the backend's `meters` table is **not** a copy of the Pi's. The Pi
stores Modbus connection config (host/port/unit_id) — the Pi's business.
The backend stores identity + display info only. Same entity, different
responsibilities, different columns.

---

## Project skeleton

```text
IoT-metering-backend/
├── .env              (secrets — DB creds + API token, never commit)
├── .gitignore        (ignore .env)
├── src/
│   ├── config.php    (loads .env, defines DB + token config)
│   └── db.php        (PDO connection helper)
├── public/           (web root / DocumentRoot — the ONLY exposed folder;
│   │                  secrets and logic live outside it)
│   ├── index.php      (dashboard page)
│   └── ingest.php     (POST endpoint the Pi calls)
└── db/
    └── schema.sql     (MySQL database + table definitions)
```

---

## Database schema (`db/schema.sql`)

Two tables. `meters` must be created first — `meter_aggregates`
references it.

```sql
CREATE TABLE IF NOT EXISTS meters (
    meter_id VARCHAR(100) PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    created_at_utc DATETIME NOT NULL
);

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
    UNIQUE KEY uq_meter_window (meter_id, window_start_utc, window_end_utc),
    FOREIGN KEY (meter_id)
        REFERENCES meters(meter_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);
```

**Why the `UNIQUE KEY` exists — design note:**
Its primary purpose is *correctness*, not query speed. If the Pi retries a
POST (e.g. the response was lost but the insert already succeeded), this
constraint makes the retried insert fail harmlessly instead of creating a
duplicate row that would corrupt energy totals. "Insert and let the DB
reject the dupe" is the idempotency strategy. As a secondary effect it is
also an index, which speeds up the dashboard's "latest per meter" lookup.

**What it costs (Q&A learning):** in InnoDB the `PRIMARY KEY` is the
clustered index (rows are stored ordered by it); a `UNIQUE KEY` is a
separate secondary B-tree that every insert must check and maintain.
Extra disk, extra memory, extra work per insert — all irrelevant at one
row per meter per 15 minutes. The cost/benefit only bites at thousands of
inserts/sec. Here we pay a tiny, irrelevant cost for a correctness
guarantee and get faster lookups as a free bonus.

**FK actions — deliberate choices, not boilerplate:**
`ON UPDATE CASCADE` = renaming a meter_id carries its history along.
`ON DELETE RESTRICT` = a meter with data cannot be deleted; measurement
history can't be orphaned or silently destroyed.

**Charset/collation (Q&A learning):** `utf8mb4` = real UTF-8 (MySQL's
plain `utf8` is a broken 3-byte subset — never use it). Collation
`utf8mb4_unicode_ci` decides comparison/sorting; `_ci` = case-insensitive,
which means the UNIQUE key treats `Mock-Meter-001` and `mock-meter-001`
as the same meter — accepted as a feature here. `DECIMAL`, not `FLOAT`,
for counters: exact, no rounding drift. `DATETIME`, not strings: real
date comparisons and `MAX()`.

---

## Config + DB connection

- `src/config.php` reads `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`,
  `API_TOKEN` — real environment variables win, then a small manual
  `.env` parser, then error.
- `src/db.php` opens a `PDO` connection:
  `mysql:host=...;dbname=...;charset=utf8mb4` with
  `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`.

`.env` is never committed (`.gitignore`) and has restricted permissions
(`chmod 600 .env`), same as the Pi side.

---

## Ingest endpoint (`public/ingest.php`)

1. Reject anything but `POST` → `405`.
2. Read `Authorization: Bearer <token>` header, compare against
   `API_TOKEN` using `hash_equals()` (not `==`, to avoid timing attacks)
   → `401` if missing/wrong.
3. `json_decode(file_get_contents('php://input'), true)`.
4. Validate required fields are present and the right type → `400` if not.
5. Insert into `meter_aggregates`. Two expected DB errors:
   - duplicate key (1062) → `200 OK` "already recorded" (idempotent retry);
   - FK violation (1452) → unknown meter — behavior is an **open
     decision**, see below.
6. On a fresh insert, return `201 Created`.

**Open decision — unknown meter_id:** reject (e.g. `422`, meters table
acts as an allowlist; adding a meter is a deliberate admin act) vs.
auto-register on first POST (zero friction, but the allowlist property
disappears and typo'd IDs silently become "real" meters). To be decided
before writing step 5.

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

There is no separate idempotency_key field — the window itself
(meter_id + window_start + window_end) is the idempotency key, enforced
by `uq_meter_window`.

---

## Dashboard (`public/index.php`)

Latest row per meter — now driven from `meters` via `LEFT JOIN`, so
registered-but-silent meters still appear (with a "no data" marker):

```sql
SELECT m.meter_id, m.name, ma.*
FROM meters m
LEFT JOIN meter_aggregates ma
  ON ma.meter_id = m.meter_id
 AND ma.window_end_utc = (
      SELECT MAX(window_end_utc)
      FROM meter_aggregates
      WHERE meter_id = m.meter_id
 )
ORDER BY m.meter_id;
```

Render as an HTML table. `<meta http-equiv="refresh" content="15">` for a
simple "live" feel without JavaScript.

---

## Local testing steps

1. Load the schema, create the least-privilege user, register a meter:
   see `db/schema.sql` header + README.
2. Start PHP's built-in dev server: `php -S 0.0.0.0:8000 -t public`
3. POST the sample payload with
   `Authorization: Bearer <token>` — expect `201`, then `200` on an exact
   repeat (idempotency), `401` with a bad token, `405` on GET, `400` on a
   malformed body, and the unknown-meter response for an unregistered ID.
4. Open `http://localhost:8000/` and confirm the row appears.

---

## Integration with the Pi side (future work)

Once this backend works end-to-end, the Pi's `aggregator.py` will POST to
it using `IOT_API_URL` / `IOT_API_TOKEN` from the Pi's own `.env`, over
HTTPS in production. Modbus TCP stays on the local trusted network — only
this REST API crosses that boundary. New physical meters must be
registered in the backend's `meters` table (or auto-registered, per the
open decision above) before their uploads are accepted.

---

## Open items / next steps

- [x] Scaffold the folder structure.
- [x] Write `db/schema.sql` (revised: normalized `meters` +
  
      `meter_aggregates` with FK) and load it into MySQL.
- [ ] Decide: unknown meter on ingest — reject vs. auto-register.
- [ ] Write `src/config.php` and `src/db.php`.
- [ ] Write `public/ingest.php` (auth, validation, idempotent insert,
  
      unknown-meter handling).
- [ ] Write `public/index.php` (LEFT JOIN dashboard).
- [ ] Test locally with `php -S` + `curl`.
- [ ] Later: write the Pi's `aggregator.py` to actually call this endpoint.
- [ ] Later: deploy behind a real web server (Apache/Nginx) with HTTPS.
