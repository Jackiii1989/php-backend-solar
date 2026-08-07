<?php

/**
 * Ingest endpoint — receives per POST from the Pi.
 * Contract: 201 stored / 200 already stored (retry) / 400 bad payload /
 * 401 bad token / 405 wrong method / 422 unknown meter /
 * 429 rate limit exceeded / 500 unexpected server or database failure.
 */

declare(strict_types=1);


// includes
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/api-helper.php';
require_once __DIR__ . '/../src/rate-limit.php';


// ── Gate 1: HTTP method ─────────────────────────────────────────────────
// Reject incorrect methods before doing authentication, filesystem, or JSON
// processing work.
require_method('POST');


// ── Gate 2: rate limiting ───────────────────────────────────────────────
// Ingestion has its own endpoint name, so its counter is independent from
// dashboard requests to api-v1.
//
// A meter normally sends one aggregate every 15 minutes. Twenty requests per
// minute still permits retries and short bursts while restricting abuse.
require_rate_limit(
    'ingest',

    // Maximum ingestion requests per client during one window.
    // The code default remains 20 if the setting is absent.
    config_positive_int('RATE_LIMIT_INGEST_MAX_REQUESTS', 20),

    // Both endpoints use the same configurable window duration.
    // The code default remains 60 if the setting is absent.
    config_positive_int('RATE_LIMIT_WINDOW_SECONDS_INGEST', 60)
);


// ── Gate 3: authentication ──────────────────────────────────────────────
// Rate limiting runs before this check so invalid-token attempts also consume
// the client's allowance.
require_bearer_token(
    $_SERVER['HTTP_AUTHORIZATION'] ?? '',
    config('API_TOKEN_INGEST')
);


// ── Gate 4: JSON body ───────────────────────────────────────────────────
// Body parsing happens only after method, rate-limit, and authentication gates.
// This ingestion payload should be small. Reject bodies larger than 16 KiB.
$payload = read_json_body(16_384);

// Collect ALL problems, then answer once — a client fixing its payload
// wants the full list, not one complaint per round trip.
$errors = [];



// ── Validation ───────────────────────────────────────────────────────────

$deviceId = $payload['device_id'] ?? null;
if (!is_string($deviceId) || $deviceId === '' || strlen($deviceId) > 100) {
    $errors[] = 'device_id must be a non-empty string (max 100 chars).';
}

$meterId = $payload['meter_id'] ?? null;
if (!is_string($meterId) || $meterId === '' || strlen($meterId) > 100) {
    $errors[] = 'meter_id must be a non-empty string (max 100 chars).';
}

$piTimeUtc  = parse_utc_datetime($payload['ts_utc'] ?? null);
if ($piTimeUtc === null) {
    $errors[] = 'ts_utc must be an ISO 8601 timestamp (e.g. 2026-07-12T10:00:00+00:00).';
}

// JSON numbers arrive as int (0, 1) or float (0.1) depending on how they
// were written — accept both; reject strings ("0.1") and negatives.
$energyNow = $payload['energy_consumption_now_kwh'] ?? null;
if (!(is_float($energyNow) || is_int($energyNow)) || $energyNow < 0) {
     $errors[] = 'energy_consumption_now_kwh must be a non-negative number.';
} 

$energyLast = $payload['energy_consumption_last_kwh'] ?? null;
if (!(is_float($energyLast) || is_int($energyLast)) || $energyLast < 0) {
     $errors[] = 'energy_consumption_last_kwh must be a number.';
} 

$energyDelta = $payload['energy_consumption_delta_kwh'] ?? null;
if (!(is_float($energyDelta) || is_int($energyDelta)) || $energyDelta < 0) {
     $errors[] = 'energy_consumption_delta_kwh must be a non-negative number.';
} 

if ($errors !== []) {
    respond(400, ['error' => 'Invalid payload.', 'details' => $errors]);
}

// ── Registered-device check ──────────────────────────────────────────────
// Payload syntax is valid at this point. Now verify that the authenticated
// client is sending data for a device registered in the server database.
//
// A prepared query keeps the device ID separate from the SQL statement.
// db_fetch_all() logs technical PDO failures and throws a generic exception.
try {
    $registeredMeters = db_fetch_all(
        'SELECT device_id
         FROM meters
         WHERE 
            device_id = :device_id AND
            meter_id = :meter_id
         LIMIT 1',
        [
            ':device_id' => $deviceId,
            ':meter_id' => $meterId,
            
        ]
    );
} catch (Throwable $error) {
    /*
     * Database or connection failures are server problems, not invalid client
     * input. Log a generic endpoint context and preserve the JSON API contract.
     */
    error_log(
        'ingest.php device lookup failed: ' . $error->getMessage()
    );

    respond(500, ['error' => 'Internal server error.']);
}

if ($registeredMeters === []) {
    /*
     * The payload is structurally valid, but it refers to a device that this
     * server does not permit. That is 422 rather than 400.
     */
    respond(422, [
        'error' => "Unknown device_id and meter_id combination.",
    ]);
}

// ── Insert — the DB enforces the hard rules, we translate its verdicts ──
// No "does this window already exist?" pre-check: two simultaneous
// retries could both pass such a check (race condition). The UNIQUE KEY
// cannot be raced; we just insert and interpret the outcome.
$sql = 'INSERT INTO meter_readings
            (
                meter_id, 
                device_id,
                energy_now_kwh,
                energy_last_kwh,
                energy_delta_kwh,
                pi_time_utc
            )
        VALUES
            (
                :meter_id,
                :device_id,
                :energy_now_kwh,
                :energy_last_kwh,
                :energy_delta_kwh,
                :pi_time_utc
            )';

try {
    // prepare/execute with named placeholders: SQL shape and values
    // travel to MySQL separately — a hostile meter_id is just a string,
    // never executable SQL (this is why EMULATE_PREPARES is off).
    db()->prepare($sql)->execute([
        ':meter_id'             => $meterId,
        ':device_id'            => $deviceId,
        ':energy_now_kwh'       => $energyNow,
        ':energy_last_kwh'      => $energyLast,
        ':energy_delta_kwh'     => $energyDelta,
        ':pi_time_utc'          => $piTimeUtc ,
    ]);
} catch (PDOException $e) {
    // errorInfo = [SQLSTATE, driver-specific errno, message];
    // index 1 is MySQL's own error number — precise, unlike SQLSTATE
    // 23000 which lumps ALL integrity violations together.
    $mysqlErrno = $e->errorInfo[1] ?? null;

    if ($mysqlErrno === 1062) {
        // Duplicate uq_meter_window: the Pi retried a POST whose response
        // got lost. The row it wanted stored IS stored — success, not
        // conflict. 200 (not 201: nothing was created this time).
        respond(200, ['status' => 'already recorded']);
    }

    if ($mysqlErrno === 1452) {
        // FK violation: meter not in the meters allowlist (decision #2:
        // reject, don't auto-register). 422 = "I understood the request,
        // but its content is not processable" — distinct from 400, where
        // the payload itself was malformed.
        respond(422, ['error' => "Unknown meter_id or device_id. Register it first."]);
    }

    // Anything else is OUR problem, not the client's: log the detail
    // server-side, reveal nothing (no SQL, no schema names) outward.
    error_log('ingest.php insert failed: ' . $e->getMessage());
    respond(500, ['error' => 'Internal server error.']);
} catch (Throwable $e) {
    // Safety net for everything else — including the RuntimeException
    // that db() throws when the connection itself fails. Throwable is
    // the top of PHP's error hierarchy: NOTHING gets past this, so every
    // failure exits through respond() and the JSON contract holds.
    error_log('ingest.php failed: ' . $e->getMessage());
    respond(500, ['error' => 'Internal server error.']);
}

respond(201, ['status' => 'created']);
