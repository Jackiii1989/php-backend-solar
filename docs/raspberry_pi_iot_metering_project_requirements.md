# PHP Backend for IoT Metering — Project Plan

This is the server-side counterpart to the Raspberry Pi metering project
(see `doc/raspberry_pi_iot_metering_project_requirements.md` and
`doc/architecture-decision.md`). It receives meter readings over HTTPS
from the Pi's `push_to_cloud.py` and displays them on a web dashboard.

---

## Decisions

- **Purpose of "shows it on the screen"**: a web dashboard in the browser.
- **Where it runs**: a separate server from the Raspberry Pi.
- **Stack**: PHP (no framework, no Composer dependencies needed for v1).
- **Database**: MySQL/MariaDB.
- **Persistence**: store every received reading in SQL, so the dashboard
  can show history and survive restarts.
- **Location**: separate project folder, independent of the `IoT-metering`
  Pi repo.
- **Auth**: Bearer token. The Pi sends `Authorization: Bearer <token>`;
  the backend checks it against a secret configured via `.env`.

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

```sql
CREATE TABLE IF NOT EXISTS meter_readings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(100) NOT NULL,
    meter_id VARCHAR(100) NOT NULL,
    ts_utc DATETIME NOT NULL,
    energy_consumption_now_kwh DECIMAL(12,6) NOT NULL,
    energy_consumption_last_kwh DECIMAL(12,6) NULL,
    energy_consumption_delta_kwh DECIMAL(12,6) NOT NULL,
    received_at_utc DATETIME NOT NULL,
    UNIQUE KEY uq_device_meter_ts (device_id, meter_id, ts_utc)
);
```

`energy_consumption_last_kwh` is nullable — a meter's very first reading
has no previous value, and the Pi sends JSON `null` for it in that case.

**Why the `UNIQUE KEY` exists:** its primary purpose is *correctness*, not
query speed. `(device_id, meter_id, ts_utc)` is the natural, unique
identity of "this specific reading from this specific meter on this
specific device." If the Pi retries a POST, this constraint makes the
retried insert fail harmlessly instead of creating a duplicate row.
"Insert and let the DB reject the dupe" is the idempotency strategy.

**What it costs:** a `UNIQUE KEY` is a secondary index — its own B-tree,
separate from the `id` primary key's clustered index, storing the indexed
columns plus a pointer back to `id`. Every insert has to check it for a
match and write a new entry, on top of writing the row itself. At this
project's write volume (well under one row per meter per minute), that
cost is microseconds and irrelevant. It would only start to matter at
genuinely high write throughput (thousands of inserts/sec), which this
system never approaches.

---

## Config + DB connection

- `src/config.php` reads `.env` (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`,
  `API_TOKEN`).
- `src/db.php` opens a `PDO` connection:
  `mysql:host=...;dbname=...;charset=utf8mb4` with
  `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`.

`.env` should never be committed and should have restricted permissions
(`chmod 600 .env`), same as the Pi side.

---

## Ingest endpoint (`public/ingest.php`)

1. Reject anything but `POST` → `405`.
2. Read `Authorization: Bearer <token>` header, compare against `API_TOKEN`
   using `hash_equals()` → `401` if missing/wrong.
3. `json_decode(file_get_contents('php://input'), true)`.
4. Validate required fields are present and the right type → `400` if not.
5. Insert into `meter_readings`. Catch the duplicate-key error and return
   `200 OK` ("already recorded") instead of `500`.
6. On a fresh insert, return `201 Created`.

### Request payload

Sent by `push_to_cloud.py`'s `convert_to_payload_to_json()`:

```json
{
  "device_id": "mock-meter-001",
  "ts_utc": "2026-08-02T10:00:00+00:00",
  "meter_id": "mock-meter-001",
  "energy_consumption_last_kwh": 1000.0,
  "energy_consumption_now_kwh": 1000.42,
  "energy_consumption_delta_kwh": 0.42
}
```

Handling notes:
- `energy_consumption_last_kwh` may arrive as JSON `null` for a meter's
  first-ever reading — bind it as SQL `NULL`, don't coerce to `0`.
- The `UNIQUE KEY` on `(device_id, meter_id, ts_utc)` is the entire dedup
  mechanism — there is no separate idempotency key field in the payload.
- The backend adds its own `received_at_utc`.
- Timestamps arrive as ISO 8601 with `+00:00`; convert to MySQL DATETIME
  (strip the offset after confirming it's UTC).

`push_to_cloud.py` (the Pi-side uploader) currently has known bugs in how
it records upload success/failure — see the main README's Known Issues.
It doesn't yet reliably mark a reading as uploaded or track failed
attempts, so don't assume the Pi will cleanly avoid re-sending a reading
it already delivered. The `UNIQUE KEY` here is doing real, load-bearing
work, not just defensive belt-and-suspenders.

---

## Dashboard (`public/index.php`)

Query the latest row per `meter_id`:

```sql
SELECT mr.*
FROM meter_readings mr
INNER JOIN (
    SELECT meter_id, MAX(ts_utc) AS max_ts
    FROM meter_readings
    GROUP BY meter_id
) latest
  ON mr.meter_id = latest.meter_id AND mr.ts_utc = latest.max_ts
ORDER BY mr.meter_id;
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
     -d '{"device_id":"mock-meter-001","ts_utc":"2026-08-02T10:00:00+00:00","meter_id":"mock-meter-001","energy_consumption_last_kwh":1000.0,"energy_consumption_now_kwh":1000.42,"energy_consumption_delta_kwh":0.42}'
   ```
   Expect `201` on first call, `200` on a repeat of the exact same call
   (dedup via the `UNIQUE KEY`).
4. Open `http://localhost:8000/` in a browser and confirm the row appears.

---

## Integration with the Pi side

The Pi's uploader is `push_to_cloud.py`. It reads config from
`config/iot-metering.env` via `EnvironmentFile=` (systemd) or
`scripts/run_with_env.sh` (manual runs):

```text
IOT_METERING_API_URL        → production endpoint (not currently read by any code)
IOT_METERING_API_URL_LOCAL  → Tailscale test address — push_to_cloud.py reads this one
IOT_METERING_API_TOKEN      → must match the backend's API_TOKEN
IOT_METERING_DEVICE_ID      → currently mock-meter-001
```

`push_to_cloud.py` currently has the line reading `IOT_METERING_API_URL`
commented out and reads `IOT_METERING_API_URL_LOCAL` instead — readings go
to a local Tailscale test address, not this backend's production URL.
Once this backend is built and confirmed working, that needs switching
(either uncomment the production-URL line, or point
`IOT_METERING_API_URL_LOCAL` here for testing first) — otherwise readings
keep accumulating locally with `uploaded_at_utc` still `NULL` and never
reach this server.

Modbus TCP itself must stay on the local trusted network and never be
exposed to the internet — only this REST API crosses that boundary
(planned network split: meters on isolated eth0, internet via wlan0 — not
yet executed, see the main README).

---

## Open items / next steps

- [x] Pi-side uploader exists as `push_to_cloud.py` — implemented, but has
      known success/failure-tracking bugs (see main README's Known
      Issues) and currently points at a local test URL, not this backend.
- [ ] Scaffold the folder structure above.
- [ ] Write `db/schema.sql` and load it into MySQL.
- [ ] Write `src/config.php` and `src/db.php`.
- [ ] Write `public/ingest.php` (auth check, validation, idempotent insert).
- [ ] Write `public/index.php` (dashboard query + HTML table —
      remember `htmlspecialchars()` on every output value).
- [ ] Test locally with `php -S` + `curl` (payload above).
- [ ] Point the Pi at this backend for real (fix the
      `IOT_METERING_API_URL`/`_LOCAL` mixup above) and confirm the backlog
      of unsent readings drains.
- [ ] Later: deploy behind a real web server (Apache/Nginx) with HTTPS.

## Security review notes

- Stored XSS: escape all dashboard output (`htmlspecialchars`).
- PDO prepared statements everywhere.
- `hash_equals()` for the token check; token from `random_bytes(32)`.
- `display_errors=Off` in production; never echo PDO exceptions.
- MySQL user for the app: SELECT + INSERT only.
- `.env` outside the web root, never committed, `chmod 600`.
- Dashboard needs auth (HTTP Basic at minimum) — energy data reveals
  occupancy patterns.
- Limit request body size; validate numeric ranges and timestamps.
