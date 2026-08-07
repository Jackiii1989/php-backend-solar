<?php

/**
 * get-meter-data.php — GET endpoint
 *
 * Contract:
 *   GET /v1/get-meter-data.php?date=YYYY-MM-DD&meter_id=...
 *   Authorization: Bearer <API_TOKEN_GET_USAGE>
 *
 *   200 day of slots (possibly empty) | 400 bad or missing input
 *   401 bad token                     | 404 unknown meter
 *   405 wrong verb                    | 429 rate limit exceeded
 *   500 unexpected server or database failure
 *
 * This is the read-side twin of ingest.php: what the Pi POSTs in,
 * a client GETs back out — same table, opposite direction.
 *
 * Each slot's value is the energy measured BETWEEN two readings, so a
 * slot spans [previous reading, this reading). The first slot of a day
 * therefore needs the last reading of the previous day — see the
 * lookback query below.
 */

declare(strict_types=1);


// __DIR__ = the folder THIS file lives in (public/v1), so the paths work
// no matter where the web server was started from. ../../src is OUTSIDE
// the web root: helpers and config can never be requested by a browser.
require_once __DIR__ . '/../../src/config.php'; # function to get the environment variables
require_once __DIR__ . '/../../src/api-helper.php'; # helper api functions
require_once __DIR__ . '/../../src/db.php'; # database functions.
require_once __DIR__ . '/../../src/rate-limit.php'; // shared API rate limiter
require_once __DIR__ . '/../../src/meter-readings.php'; // for meter-readings api

// ── Gate 1: only GET may pass (shared helper — same 405 + Allow header
//    behavior as ingest.php, defined exactly once) ────────────────────────
require_method('GET');

// ── Gate 2: rate limiting ────────────────────────────────────────────────
// Keep a separate allowance for this endpoint and each connecting IP.
// The dashboard polls once every 15 seconds (about four requests/minute),
// so 60 requests/minute leaves enough room for normal use and manual reloads.
//
// This gate runs before authentication so repeated attempts with missing or
// incorrect Bearer tokens also consume the request allowance.
require_rate_limit(
    'get-meters',

    // Maximum read requests per client during one window.
    // The code default remains 60 if the setting is absent.
    config_positive_int('RATE_LIMIT_API_MAX_REQUESTS', 60),

    // Shared duration of one rate-limit window in seconds.
    // The code default remains 60 if the setting is absent.
    config_positive_int('RATE_LIMIT_WINDOW_SECONDS_API', 60)
);


// ── Gate 3: authentication ───────────────────────────────────────────────

// $_SERVER is the superglobal where PHP exposes the request environment;
// incoming HTTP headers appear uppercased with an HTTP_ prefix:
//   Authorization  ->  HTTP_AUTHORIZATION
// If the client sent no such header the key doesn't exist AT ALL, so
// "?? ''" (null coalescing) substitutes an empty string — the function
// always receives a real string, and "missing" flows into the normal
// comparison as a failed match.
//require_bearer_token($_SERVER['HTTP_AUTHORIZATION'] ?? '', config('API_TOKEN_INGEST'));


// Browsers send a session cookie and never hold the API token. Machines
// (the Pi, curl) send a Bearer token. Same data, same rules, two ways of
// proving who you are — so one endpoint, not two.
//
// Only consult the session when a session cookie was actually presented:
// calling session_start() for every Pi request would litter the server
// with junk session files. session_name() reads the cookie's name
// without starting anything.
if (!(isset($_COOKIE[session_name()]) && is_logged_in())) {
    require_bearer_token($_SERVER['HTTP_AUTHORIZATION'] ?? '', config('API_TOKEN_GET_USAGE'));
}

// ── Gate 4: the ?date= query parameter ───────────────────────────────────
// Optional; default is today in UTC (gmdate = date() in UTC).
//$date = $_GET['date'] ?? gmdate('Y-m-d');

// Round-trip validation trick: createFromFormat alone is too forgiving —
// it happily accepts "2026-02-31" and silently rolls it over to March 3rd.
// So: parse, format BACK, and demand the result equals the input.
// Only real calendar dates survive that round trip.
//
// The "!" in '!Y-m-d' means "zero out every field the format doesn't
// mention" — hours/minutes/seconds become 00:00:00, giving us exactly
// midnight UTC, which doubles as our day start.
//$dayStart = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
//if ($dayStart === false || $dayStart->format('Y-m-d') !== $date) {
//    respond(400, ['error' => 'date must have the form YYYY-MM-DD.']);
//}

// ── Gate 4: the time range ───────────────────────────────────────────────
// ?from=&to= are inclusive days; ?date= is shorthand for both; nothing
// means today. Keeping ?date= alive means the existing dashboard keeps
// working — an old client should never break because the server learned
// a new trick.
$today = gmdate('Y-m-d');

$from = parse_utc_date($_GET['from'] ?? $_GET['date'] ?? $today);
$to   = parse_utc_date($_GET['to']   ?? $_GET['date'] ?? $today);

if ($from === null || $to === null) {
    respond(400, ['error' => 'from/to/date must have the form YYYY-MM-DD.']);
}
if ($from > $to) {
    respond(400, ['error' => 'from must not be later than to.']);
}

// "to" names an INCLUSIVE day, but the query wants a half-open upper
// bound: to=2026-08-07 means "through the end of the 7th".
$rangeStart = $from;
$rangeEnd   = $to->add(new DateInterval('P1D'));

// A guard, not a preference: an unbounded range would pull the whole
// history into memory and across the wire.
if ($rangeStart->diff($rangeEnd)->days > 31) {
    respond(400, ['error' => 'Range too large (maximum 31 days).']);
}

// ── Gate 5: which meter? ─────────────────────────────────────────────────
// The response declares ONE meter_id, so one meter per request. There is
// no default: with several meters, guessing which one the caller meant
// would be a bug factory.
$meterId = $_GET['meter_id'] ?? '';
if ($meterId === '') {
    respond(400, ['error' => 'meter_id is required.']);
}


// The meters table is the allowlist for reads too. Asking for an
// unregistered meter = asking for a resource that doesn't exist -> 404.
// (Compare ingest's 422 for the same situation: there the request was
// "please STORE this" — understood but unprocessable. Here it's "please
// SHOW me X" and there is no X. Different verbs, different semantics.)
//
// prepare/execute with a placeholder even for this tiny query: the
// meter_id came from the URL — user input NEVER gets glued into SQL.
try {
    if (!meter_exists($meterId)) {
        respond(404, ['error' => "Unknown meter_id '{$meterId}'."]);
    }

    $slots = fetch_meter_slots($meterId, $rangeStart, $rangeEnd);
} catch (Throwable $error) {
    error_log('get-meter-data.php read failed: ' . $error->getMessage());
    respond(500, ['error' => 'Internal server error.']);
}

respond(200, [
    'meter_id' => $meterId,
    'from'     => $from->format('Y-m-d'),
    'to'       => $to->format('Y-m-d'),
    'unit'     => 'kWh',
    'slots'    => $slots,
]);