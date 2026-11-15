# Migration: new Pi payload (import/export + 3-phase power)

A runbook for the change from the old `energy_consumption_*` payload to the
import/export + power payload. Written so the whole thing can be redone from
scratch on a clean machine.

**Status:** steps 1–5 written up. Step 6 is filled in when completed.

Step 3 was rewritten after the "raw data only, no deltas" decision — it no
longer derives anything.

---

## Why

The Pi's uploader changed what it sends. Old payload:

```json
{
  "device_id": "pi-01",
  "meter_id": "mock-meter-001",
  "ts_utc": "2026-08-07T10:00:00+00:00",
  "energy_consumption_now_kwh": 1000.42,
  "energy_consumption_last_kwh": 1000.0,
  "energy_consumption_delta_kwh": 0.42
}
```

New payload:

```json
{
  "device_id": "gateway-001-abcd1234",
  "ts_utc": "2026-11-15T18:05:11+00:00",
  "meter_id": "meter-001",
  "import_kwh": 0.24399998784065247,
  "export_kwh": 0.0,
  "power_l1_w": 20.091312408447266,
  "power_l2_w": 0.0,
  "power_l3_w": 0.0
}
```

Three changes at once:

1. **Energy split by direction** — `import_kwh` / `export_kwh` where there was
   one value. For a solar site, export is the interesting half.
2. **The `now`/`last`/`delta` triplet is gone.** The Pi no longer subtracts.
3. **Instantaneous power arrived** — three phases, in watts. A *rate*, not an
   accumulation, so it can never share an axis with kWh.

Sending the new payload at the old endpoint returns:

```json
{"error":"Invalid payload.","details":[
  "energy_consumption_now_kwh must be a non-negative number.",
  "energy_consumption_last_kwh must be a number.",
  "energy_consumption_delta_kwh must be a non-negative number."]}
```

`device_id`, `meter_id` and `ts_utc` still validate; everything else is new.

## Decisions taken

| Question | Decision | Consequence |
|---|---|---|
| Is `import_kwh` cumulative or per-interval? | **Cumulative** — a lifetime totalizer | Stored raw, exactly as the meter reports it. |
| How to treat `export_kwh`? | **Store, validate, return** | Column, ingest validation and API output. |
| Existing rows and the old payload shape? | **Drop and start fresh** | No migration SQL, no dual-shape ingest. Local test data only; nothing deployed to Plesk yet. |
| Derive per-interval energy? | **No — raw data only** | Nothing is computed anywhere. No delta helper, no lookback query, no interval concept. The chart draws measurements, not arithmetic. |
| What goes on the graph? | **Two stacked charts** | Power (W) above, energy counters (kWh) below. They cannot share a y-axis: one is a rate, the other an accumulation. |
| Does "all data" drop the date range? | **No** | Every stored column comes back raw, but `from`/`to` and the 31-day cap stay — an unbounded range would pull the whole history into memory and across the wire. |

Why storing raw counters rather than deltas: a raw counter can always be
re-derived into deltas later, including after a bug is fixed. A stored delta
cannot be un-mixed back into a counter if the arithmetic was ever wrong. The
raw counter is the more durable thing to keep, whether or not anything
currently derives from it.

## Plan

| Step | File | What |
|---|---|---|
| 1 | `db/schema.sql` | Replace the three `energy_*` columns with five new ones |
| 2 | `public/ingest.php` | New validation and `INSERT` |
| 3 | `src/meter-readings.php` | `fetch_meter_slots()` becomes `fetch_meter_readings()` — a plain row fetch |
| 4 | `public/v1/get-meter-data.php` | `readings` instead of `slots`; the `unit` field splits |
| 5 | `public/graph.js`, `public/dashboard.js` | Two stacked charts with linked zoom; table columns |
| 6 | `README.md`, `docs/`, schema comments | Documentation |

Steps 1–4 are small. Step 5 now carries the work: two ECharts instances
instead of one, and a y-axis that has to be told to fit the data rather than
include zero.

---

## Prerequisites

XAMPP on Windows, PowerShell from the repository root.

```powershell
# mysql.exe on PATH for this session only
$env:Path += ";C:\xampp\mysql\bin"

# XAMPP's root password is blank by default - no -p flag anywhere
mysql.exe -u root -e "SELECT 1;"
```

If that prints `1`, every command below works as written. If it asks for a
password, see "Troubleshooting → forgotten passwords".

---

## Step 1 — schema

### Edit `db/schema.sql`

In the `meter_readings` block, replace these four lines:

```sql
	-- exact decimal number: 12 digits total, 6 of them after the decimal point
	energy_now_kwh DECIMAL(12,6) NOT NULL,
	energy_last_kwh DECIMAL(12,6) NOT NULL,
	energy_delta_kwh DECIMAL(12,6) NOT NULL,
```

with:

```sql
	/*
	 * Cumulative lifetime counters, stored exactly as the meter's registers
	 * report them. Nothing derived is ever written here: per-interval energy
	 * is computed at READ time by subtracting consecutive rows.
	 *
	 * That direction matters. A raw counter can always be re-derived into
	 * deltas, including after a bug is fixed. A stored delta cannot be
	 * un-mixed back into a counter if the arithmetic was ever wrong.
	 *
	 * DECIMAL, not FLOAT: 12 digits total, 6 after the point. Exact, so the
	 * subtraction in step 3 cannot accumulate rounding drift.
	 */
	import_kwh DECIMAL(12,6) NOT NULL,
	export_kwh DECIMAL(12,6) NOT NULL,

	/*
	 * Instantaneous power per phase, in watts. This is a RATE, not an
	 * accumulation: it describes the instant of the reading, not the span
	 * since the previous one. So it is never summed — averaging is the only
	 * honest aggregation — and it must never share an axis with kWh.
	 *
	 * Three decimals is milliwatt resolution, finer than any real meter.
	 */
	power_l1_w DECIMAL(10,3) NOT NULL,
	power_l2_w DECIMAL(10,3) NOT NULL,
	power_l3_w DECIMAL(10,3) NOT NULL,
```

The `UNIQUE KEY`, its comments, the foreign key and the `ENGINE=` line are
untouched. Keep the file's tab indentation.

Notes:

- All three `energy_*` columns go. `energy_last_kwh` was the previous row's
  counter and `energy_delta_kwh` the difference — both always derivable from
  the counter itself, so nothing is lost. This also retires the "a meter's
  first reading sends `null` and gets rejected with 400" bug, since there is
  no `last` field any more.
- `0.24399998784065247` is exactly float32 `0.244` widened to float64. The
  trailing digits are representation noise from the Modbus read, not
  measurement. `DECIMAL(12,6)` rounds it to `0.244000`, the honest value, and
  the noise never compounds across a subtraction.
- `DECIMAL(12,6)` caps a counter at 999,999.999999 kWh ≈ 1 GWh.

### Apply

The local database was still the *original* two-table design
(`meters` + `meter_aggregates`, no `devices`), so it was replaced wholesale
rather than migrated:

```powershell
# Remove the stale database entirely
mysql.exe -u root -e "DROP DATABASE iot_metering;"

# Recreate all three tables from the file
Get-Content db\schema.sql | mysql.exe -u root

# Register the gateway FIRST, then the meter that belongs to it
mysql.exe -u root -e "INSERT INTO iot_metering.devices (device_id, name, description, created_at_utc) VALUES ('gateway-001-abcd1234', 'Gateway 001', 'Solar site', UTC_TIMESTAMP());"

mysql.exe -u root -e "INSERT INTO iot_metering.meters (meter_id, device_id, name, description, created_at_utc) VALUES ('meter-001', 'gateway-001-abcd1234', 'Main meter', 'Import/export + 3-phase power', UTC_TIMESTAMP());"
```

Order matters in the last two: the meter's foreign key points at the device, so
inserting the meter first gives **errno 1452**.

### Verify

```powershell
mysql.exe -u root -e "SHOW TABLES FROM iot_metering;"
mysql.exe -u root -e "DESCRIBE iot_metering.meter_readings;"
```

Expect three tables — `devices`, `meters`, `meter_readings` — and the readings
table carrying `import_kwh`, `export_kwh`, `power_l1_w/l2/l3` with no `energy_*`
column.

Then the real test, which goes through the application's own config and
credentials instead of root:

```powershell
php -r "require 'src/db.php'; print_r(db()->query('SELECT * FROM meters')->fetchAll());"
```

Printing the `meter-001` row exercises `DB_HOST`, `DB_NAME`, `DB_USER`,
`DB_PASS` and the new schema in one shot.

---

## Step 2 — `public/ingest.php`

Two edits. Everything above validation — method, rate limit, authentication,
JSON body — is untouched.

### Edit 1: validation

Replace the comment and the three `energy_consumption_*` blocks with:

```php
// JSON numbers arrive as int (0, 1) or float (0.1) depending on how they
// were written — accept both; reject strings ("0.1").
//
// Two loops, not one, because the two groups have DIFFERENT rules. That
// difference is the physics, so it's worth seeing in the code shape.

// Cumulative counters. A totalizer can never be negative, and it can never
// legitimately decrease either — but "decreasing" needs the previous row to
// detect, so that belongs in step 3, not here.
foreach (['import_kwh', 'export_kwh'] as $field) {
    $value = $payload[$field] ?? null;

    if (!(is_float($value) || is_int($value)) || $value < 0) {
        $errors[] = "{$field} must be a non-negative number.";
    }
}

// Instantaneous power, and NEGATIVE IS VALID here. On a solar site a phase
// that is exporting reads negative watts — that's the inverter pushing back
// into the grid, not an error. Rejecting it would silently discard exactly
// the data this project exists to measure.
foreach (['power_l1_w', 'power_l2_w', 'power_l3_w'] as $field) {
    $value = $payload[$field] ?? null;

    if (!(is_float($value) || is_int($value))) {
        $errors[] = "{$field} must be a number.";
    }
}
```

Five fields, two rules, no copy-paste. `$errors` still collects every problem
before answering once, so a client fixing its payload gets the full list rather
than one complaint per round trip.

The asymmetry is the point: **counters are non-negative, power is signed.**

### Edit 2: the INSERT

Replace the `$sql = 'INSERT INTO meter_readings …'` assignment with:

```php
$sql = 'INSERT INTO meter_readings
            (
                meter_id,
                device_id,
                pi_time_utc,
                import_kwh,
                export_kwh,
                power_l1_w,
                power_l2_w,
                power_l3_w
            )
        VALUES
            (
                :meter_id,
                :device_id,
                :pi_time_utc,
                :import_kwh,
                :export_kwh,
                :power_l1_w,
                :power_l2_w,
                :power_l3_w
            )';
```

and the `->execute([...])` array with:

```php
    db()->prepare($sql)->execute([
        ':meter_id'    => $meterId,
        ':device_id'   => $deviceId,
        ':pi_time_utc' => $piTimeUtc,

        // Read straight from $payload: validation above already proved these
        // keys exist and hold numbers, and respond() exits on failure — so
        // there is no path to here with a missing or bad value.
        ':import_kwh'  => $payload['import_kwh'],
        ':export_kwh'  => $payload['export_kwh'],
        ':power_l1_w'  => $payload['power_l1_w'],
        ':power_l2_w'  => $payload['power_l2_w'],
        ':power_l3_w'  => $payload['power_l3_w'],
    ]);
```

### Stale comment to fix while in the file

The `1062` branch below says *"Duplicate uq_meter_window"*. That index has not
existed for two redesigns — it is `uq_meter_readings (device_id, meter_id,
pi_time_utc)` now.

### Test

```powershell
php -S 127.0.0.1:8000 -t public
```

In a second terminal:

```powershell
$WRITE = "<API_TOKEN_INGEST from .env>"

@'
{
  "device_id": "gateway-001-abcd1234",
  "ts_utc": "2026-11-15T18:05:11+00:00",
  "meter_id": "meter-001",
  "import_kwh": 0.24399998784065247,
  "export_kwh": 0.0,
  "power_l1_w": 20.091312408447266,
  "power_l2_w": 0.0,
  "power_l3_w": 0.0
}
'@ | Set-Content -Encoding ascii payload.json

curl.exe -i -X POST "http://127.0.0.1:8000/ingest.php" -H "Authorization: Bearer $WRITE" -H "Content-Type: application/json" --data-binary "@payload.json"
```

`-Encoding ascii` matters: Windows PowerShell's older `utf8` prepends a
byte-order mark, which makes the body invalid JSON.

Expect `201 {"status":"created"}`, and `200 {"status":"already recorded"}` on an
exact repeat — the unique key still provides idempotency.

Four more cases:

```powershell
# Negative power must be ACCEPTED (new ts_utc so it's a new row)
curl.exe -i -X POST "http://127.0.0.1:8000/ingest.php" -H "Authorization: Bearer $WRITE" -H "Content-Type: application/json" -d '{"device_id":"gateway-001-abcd1234","ts_utc":"2026-11-15T18:10:11+00:00","meter_id":"meter-001","import_kwh":0.25,"export_kwh":0.01,"power_l1_w":-1500.5,"power_l2_w":0.0,"power_l3_w":0.0}'

# Negative counter must be REJECTED -> 400
curl.exe -i -X POST "http://127.0.0.1:8000/ingest.php" -H "Authorization: Bearer $WRITE" -H "Content-Type: application/json" -d '{"device_id":"gateway-001-abcd1234","ts_utc":"2026-11-15T18:15:11+00:00","meter_id":"meter-001","import_kwh":-1,"export_kwh":0.0,"power_l1_w":0.0,"power_l2_w":0.0,"power_l3_w":0.0}'

# The OLD payload must now be rejected, listing all five missing fields
curl.exe -i -X POST "http://127.0.0.1:8000/ingest.php" -H "Authorization: Bearer $WRITE" -H "Content-Type: application/json" -d '{"device_id":"gateway-001-abcd1234","ts_utc":"2026-11-15T18:20:11+00:00","meter_id":"meter-001","energy_consumption_now_kwh":1,"energy_consumption_last_kwh":0,"energy_consumption_delta_kwh":1}'

# An unregistered meter is still 422, not 400
curl.exe -i -X POST "http://127.0.0.1:8000/ingest.php" -H "Authorization: Bearer $WRITE" -H "Content-Type: application/json" -d '{"device_id":"gateway-001-abcd1234","ts_utc":"2026-11-15T18:25:11+00:00","meter_id":"ghost","import_kwh":1,"export_kwh":0,"power_l1_w":0,"power_l2_w":0,"power_l3_w":0}'
```

### Verify what landed

```powershell
mysql.exe -u root -e "SELECT pi_time_utc, import_kwh, export_kwh, power_l1_w FROM iot_metering.meter_readings ORDER BY pi_time_utc;"
```

The first row is the interesting one: `import_kwh` should read **`0.244000`**,
not `0.243999…`. That is `DECIMAL(12,6)` rounding the float32 noise away on the
way in — exactly what is wanted before step 3 starts subtracting two of these
from each other. `power_l1_w` reads `20.091`.

## Step 3 — `src/meter-readings.php`

**Raw data only.** Nothing is derived: each row goes out exactly as the meter
reported it, so the chart draws measurements rather than arithmetic.

An earlier draft of this step derived per-interval energy by subtracting
consecutive counters. That was dropped — see the decisions table. What follows
replaces it entirely.

### Replace the whole of `fetch_meter_slots()` with

```php
/**
 * Raw readings for one meter over a half-open range [start, end).
 *
 * Nothing is derived here. Each row goes out exactly as the meter reported
 * it, so the chart draws measurements rather than arithmetic.
 *
 * The loop is not business logic — it is the storage-format-to-wire-format
 * boundary, and it does exactly two jobs:
 *
 *   1. MySQL DATETIME carries no timezone, so format_utc_atom() re-attaches
 *      the explicit +00:00 the client needs.
 *   2. PDO returns DECIMAL columns as STRINGS ("0.244000"). Casting to float
 *      means the JSON holds numbers, so JavaScript can plot them without
 *      calling Number() on every value.
 *
 * @return array<int, array<string, mixed>>
 */
function fetch_meter_readings(
    string $meterId,
    DateTimeImmutable $rangeStart,
    DateTimeImmutable $rangeEnd
): array {
    $rows = db_fetch_all(
        'SELECT pi_time_utc,
                import_kwh, export_kwh,
                power_l1_w, power_l2_w, power_l3_w
         FROM meter_readings
         WHERE meter_id = :meter_id
           AND pi_time_utc >= :range_start
           AND pi_time_utc <  :range_end
         ORDER BY pi_time_utc',
        [
            ':meter_id'    => $meterId,
            ':range_start' => $rangeStart->format('Y-m-d H:i:s'),
            ':range_end'   => $rangeEnd->format('Y-m-d H:i:s'),
        ]
    );

    $readings = [];
    foreach ($rows as $row) {
        $readings[] = [
            'timestamp'  => format_utc_atom($row['pi_time_utc']),
            'import_kwh' => (float) $row['import_kwh'],
            'export_kwh' => (float) $row['export_kwh'],
            'power_l1_w' => (float) $row['power_l1_w'],
            'power_l2_w' => (float) $row['power_l2_w'],
            'power_l3_w' => (float) $row['power_l3_w'],
        ];
    }

    return $readings;
}
```

`meter_exists()` and `fetch_meters()` are untouched.

### What the "no deltas" decision removed

| Gone | Why it had existed | Cost of removing it |
|---|---|---|
| A `counter_delta()` helper | Derived per-interval energy | None — nothing derives any more |
| The lookback query | Seeded the first interval's delta from the reading before the range | **One fewer database query per request.** It only ever existed for the delta. |
| `start_timestamp` / `end_timestamp` | A slot was an interval | Replaced by a single `timestamp`. A reading *is* a point in time; calling it an interval was always a small fiction. |
| The counter-decrease decision | Negative deltas after a meter swap or cleared register | Moot. A reset now shows as a visible step down in the line, which is arguably more honest than a `null` gap. |

The rename matters for honesty: `slots` meant intervals and there are none now.
One `timestamp` per reading says what the data actually is.

### New reading shape

```php
[
    'timestamp'  => '2026-11-15T19:30:00+00:00',
    'import_kwh' => 1.6,     // raw cumulative counter
    'export_kwh' => 0.15,    // raw cumulative counter
    'power_l1_w' => 250.0,   // instantaneous
    'power_l2_w' => 0.0,
    'power_l3_w' => 0.0,
]
```

### Test

Seed a few readings through the real endpoint first:

```powershell
$WRITE = "<API_TOKEN_INGEST from .env>"
$U = "http://127.0.0.1:8000/ingest.php"

curl.exe -s -X POST $U -H "Authorization: Bearer $WRITE" -H "Content-Type: application/json" -d '{"device_id":"gateway-001-abcd1234","ts_utc":"2026-11-15T19:00:00+00:00","meter_id":"meter-001","import_kwh":1.000,"export_kwh":0.100,"power_l1_w":230.5,"power_l2_w":0,"power_l3_w":0}'
curl.exe -s -X POST $U -H "Authorization: Bearer $WRITE" -H "Content-Type: application/json" -d '{"device_id":"gateway-001-abcd1234","ts_utc":"2026-11-15T19:15:00+00:00","meter_id":"meter-001","import_kwh":1.250,"export_kwh":0.100,"power_l1_w":240.0,"power_l2_w":0,"power_l3_w":0}'
curl.exe -s -X POST $U -H "Authorization: Bearer $WRITE" -H "Content-Type: application/json" -d '{"device_id":"gateway-001-abcd1234","ts_utc":"2026-11-15T19:30:00+00:00","meter_id":"meter-001","import_kwh":1.600,"export_kwh":0.150,"power_l1_w":250.0,"power_l2_w":0,"power_l3_w":0}'
```

Then call the function directly:

```powershell
php -r "require 'src/meter-readings.php'; print_r(fetch_meter_readings('meter-001', new DateTimeImmutable('2026-11-15', new DateTimeZone('UTC')), new DateTimeImmutable('2026-11-16', new DateTimeZone('UTC'))));"
```

Two things to check in the output:

- `timestamp` reads `2026-11-15T19:00:00+00:00` — with the offset, not the bare
  MySQL `2026-11-15 19:00:00`
- the numbers print as `0.244` / `20.091`, **not** as `'0.244000'` in quotes.
  Quotes mean a `(float)` cast was missed, and the chart would then be sorting
  and plotting strings.

## Step 4 — `public/v1/get-meter-data.php`

Three small edits. Method, rate limit, dual authentication, range parsing and
the 31-day cap are all untouched.

### Edit 1: the call and the response

Replace

```php
    $slots = fetch_meter_slots($meterId, $rangeStart, $rangeEnd);
```

with

```php
    $readings = fetch_meter_readings($meterId, $rangeStart, $rangeEnd);
```

then replace the final `respond(200, [...])` with

```php
respond(200, [
    'meter_id' => $meterId,
    'from'     => $from->format('Y-m-d'),
    'to'       => $to->format('Y-m-d'),
    'readings' => $readings,
]);
```

### Edit 2: `unit` goes away

Note what is missing: there is no `unit` field any more.

A single top-level `unit` cannot be right about this payload — half of it is
kWh and half is watts, so whatever value it held would be a false statement
about the other half. The alternative, a `units` map naming each field's unit,
would only restate information the field names already carry: `import_kwh` and
`power_l1_w` are suffixed with their units precisely so they are
self-describing.

Same call as earlier in this project, when the duplicated per-slot `unit` and
the `source: 'database'` flag were removed: do not ship a field that adds
nothing.

### Edit 3: the docblock is now wrong

At the top of the file this paragraph describes machinery that no longer
exists:

```php
 * Each slot's value is the energy measured BETWEEN two readings, so a
 * slot spans [previous reading, this reading). The first slot of a day
 * therefore needs the last reading of the previous day — see the
 * lookback query below.
```

Replace it with:

```php
 * Readings are returned exactly as stored — raw cumulative energy counters
 * plus instantaneous per-phase power. Nothing is derived, so there is no
 * lookback query and no interval arithmetic: one row in the database is one
 * entry in the response.
 *
 * Units live in the field names (import_kwh, power_l1_w), because one
 * response carries both kWh and watts and no single unit label could be
 * honest about both.
```

Also fix the contract line a few rows above, which still says `slots`:

```php
 *   200 range of readings (possibly empty) | 400 bad or missing input
```

While in the file, delete the dead commented-out code: the old
`require_bearer_token` line and the whole superseded `?date=` gate with its
`$dayStart` round-trip block. Both were replaced by the current Gate 4.

### Test

```powershell
$READ = "<API_TOKEN_GET_USAGE from .env>"

curl.exe -s "http://127.0.0.1:8000/v1/get-meter-data.php?date=2026-11-15&meter_id=meter-001" -H "Authorization: Bearer $READ"
```

Expect:

```json
{"meter_id":"meter-001","from":"2026-11-15","to":"2026-11-15",
 "readings":[{"timestamp":"2026-11-15T19:00:00+00:00","import_kwh":1,
 "export_kwh":0.1,"power_l1_w":230.5,"power_l2_w":0,"power_l3_w":0}]}
```

Check: `readings` not `slots`, no `unit` key, timestamps carrying `+00:00`,
numbers unquoted.

Error paths should be unchanged, since none of that code moved:

```powershell
curl.exe -s -o NUL -w "no token: %{http_code}`n" "http://127.0.0.1:8000/v1/get-meter-data.php?meter_id=meter-001"
curl.exe -s -o NUL -w "unknown meter: %{http_code}`n" "http://127.0.0.1:8000/v1/get-meter-data.php?meter_id=ghost" -H "Authorization: Bearer $READ"
curl.exe -s -o NUL -w "no meter_id: %{http_code}`n" "http://127.0.0.1:8000/v1/get-meter-data.php" -H "Authorization: Bearer $READ"
curl.exe -s "http://127.0.0.1:8000/v1/get-meter-data.php?from=2026-01-01&to=2026-11-15&meter_id=meter-001" -H "Authorization: Bearer $READ"
```

`401`, `404`, `400`, and the 31-day range error.

### Expect two things to break here — do not chase them

- `/graph.php` — `graph.js` still reads `payload.slots` and
  `meter_reading_delta_kwh`
- the dashboard table on `/index.php` — `dashboard.js` reads
  `responseData.slots` too

Both are step 5. The API is the contract and it is correct at this point; the
clients catch up next.

## Step 5 — `public/graph.php`, `public/graph.js`, `public/dashboard.js`

The largest step, in three parts.

### Edit locations

Line numbers are against the files **before any of these edits are applied**,
so work top-down within a file or they will drift.

| File | Line(s) | What is there now | Replace with |
|---|---|---|---|
| `graph.php` | 42–43 | the two `--series-*` tokens | the five power/energy tokens |
| `graph.php` | 54–55 | the two dark `--series-*` tokens | the five dark tokens |
| `graph.php` | 77–79 | the `#chart` height rule | `#chart-power, #chart-energy` + `.chart-label` |
| `graph.php` | 95–96 | "Bars: energy per interval…" | the new subtitle text |
| `graph.php` | 102 | `value="mock-meter-001"` | `value="meter-001"` — the old meter no longer exists |
| `graph.php` | 113 | `href="/logout.php"` | `href="/index.php?logout=1"` |
| `graph.php` | 116 | `<div id="chart"></div>` | two labelled chart divs |
| `graph.js` | all | the bars + cumulative chart | whole-file replacement, below |
| `index.php` | 189–192 | the four `<th>` cells | six `<th>` cells |
| `dashboard.js` | 57–93 | `renderSlots()` | `renderReadings()` |
| `dashboard.js` | 230 | `!Array.isArray(responseData.slots)` | `responseData.readings` |
| `dashboard.js` | 232 | `'…does not contain a slots array.'` | `'…a readings array.'` |
| `dashboard.js` | 236 | `renderSlots(responseData.slots);` | `renderReadings(responseData.readings);` |
| `dashboard.js` | 240 | `responseData.slots.length === 0` | `responseData.readings.length` |
| `dashboard.js` | 248 | `${responseData.slots.length}` | `${responseData.readings.length}` |

Two things that look like they need changing and do **not**:

- `graph.php` line 117, `<p id="status"></p>` — stays put, below both charts.
- `dashboard.js` line 21, `const slotsElement = …('dashboard-slots')`, and
  `index.php` line 197, `<tbody id="dashboard-slots">`. That is an element id,
  not a response key. Renaming it would mean editing both files for no gain.

Confirm nothing was missed:

```powershell
# Should print ONLY lines 21, 62 and 89 - all of them slotsElement
Select-String -Path public\dashboard.js -Pattern "slots|slot\.|renderSlots"

# Should print nothing at all
Select-String -Path public\graph.php -Pattern "series-cumulative|series-interval|logout.php|id=.chart.>"

# Six <th> cells in the dashboard table, matching six createTextCell calls
Select-String -Path public\index.php -Pattern "<th>"
```

The `slot\.` alternative matters. A pattern of just `slots|renderSlots` misses
every line reading `slot.start_timestamp` or `slot.meter_reading_delta_kwh` —
singular — which is exactly the body of the function being replaced.

**The two `dashboard.js` edits must land together.** Renaming the function to
`renderReadings` breaks its only call site at line 236, and updating line 236
without replacing the function silently passes the new array into code that
reads the old keys: every cell falls through to `''` and the table renders
empty columns under the new header. Neither half works alone.

### 5a — `public/graph.php`

**Colour tokens.** In `:root`, replace the two `--series-*` lines with five:

```css
            --power-l1:      #2a78d6;  /* blue   */
            --power-l2:      #eb6834;  /* orange */
            --power-l3:      #4a3aa7;  /* violet */
            --energy-import: #e34948;  /* red   — drawn from the grid */
            --energy-export: #1baf7a;  /* green — sent back to the grid */
```

and in the dark `@media` block, replace its two with:

```css
                --power-l1:      #3987e5;
                --power-l2:      #d95926;
                --power-l3:      #9085e9;
                --energy-import: #e66767;
                --energy-export: #199e70;
```

Import red / export green is deliberate — taking from the grid versus giving
back. The three power hues avoid red and green entirely so that pairing stays
unique to the energy chart.

**CSS.** Replace the `#chart` rule with:

```css
        /* Each chart needs an explicit height: ECharts measures its
           container, and a div with no content has height 0. */
        #chart-power, #chart-energy { width: 100%; height: 17rem; }
        .chart-label { font-size: .8rem; color: var(--text-secondary);
                       margin: .8rem 0 .2rem; }
```

**Subtitle:**

```html
<p id="subtitle" style="color: var(--text-secondary); margin: 0;">
    Readings exactly as the meter reported them. Top: instantaneous power per
    phase in watts, where negative means that phase is exporting. Bottom:
    cumulative import and export counters in kWh. Times in UTC; zooming
    either chart moves both.
</p>
```

**Body.** Replace `<div id="chart"></div>` with:

```html
<p class="chart-label">Power per phase (W) — instantaneous</p>
<div id="chart-power"></div>

<p class="chart-label">Energy counters (kWh) — cumulative meter totals</p>
<div id="chart-energy"></div>
```

**Fix the dead sign-out link** while in the file — `/logout.php` has never
existed:

```html
    <a href="/index.php?logout=1">Sign out</a>
```

### 5b — `public/graph.js`

Replace the whole file. Three decisions carry the step:

- **`scale: true` on the energy axis only.** ECharts includes zero by default,
  which is correct for power (0 W is a real baseline, negative means exporting)
  but ruinous for a lifetime counter at 1000.2 → 1000.6, which would render as
  a dead-flat stroke against a 0..1000 axis. The two charts genuinely want
  different axis behaviour, and this is the only real asymmetry between them.
- **`echarts.connect([powerChart, energyChart])`.** One line, and the charts
  share zoom and crosshair: drag either slider and both move.
- **Full option once, then data only.** The 15-second refresh sends just
  `series`, never `dataZoom`. Including `dataZoom` in a merge snaps start/end
  back to 0-100 and discards the user's zoom every tick.

No bars anywhere: bars meant per-interval energy, which no longer exists.

Full file:

```javascript
'use strict';

/*
 * graph.js — two charts, one time axis.
 *
 *   top:    instantaneous power per phase, in watts
 *   bottom: cumulative energy counters (import / export), in kWh
 *
 * Two charts rather than two y-axes on one chart. Watts are a RATE and kWh an
 * ACCUMULATION; putting them on one plot with independent scales invents a
 * correlation the data does not contain. Separate plots, one shared x-axis,
 * linked zoom — the honest form.
 *
 * Nothing is computed here. The API returns readings exactly as stored and
 * every line is drawn straight from them.
 */

// ── Colours: read the CSS tokens so the palette is defined once ────────
const css = getComputedStyle(document.documentElement);
const token = (name) => css.getPropertyValue(name).trim();

const COLOR = {
    l1:           token('--power-l1'),
    l2:           token('--power-l2'),
    l3:           token('--power-l3'),
    energyImport: token('--energy-import'),
    energyExport: token('--energy-export'),
    text:         token('--text-primary'),
    textMuted:    token('--text-secondary'),
    grid:         token('--grid'),
};

const meterInput = document.getElementById('meter');
const fromInput  = document.getElementById('from');
const toInput    = document.getElementById('to');
const loadButton = document.getElementById('load');
const statusEl   = document.getElementById('status');

const powerChart  = echarts.init(document.getElementById('chart-power'),  null, { renderer: 'canvas' });
const energyChart = echarts.init(document.getElementById('chart-energy'), null, { renderer: 'canvas' });

// One call, and the two charts share zoom AND crosshair: dragging the slider
// on either moves both, so the eye is always comparing the same time window.
echarts.connect([powerChart, energyChart]);

/** Minimal HTML escape — seriesName lands inside a tooltip HTML string. */
function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c]);
}

/**
 * API readings -> [timestampMs, value] pairs per series.
 *
 * Date.parse understands ISO 8601 with an offset and returns an absolute
 * instant, so no timezone ambiguity can creep in.
 */
function toSeriesData(readings) {
    const at = (reading) => Date.parse(reading.timestamp);

    return {
        l1:     readings.map((r) => [at(r), r.power_l1_w]),
        l2:     readings.map((r) => [at(r), r.power_l2_w]),
        l3:     readings.map((r) => [at(r), r.power_l3_w]),
        import: readings.map((r) => [at(r), r.import_kwh]),
        export: readings.map((r) => [at(r), r.export_kwh]),
    };
}

/** One line series. No smoothing: a curve would invent unmeasured values. */
function lineSeries(name, data, color) {
    return {
        name,
        type: 'line',
        data,
        lineStyle: { color, width: 2 },
        itemStyle: { color },
        smooth: false,
        showSymbol: false,
        symbolSize: 7,
        // Thins the drawn points at wide ranges while keeping the shape.
        // Affects rendering only, never the data.
        sampling: 'lttb',
        emphasis: { focus: 'series' },
    };
}

/** Tooltip formatter bound to a unit and a decimal count. */
function tooltipFormatter(unit, digits) {
    return function (params) {
        if (!params.length) { return ''; }

        const when = echarts.time.format(
            params[0].value[0], '{yyyy}-{MM}-{dd} {HH}:{mm}', true   // true = UTC
        );

        const rows = params.map((p) => {
            const stroke =
                `<span style="display:inline-block;width:14px;height:2px;` +
                `background:${p.color};vertical-align:middle;margin-right:6px"></span>`;

            return `${stroke}<strong>${Number(p.value[1]).toFixed(digits)} ${unit}</strong>` +
                   `<span style="opacity:.7"> ${escapeHtml(p.seriesName)}</span>`;
        });

        return `<div style="font-weight:600">${when} UTC</div>${rows.join('<br>')}`;
    };
}

/**
 * Everything both charts share. scaleY is the one real difference.
 */
function baseOption(axisName, scaleY, unit, digits) {
    return {
        // The DB and API are UTC. Without this ECharts labels the axis in the
        // viewer's local timezone while the tooltip says UTC.
        useUTC: true,

        // Chart chrome wears TEXT colours, never series colours.
        textStyle: { color: COLOR.textMuted },

        grid: { left: 64, right: 24, top: 34, bottom: 56, containLabel: false },

        legend: { top: 4, textStyle: { color: COLOR.text } },

        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'line', lineStyle: { color: COLOR.grid } },
            formatter: tooltipFormatter(unit, digits),
        },

        xAxis: {
            type: 'time',
            axisLine:  { lineStyle: { color: COLOR.grid } },
            axisLabel: { color: COLOR.textMuted, hideOverlap: true },
            splitLine: { show: false },
        },

        yAxis: {
            type: 'value',
            name: axisName,
            nameTextStyle: { color: COLOR.textMuted, align: 'right' },
            axisLabel: { color: COLOR.textMuted },
            splitLine: { lineStyle: { color: COLOR.grid, type: 'dashed' } },

            // THE setting that decides whether the energy chart is readable.
            // ECharts includes zero by default, which is right for power
            // (0 W is a real baseline and negative means exporting) but
            // ruinous for a lifetime counter sitting at 1000.2 -> 1000.6:
            // the line would render as a dead-flat stroke against a 0..1000
            // axis. scale:true fits the axis to the data instead.
            scale: scaleY,
        },

        dataZoom: [
            { type: 'inside', xAxisIndex: 0 },
            {
                type: 'slider', xAxisIndex: 0, bottom: 6, height: 18,
                borderColor: COLOR.grid,
                textStyle: { color: COLOR.textMuted },
            },
        ],
    };
}

// The full option is set ONCE. After that only series data is sent, because
// setOption merges — and including dataZoom in a refresh would snap
// start/end back to 0-100 and throw away the user's zoom every 15 seconds.
let chartsInitialised = false;

function drawCharts(readings) {
    const data = toSeriesData(readings);

    const powerSeries = [
        lineSeries('L1', data.l1, COLOR.l1),
        lineSeries('L2', data.l2, COLOR.l2),
        lineSeries('L3', data.l3, COLOR.l3),
    ];

    const energySeries = [
        lineSeries('Import', data.import, COLOR.energyImport),
        lineSeries('Export', data.export, COLOR.energyExport),
    ];

    if (!chartsInitialised) {
        powerChart.setOption({
            ...baseOption('W', false, 'W', 1),
            series: powerSeries,
        });

        energyChart.setOption({
            ...baseOption('kWh', true, 'kWh', 3),
            series: energySeries,
        });

        chartsInitialised = true;
        return;
    }

    // Series merge BY NAME, so name must be included.
    powerChart.setOption({
        series: powerSeries.map((s) => ({ name: s.name, data: s.data })),
    });

    energyChart.setOption({
        series: energySeries.map((s) => ({ name: s.name, data: s.data })),
    });
}

function setStatus(message, className) {
    statusEl.textContent = message;        // textContent, not innerHTML:
    statusEl.className = className || '';  // messages carry API text
}

/** "YYYY-MM-DD" for a Date, in UTC. */
function isoDate(date) {
    return date.toISOString().slice(0, 10);
}

/** Fetch one range of readings. Throws on any non-2xx. */
async function fetchReadings(meterId, from, to) {
    const url = new URL('/v1/get-meter-data.php', window.location.origin);
    url.searchParams.set('meter_id', meterId);
    url.searchParams.set('from', from);
    url.searchParams.set('to', to);

    const response = await fetch(url, {
        headers: { 'Accept': 'application/json' },
        // THIS is what carries the session cookie. No token in the browser.
        credentials: 'same-origin',
        cache: 'no-store',
    });

    // 401 = the session expired while this tab sat open. A fetch cannot follow
    // a redirect to a login page, so the page navigates itself.
    if (response.status === 401) {
        window.location.href = '/index.php';
        throw new Error('Session expired.');
    }

    // Parse first: the API sends errors as JSON too, and "Range too large"
    // beats "HTTP 400".
    let payload;
    try {
        payload = await response.json();
    } catch {
        throw new Error('Server returned invalid JSON (HTTP ' + response.status + ').');
    }

    if (!response.ok) {
        throw new Error(payload.error ?? ('HTTP ' + response.status));
    }
    if (!Array.isArray(payload.readings)) {
        throw new Error('Response has no readings array.');
    }

    return payload.readings;
}

/** Read the controls, fetch, redraw. */
async function loadCharts() {
    const meterId = meterInput.value.trim();
    const from = fromInput.value;
    const to = toInput.value;

    if (meterId === '' || from === '' || to === '') {
        setStatus('Meter ID, from and to are all required.', 'warn');
        return;
    }

    loadButton.disabled = true;
    setStatus('Loading…');

    try {
        const readings = await fetchReadings(meterId, from, to);

        drawCharts(readings);

        if (readings.length === 0) {
            setStatus('No readings for ' + meterId + ' between ' + from + ' and ' + to + '.');
        } else {
            const latest = readings[readings.length - 1];

            setStatus(
                readings.length + ' readings · latest import ' +
                latest.import_kwh.toFixed(3) + ' kWh · export ' +
                latest.export_kwh.toFixed(3) + ' kWh'
            );
        }
    } catch (error) {
        // Keep the previous charts on screen: a transient failure should not
        // wipe out what the reader was looking at.
        setStatus(error.message ?? 'Request failed.', 'warn');
    } finally {
        loadButton.disabled = false;
    }
}

// ── Boot ───────────────────────────────────────────────────────────────

// Default range: the last 7 days ending today, in UTC — the same clock as the
// data, so "today" means the same thing on both sides.
const now = new Date();
toInput.value = isoDate(now);
fromInput.value = isoDate(new Date(now.getTime() - 6 * 24 * 60 * 60 * 1000));

loadButton.addEventListener('click', function () {
    chartsInitialised = false;   // new query -> new view
    loadCharts();
});

window.addEventListener('resize', function () {
    powerChart.resize();
    energyChart.resize();
});

loadCharts();

// ── Live refresh ───────────────────────────────────────────────────────
// 15 seconds. setTimeout, NOT setInterval: the next request is scheduled only
// after the previous one finishes, so a slow network cannot stack requests.
const REFRESH_MS = 15000;
let refreshTimer = null;

function scheduleRefresh() {
    window.clearTimeout(refreshTimer);
    refreshTimer = window.setTimeout(refreshTick, REFRESH_MS);
}

async function refreshTick() {
    // A background tab gets throttled by the browser anyway; skipping the
    // request outright is honest about that and saves the server the work.
    if (!document.hidden) {
        await loadCharts();
    }

    scheduleRefresh();
}

document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
        window.clearTimeout(refreshTimer);
        refreshTick();
    }
});

scheduleRefresh();
```

### 5c — `public/dashboard.js` and the table header

The columns change, so this touches two files.

In `public/index.php`, replace the `<thead>` row:

```html
        <tr>
            <th>Time (UTC)</th>
            <th>Import (kWh)</th>
            <th>Export (kWh)</th>
            <th>L1 (W)</th>
            <th>L2 (W)</th>
            <th>L3 (W)</th>
        </tr>
```

In `public/dashboard.js`, replace `renderSlots()` with:

```javascript
/**
 * Replace the existing table rows with readings from the latest response.
 */
function renderReadings(readings) {
    // Remove rows from the previous successful response.
    slotsElement.replaceChildren();

    if (readings.length === 0) {
        tableElement.hidden = true;
        return;
    }

    for (const reading of readings) {
        const row = document.createElement('tr');

        // ?? '' and not || '': a power reading of 0 is a real value and must
        // survive. || would replace it with an empty cell.
        row.append(
            createTextCell(reading.timestamp ?? ''),
            createTextCell(reading.import_kwh ?? ''),
            createTextCell(reading.export_kwh ?? ''),
            createTextCell(reading.power_l1_w ?? ''),
            createTextCell(reading.power_l2_w ?? ''),
            createTextCell(reading.power_l3_w ?? '')
        );

        slotsElement.append(row);
    }

    tableElement.hidden = false;
}
```

Then inside `loadDashboard()`:

```javascript
        if (!Array.isArray(responseData.readings)) {
            throw new Error(
                'The API response does not contain a readings array.'
            );
        }

        renderReadings(responseData.readings);
```

and the two status messages below it that read `responseData.slots.length`
become `responseData.readings.length`.

### Test

```powershell
php -S 127.0.0.1:8000 -t public
```

Sign in at `http://127.0.0.1:8000/`, then open the graph.

- Two charts stacked, power above, energy below
- **The energy lines are not flat.** If they are, `scale: true` did not land.
- Drag the zoom slider on one chart — the other follows
- Hover — tooltip shows `W` with 1 decimal on top, `kWh` with 3 below
- Click a legend entry — that line toggles
- Zoom in, wait 15+ seconds — the zoom survives and the data updates
- A phase sending negative watts dips below the zero line rather than clipping
- Browser console clean
- On `/index.php`, paste the read token and load — six columns, and zeros
  showing as `0` rather than blank cells

## Step 6 — documentation

*To be filled in.* Outline:

- `db/schema.sql` design comments
- `README.md` payload example, and correct the DB user/host to match `.env`
- `docs/arhitecture-decisions.md`
- Remove the now-retired "first reading sends null" known issue

---

## Troubleshooting

Everything below was actually hit during this migration.

### `ERROR 1051 Unknown table 'iot_metering.meter_readings'`

Nothing to drop — harmless. `DROP TABLE IF EXISTS` would have passed silently.
In this case it meant the table never existed, because the database predated
the current schema.

### The database is an older design than `schema.sql`

`SHOW TABLES` returned `meters` and `meter_aggregates` with no `devices` —
the original two-table layout. A plain reload does **not** fix this:

- `devices` is created
- `meters` is **silently skipped**, because `CREATE TABLE IF NOT EXISTS` sees
  the old four-column one and leaves it alone
- `meter_readings` then fails with **errno 150**, because its foreign key
  targets `meters (device_id, meter_id)` and the old `meters` has neither a
  `device_id` column nor the `uq_meters_device_meter` key

A foreign key can only point at an existing unique index of matching type,
charset and collation. The fix was `DROP DATABASE` and reload.

Because of `IF NOT EXISTS`, always confirm with `DESCRIBE` rather than trusting
a silent reload.

### `ERROR 1045 Access denied for user 'x'@'localhost'`

In MySQL a user identity is the **pair** `user@host`, not the name.
`'iot_meter_backend'@'localhost'` and `'iot_meter_backend'@'127.0.0.1'` are two
different accounts with separate passwords and separate grants. A connection
arriving as `localhost` cannot see an account created for `127.0.0.1`.

List what exists:

```powershell
mysql.exe -u root -e "SELECT user, host FROM mysql.user ORDER BY user, host;"
mysql.exe -u root -e "SHOW GRANTS FOR 'iot_meter_backend'@'localhost';"
```

`DB_HOST` in `.env` must match the host half of an account that actually
exists. Then either align `.env` or create the account:

```powershell
mysql.exe -u root -e "CREATE USER IF NOT EXISTS 'iot_meter_backend'@'localhost' IDENTIFIED BY 'your-DB_PASS'; GRANT SELECT, INSERT ON iot_metering.* TO 'iot_meter_backend'@'localhost';"
```

`SELECT, INSERT` is everything the application needs — reads for the API,
inserts for ingest. No `UPDATE`, `DELETE` or `DROP`, so even a SQL-injection
bug that slipped past the prepared statements could not destroy data.

Grants survive `DROP DATABASE`: they live in `mysql.db` keyed by name, not
attached to the database object.

### Graph page blank: no lines, empty date boxes, no status text

The tell is the **date inputs being empty and the status line silent**.
`graph.js` fills both during boot, so if neither happened the script threw
before it got there — or never loaded at all. Blank charts alone would mean a
data problem; blank charts *plus* empty dates means the script died.

Most often this is `graph.php` updated while `graph.js` was not, or the
browser serving a cached copy of the old one. The old file begins its boot
section with:

```js
const chartElement = document.getElementById('chart');
const chart = echarts.init(chartElement, null, { renderer: 'canvas' });

const now = new Date();
toInput.value = isoDate(now);     // never reached
```

Step 5a removes `<div id="chart">`, so `chartElement` is null,
`echarts.init(null)` throws, and every line after it — the date defaults
included — never runs.

```powershell
Select-String -Path public\graph.js -Pattern "chart-power|chart-energy|connect"
```

- Nothing printed: the old `graph.js` is still on disk. Replace it.
- Lines printed: the file is correct and the browser cached the old one.
  Hard-reload with Ctrl+F5.

Then read the browser console (F12). The usual three:

| Console says | Cause |
|---|---|
| `Initialize failed: invalid dom` | old `graph.js`, as above |
| `404 /graph.js` | file not saved at `public\graph.js` |
| `echarts is not defined` | `/vendor/echarts.min.js` not loading |

For the 404 case the PHP server's own terminal output is more useful than the
browser: it names the exact path requested, where the browser only says "404".

### Graph loads but says "Unknown meter_id"

The Meter ID box defaults to whatever is hard-coded in `graph.php` line 102.
If the database was recreated in step 1, `mock-meter-001` no longer exists and
every load returns 404. Edit 5 of step 5a changes the default to `meter-001`;
until that is applied, typing the right ID into the box works too.

### Forgotten passwords

**MySQL root.** XAMPP ships it blank, so try `mysql.exe -u root` with no `-p`
first. If a password was set, phpMyAdmin must know it — look for
`$cfg['Servers'][$i]['password']` in `C:\xampp\phpMyAdmin\config.inc.php`.
To reset: stop MySQL in the XAMPP Control Panel, add `skip-grant-tables` under
`[mysqld]` in `C:\xampp\mysql\bin\my.ini`, start it, then:

```powershell
mysql.exe -u root -e "FLUSH PRIVILEGES; ALTER USER 'root'@'localhost' IDENTIFIED BY 'newpassword';"
```

`FLUSH PRIVILEGES` must come first — with `skip-grant-tables` the privilege
tables are not loaded and `ALTER USER` fails until they are. Then **remove the
`skip-grant-tables` line** and restart; leaving it in means the database
accepts any connection with no password.

**The app's DB password.** It is in `.env` as `DB_PASS`.

**The dashboard login.** Not recoverable — `.env` stores a `password_hash()`
output, which is deliberately one-way. Replace it:

```powershell
php -r "echo password_hash('your-new-password', PASSWORD_DEFAULT);"
```

Paste the whole `$2y$...` string into `DASHBOARD_PASSWORD_HASH`. No restart
needed; `config()` re-reads `.env` on the next request.

### PowerShell quoting

Use **single** quotes around passwords and hashes. Inside double quotes
PowerShell expands `$`, and a bcrypt hash is full of `$`. If a value contains
`$`, backtick or quotes, skip the `-e` one-liner and go interactive — the MySQL
client does its own quoting:

```powershell
mysql.exe -u root
```

Then paste the SQL at the prompt and `EXIT;`.

Also: `mysql < file.sql` is not supported in PowerShell. Use
`Get-Content file.sql | mysql.exe -u root`.

### Checks worth running after any step

```powershell
# Syntax-check every application PHP file
Get-ChildItem src,public -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }

# Start the dev server with the correct document root
php -S 127.0.0.1:8000 -t public
```
