<?php

/**
 * list-meters.php — GET endpoint
 *
 * Contract:
 *   GET /v1/list-meters.php
 *   Authorization: Bearer <API_TOKEN_GET_USAGE>   (or a logged-in session)
 *
 *   200 {"meters":[{meter_id,name,description}, ...]}   (possibly empty)
 *   401 bad token   | 405 wrong verb
 *   429 rate limit  | 500 unexpected server or database failure
 *
 * The graph page calls this once on load to build its meter picker, so
 * meters are DISCOVERED instead of typed by hand.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/api-helper.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/rate-limit.php';
require_once __DIR__ . '/../../src/session.php';        // is_logged_in()
require_once __DIR__ . '/../../src/meter-readings.php'; // fetch_meters()

// ── Gate 1: only GET may pass ────────────────────────────────────────────
require_method('GET');

// ── Gate 2: rate limiting ────────────────────────────────────────────────
// Its OWN bucket name, deliberately. The chart polls 'get-meters' every 15
// seconds; if this endpoint shared that name, opening the page would spend
// the chart's allowance. Separate names = separate budgets.
require_rate_limit(
    'list-meters',
    config_positive_int('RATE_LIMIT_API_MAX_REQUESTS', 60),
    config_positive_int('RATE_LIMIT_WINDOW_SECONDS_API', 60)
);

// ── Gate 3: authentication ───────────────────────────────────────────────
// Same dual scheme as get-meter-data.php: browsers prove themselves with a
// session cookie, machines with a Bearer token. Only touch the session when
// a cookie was actually sent, so token clients don't litter the server with
// junk session files.
if (!(isset($_COOKIE[session_name()]) && is_logged_in())) {
    require_bearer_token($_SERVER['HTTP_AUTHORIZATION'] ?? '', config('API_TOKEN_GET_USAGE'));
}

// ── The work ─────────────────────────────────────────────────────────────
// No input to validate: there are no query parameters at all.
try {
    $meters = fetch_meters();
} catch (Throwable $error) {
    error_log('list-meters.php read failed: ' . $error->getMessage());
    respond(500, ['error' => 'Internal server error.']);
}

respond(200, ['meters' => $meters]);