# Raspberry Pi 4 IoT Metering Project — Description and Requirements

## Project goal

I am building an IoT metering project on a Raspberry Pi 4 running Raspberry Pi OS Trixie.

The Raspberry Pi should read energy counters from one or more energy meters using **Modbus TCP**. The data should be stored locally first, so that measurements are not lost if the device restarts or if the internet connection is unavailable.

For now, the hardware meters are not available, so the project should support mocked/demo readings during development. However, the real communication method in production will always be **Modbus TCP**.

I want to learn the project step by step and understand the design decisions, not just copy a final solution.

---

## Main requirements

The system should:

1. Run on a Raspberry Pi 4 with Raspberry Pi OS Trixie.
2. Be implemented in Python.
3. Use `uv` as the Python project and dependency manager.
4. Store code in one project folder, for example:

   ```text
   /home/user/IoT-metering
   ```

5. Read all enabled meters every minute.
6. Store successful meter readings persistently in a local SQLite database.
7. Support more than one meter.
8. Store meter configuration in the database.
9. Every 15 minutes, calculate aggregated values from the raw readings.
10. Send the aggregated values to a REST API using HTTP POST.
11. Also save the calculated aggregate locally before sending it to the server.
12. Log program output and errors so they are visible with `journalctl`.
13. Keep the design simple at first and grow it step by step.

---

## Important design decisions

### Local storage first

The Raspberry Pi should first save values locally before sending anything over the internet.

Reason:

```text
meter read succeeds
    ↓
save locally in SQLite
    ↓
later aggregate
    ↓
send to REST API
```

This avoids losing data when:

- the server is down,
- the internet connection is unavailable,
- the Raspberry Pi reboots,
- the upload fails.

---

### SQLite database

Use SQLite as the local persistent database.

Reasons:

- simple local file-based database,
- no separate database server needed,
- good fit for small IoT edge devices,
- easy to inspect with the `sqlite3` command-line tool,
- supports SQL queries for time windows and meter IDs,
- more reliable and structured than CSV or JSON files for this use case.

---

### Multiple meters

The system should not hardcode only one meter.

Instead, the database should contain a `meters` table. The poller should query all enabled meters and read each one.

Concept:

```text
meters table
    ↓
SELECT all enabled meters
    ↓
for each meter:
    read value
    save successful reading
```

---

### Modbus TCP only

The production communication method will always be Modbus TCP.

Therefore, a `protocol` column is not required for now.

The meter table should eventually store Modbus TCP connection basics directly, such as:

- host/IP address,
- port,
- unit ID / slave ID.

Mocking is only for development and should not pollute the real meter schema with mock-specific columns such as `mock_min_power_w` or `mock_start_energy_kwh`.

> **Current status (main branch):** the `meters` table currently stores a
> single generic `address TEXT` column (e.g. `mock://mock-meter-001` for
> mock meters, or a bare IP like `192.168.1.103`) instead of separate
> `host` / `port` / `unit_id` columns. This was fine while every meter is
> mocked, but it does **not** yet match the design above. Before wiring up
> a real Modbus TCP reader (learning step 13), the `meters` table needs a
> migration to split `address` into `host`, `port`, and `unit_id` columns.
> Flagging this now so it isn't forgotten later.

---

### Error handling

The database should store successful meter readings only.

Failed meter reads should be:

- logged locally through Python logging,
- visible through `journalctl`,
- optionally propagated to the server,
- not stored in the main measurement table.

The trade-off is accepted:

```text
Database can answer:
  "What values were successfully read?"

Database cannot answer:
  "How many read failures happened?"
```

Failure events can be sent to the server or handled by logs.

---

## Recommended database schema

### Table: `meters`

This table stores the known meters and their Modbus TCP connection information.

```sql
CREATE TABLE IF NOT EXISTS meters (
    -- Stable text ID for the meter.
    --
    -- Examples:
    --   main-meter
    --   heat-pump-meter
    --   apartment-01-meter
    meter_id TEXT PRIMARY KEY,

    -- Human-readable name for dashboards, logs and debugging.
    name TEXT NOT NULL,

    -- Optional description.
    --
    -- Example:
    --   "Main building meter in technical room"
    description TEXT,

    -- Modbus TCP host address.
    --
    -- This can be:
    --   192.168.1.50
    --   meter-01.local
    host TEXT NOT NULL,

    -- Modbus TCP default port is 502.
    port INTEGER NOT NULL DEFAULT 502,

    -- Modbus unit ID, sometimes also called slave ID.
    --
    -- Even over TCP this can matter, especially when a gateway is used.
    unit_id INTEGER NOT NULL DEFAULT 1,

    -- Whether this meter should be read by the poller.
    --
    -- 1 = enabled
    -- 0 = disabled
    enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),

    -- Timestamp when this meter record was created.
    created_at_utc TEXT NOT NULL,

    -- Timestamp when this meter record was last updated.
    updated_at_utc TEXT NOT NULL
);
```

> **Current schema (main branch, as actually implemented in
> `utility/db_meters.py`)** — simpler than the target above, since only
> mock meters exist so far:
>
> ```sql
> CREATE TABLE IF NOT EXISTS meters (
>     meter_id TEXT PRIMARY KEY,
>     name TEXT NOT NULL,
>     address TEXT NOT NULL,   -- generic: "mock://meter-001" or a bare IP
>     enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
>     created_at_utc TEXT NOT NULL,
>     updated_at_utc TEXT NOT NULL
> );
> ```
>
> No `description`, `host`, `port`, or `unit_id` columns yet. See the note
> under "Modbus TCP only" above.

---

### Table: `meter_readings`

This table stores successful raw meter readings.

Each row represents one successful reading from one meter.

```sql
CREATE TABLE IF NOT EXISTS meter_readings (
    -- Internal database ID for this reading.
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    -- UTC timestamp when the value was measured.
    --
    -- Example:
    --   2026-06-27T20:15:00+00:00
    ts_utc TEXT NOT NULL,

    -- Which meter this reading belongs to.
    meter_id TEXT NOT NULL,

    -- Cumulative energy counter in kWh.
    --
    -- A real meter usually reports a total/cumulative counter:
    --   1000.000
    --   1000.015
    --   1000.031
    --
    -- Consumption is calculated later using:
    --   last_energy_kwh - first_energy_kwh
    energy_kwh REAL NOT NULL CHECK (energy_kwh >= 0),

    -- Optional instantaneous power in watts.
    --
    -- NULL is allowed because not every meter provides this.
    power_w REAL CHECK (power_w IS NULL OR power_w >= 0),

    -- Relationship to the meters table.
    --
    -- This prevents inserting readings for unknown meters.
    FOREIGN KEY (meter_id)
        REFERENCES meters(meter_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);
```

> **Current schema (main branch):** matches the above, except the actual
> `utility/db_meters.py` version omits the `CHECK (energy_kwh >= 0)` and
> `CHECK (power_w IS NULL OR power_w >= 0)` constraints. Worth adding back
> later — right now nothing stops a negative value from being inserted.

---

### Index for faster time-window queries

The aggregator will often query:

```sql
SELECT *
FROM meter_readings
WHERE meter_id = ?
  AND ts_utc >= ?
  AND ts_utc < ?
ORDER BY ts_utc;
```

Therefore, create an index:

```sql
CREATE INDEX IF NOT EXISTS idx_meter_readings_meter_ts
ON meter_readings(meter_id, ts_utc);
```

Reason:

- faster queries by meter and time,
- useful for 15-minute aggregation,
- small write overhead is acceptable because the write rate is low.

---

### Table: `meter_aggregates`

> **Already implemented on main branch**, ahead of `aggregator.py` itself
> (the schema exists in `utility/db_meters.py`, but no program writes to it
> yet — see the learning-order status near the end of this document).

This table stores the locally-saved 15-minute aggregate for each meter,
before/regardless of whether it was successfully uploaded to the REST API.

```sql
CREATE TABLE IF NOT EXISTS meter_aggregates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    meter_id TEXT NOT NULL,
    window_start_utc TEXT NOT NULL,
    window_end_utc TEXT NOT NULL,
    number_of_readings INTEGER NOT NULL CHECK (number_of_readings >= 0),
    first_energy_kwh REAL NOT NULL CHECK (first_energy_kwh >= 0),
    last_energy_kwh REAL NOT NULL CHECK (last_energy_kwh >= 0),
    energy_delta_kwh REAL NOT NULL CHECK (energy_delta_kwh >= 0),
    average_power_w REAL NOT NULL CHECK (average_power_w >= 0),
    created_at_utc TEXT NOT NULL,

    FOREIGN KEY (meter_id)
        REFERENCES meters(meter_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    -- Prevents duplicate aggregates if the aggregator ever runs twice for
    -- the same window. This is also how upload idempotency is enforced —
    -- see "REST API upload" below and doc/backend_php_project_plan.md.
    UNIQUE (meter_id, window_start_utc, window_end_utc)
);

CREATE INDEX IF NOT EXISTS idx_meter_aggregates_meter_window
ON meter_aggregates(meter_id, window_start_utc, window_end_utc);
```

---

## Aggregation logic

Every 15 minutes, a second program should read the raw values and calculate an aggregate.

For cumulative energy counters, do not average the `energy_kwh` values directly.

Instead:

```text
delta_kWh = last_energy_kWh - first_energy_kWh
```

For a 15-minute window:

```text
15 minutes = 0.25 hours
```

Average power can be calculated as:

```text
avg_power_W = delta_kWh * 1000 / 0.25
```

Example:

```text
first_energy_kWh = 1000.000
last_energy_kWh  = 1000.100

delta_kWh = 0.100

avg_power_W = 0.100 * 1000 / 0.25
avg_power_W = 400 W
```

---

## REST API upload

The aggregator should:

1. calculate the 15-minute aggregate,
2. save it locally,
3. send it to the REST API using HTTP POST,
4. include an idempotency key.

Example idempotency key:

```text
meter_id:window_start_utc:window_end_utc
```

Example:

```text
main-meter:2026-06-27T10:00:00+00:00:2026-06-27T10:15:00+00:00
```

This allows the server to detect duplicate uploads if the Raspberry Pi retries.

> **Backend design decided (not yet built on the Pi side):** the receiving
> server is a separate PHP + MySQL project — see
> `doc/backend_php_project_plan.md` for the full design. Key points that
> affect what `aggregator.py` will need to send later:
>
> - The POST body is the aggregate row itself (`meter_id`,
>   `window_start_utc`, `window_end_utc`, `number_of_readings`,
>   `first_energy_kwh`, `last_energy_kwh`, `energy_delta_kwh`,
>   `average_power_w`) as JSON — there is no separate `idempotency_key`
>   field in the payload. Instead, the backend enforces a
>   `UNIQUE (meter_id, window_start_utc, window_end_utc)` constraint on its
>   own table, so a retried POST with the same window simply fails the
>   insert harmlessly instead of creating a duplicate row. The
>   `meter_id:window_start_utc:window_end_utc` string above is still useful
>   as a human-readable log line, just not as a payload field.
> - Auth is a Bearer token: `Authorization: Bearer <IOT_API_TOKEN>`.
> - The endpoint returns `201` for a newly stored aggregate and `200` for
>   one that already existed (both count as a successful upload from the
>   Pi's point of view).

---

## Logging

The Python programs should log to stdout/stderr.

When run by systemd, these logs should be visible with:

```bash
journalctl -u iot-meter-poll.service -f
journalctl -u iot-meter-aggregate.service -f
```

No custom log file is required at the beginning.

If a meter read fails:

```text
failed read
    ↓
log error to journalctl
    ↓
optionally send error event to server
    ↓
do not insert into meter_readings
```

---

## Recommended Python project structure

Keep the project small at first:

```text
IoT-metering/
├── pyproject.toml
├── README.md
├── data/
│   └── metering.db
├── iot_metering/
│   ├── __init__.py
│   ├── db.py
│   ├── init_db.py
│   ├── poller.py
│   └── aggregator.py
└── systemd/
    ├── iot-meter-poll.service
    ├── iot-meter-poll.timer
    ├── iot-meter-aggregate.service
    └── iot-meter-aggregate.timer
```

Start with only:

```text
db.py
init_db.py
```

Then add the rest step by step.

> **Current structure (main branch, as actually laid out):**
>
> ```text
> IoT-metering/
> ├── pyproject.toml
> ├── README.md
> ├── main.py                   # plays the init_db.py role: creates schema
> │                             # + demo meters, prints enabled meters
> ├── poller.py                 # top-level, not inside a package yet
> ├── utility/
> │   ├── db_meters.py          # plays the db.py role: connection, schema,
> │   │                         # all DB helper functions
> │   └── logging_config.py     # journalctl-friendly logging setup
> └── data/
>     └── metering.db
> ```
>
> Differences from the target layout above:
> - No `iot_metering/` package yet — `poller.py` and `main.py` are
>   top-level, and DB code lives in `utility/` instead of `iot_metering/`.
> - No `init_db.py` — `main.py` currently does that job.
> - No `aggregator.py` yet on `main` (the `meter_aggregates` table schema
>   already exists in `utility/db_meters.py`, but nothing writes to it).
> - No `systemd/` folder yet — polling is still run manually, not on a
>   timer.

---

## `db.py` responsibility

`db.py` should contain:

- database path,
- SQLite connection function,
- schema definition,
- schema initialization function,
- common database helper functions.

Reason:

All database logic should be in one place.

If database settings need to change later, they should be changed in `db.py`, not in every file.

---

## `init_db.py` responsibility

`init_db.py` should:

- open the database,
- create the schema,
- insert initial/demo meter rows,
- be safe to run multiple times.

It should not read meters or perform polling.

---

## `poller.py` responsibility

`poller.py` should:

- read all enabled meters from `meters`,
- read each meter over Modbus TCP,
- during development, optionally generate mock readings,
- insert successful readings into `meter_readings`,
- log errors but not store failed readings in `meter_readings`.

It should be a one-shot program:

```text
start
read all enabled meters once
save readings
exit
```

systemd will run it every minute.

> **Current status (main branch):** implemented and working — reads
> enabled meters, generates a mock reading based on the previous
> `energy_kwh` value, and inserts it, committing after each meter.
>
> **Known bug to fix:** the `except Exception:` block in `poller.py`
> references a variable named `exc` in the log message, but the exception
> is never bound to that name (it should be `except Exception as exc:`).
> As written, a failed read raises `NameError` instead of logging the
> original error — masking the real failure reason.

---

## `aggregator.py` responsibility

`aggregator.py` should:

- run every 15 minutes,
- calculate the last completed 15-minute time window,
- read raw values from `meter_readings`,
- calculate `delta_kWh`,
- calculate average power,
- save the aggregate locally,
- send the aggregate to the REST API,
- log success or failure.

> **Current status (main branch): not yet implemented.** The
> `meter_aggregates` table it will write to already exists in the schema
> (see above), but this file doesn't exist on `main` yet. This is the next
> piece of Pi-side code to build, once the PHP backend
> (`doc/backend_php_project_plan.md`) is ready to receive uploads.

---

## systemd behavior

Use systemd timers instead of an infinite Python loop.

Reason:

```text
systemd timer
    ↓
runs one-shot Python service
    ↓
Python exits
    ↓
systemd runs it again next time
```

Advantages:

- easier logs,
- easier restart behavior,
- better integration with Raspberry Pi OS,
- no manual loop and sleep logic,
- `journalctl` works naturally.

---

## Polling timer

The poller should run every minute.

Example timer concept:

```text
OnCalendar=*-*-* *:*:00
```

---

## Aggregation timer

The aggregator should run every 15 minutes.

Example timer concept:

```text
OnCalendar=*-*-* *:00/15:30
```

The 30-second delay gives the poller time to write the latest minute sample.

---

## Security requirements

### Modbus TCP

Modbus TCP should stay on the local trusted network.

Do not expose Modbus TCP port 502 to the public internet.

### REST API

REST API communication should use HTTPS.

The API token should not be hardcoded in Python code.

Use environment variables or a protected `.env` file for secrets.

Example:

```text
IOT_API_URL=https://example.com/api/metering
IOT_API_TOKEN=secret-token
```

The `.env` file should have restricted permissions:

```bash
chmod 600 .env
```

### Logging

Do not log API tokens or secrets.

Logging meter IDs, timestamps and values is okay.

---

## Learning approach

The project should be developed step by step.

Suggested order, with current status on `main`:

```text
1.  Create uv project.                          [done]
2.  Create db.py.                               [done — as utility/db_meters.py]
3.  Create meters table.                        [done]
4.  Create meter_readings table.                [done]
5.  Insert demo meters.                         [done — via main.py]
6.  Read enabled meters from the database.      [done]
7.  Insert one manual reading.                  [done]
8.  Generate mock readings.                     [done — in poller.py]
9.  Poll all enabled meters.                     [done]
10. Add systemd timer for polling.               [not started]
11. Add aggregator.                              [not started — schema ready]
12. Add REST POST upload.                        [backend designed, Pi side
                                                   not started — see
                                                   doc/backend_php_project_plan.md]
13. Replace mock reader with real Modbus TCP.    [not started]
```

Each step should include:

- explanation,
- commented code,
- test command,
- expected output,
- short review of what was learned.

---

## Current design preferences

Please follow these preferences:

1. Do not put mock-generation fields in the `meters` table.
2. Do not store failed readings in `meter_readings`.
3. Communication will always be Modbus TCP in production.
4. Keep the database schema simple at the beginning.
5. Use many comments in the code.
6. Explain why each piece exists.
7. Push back if a design idea is not logical or may cause problems later.
8. Prefer learning and understanding over producing a large final code dump.
