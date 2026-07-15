<?php

declare(strict_types=1);


require_once __DIR__ . '/../src/db.php';

/*
 * Load every registered meter.
 *
 * Begin with a simple query before joining measurement data. This confirms
 * that the dashboard can access the database and provides its base records.
 */
$sql_meters = '
    SELECT
        meter_id,
        name,
        description,
        created_at_utc
    FROM meters
    ORDER BY meter_id
';






/*
 * Load every registered meter and its latest aggregate.
 *
 * LEFT JOIN keeps meters that have never submitted a reading. The correlated
 * subquery selects one latest row, using the ID to break timestamp ties.
 */
$sql_aggregates = '
    SELECT
        m.meter_id,
        m.name,
        m.description,
        m.created_at_utc,
        latest.window_start_utc,
        latest.window_end_utc,
        latest.energy_delta_kwh,
        latest.received_at_utc
    FROM meters AS m
    LEFT JOIN meter_aggregates AS latest
        ON latest.id = (
            SELECT aggregate_row.id
            FROM meter_aggregates AS aggregate_row
            WHERE aggregate_row.meter_id = m.meter_id
            ORDER BY
                aggregate_row.window_end_utc DESC,
                aggregate_row.id DESC
            LIMIT 1
        )
    ORDER BY m.meter_id
';

$meters = [];
$aggregates = [];
$dashboardError = null;

try {
    $meters = db_fetch_all($sql_meters);
    $aggregates = db_fetch_all($sql_aggregates);
} catch (RuntimeException $error) {
    /*
     * db() and db_fetch_all() already log the technical details.
     * The page exposes only a safe, useful message.
     */
    $dashboardError = 'Meter data is temporarily unavailable. Please try again later.';
}


// ── Gather everything we want to display ─────────────────────────────────
// A page has the same anatomy as an endpoint: collect + validate data
// FIRST, render LAST. Never mix the two phases.

// The visitor's IP as the server sees it.
$visitorIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// The browser's self-description. DANGER: this is CLIENT-CONTROLLED text —
// anyone can send anything here, including <script> tags. It must NEVER
// reach the HTML unescaped (see e() below).
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '(none sent)';

// Is this connection encrypted? Behind Plesk's nginx proxy, HTTPS shows up
// either as $_SERVER['HTTPS'] or only in the forwarded port — check both.
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') === '443';

// Server time in UTC — the project's one true timezone.
$nowUtc = gmdate('Y-m-d H:i:s') . ' UTC';

/**
 * Escape a string for safe insertion into HTML.
 *
 * THE rule of templating: every piece of data that wasn't written by us
 * goes through this function on its way into the page. Otherwise a visitor
 * who sets their browser's user agent to  <script>...</script>  would have
 * that script EXECUTE in the browser of whoever views the page — that's
 * XSS (cross-site scripting), the #1 web vulnerability.
 *
 * htmlspecialchars turns  <  >  "  '  &  into harmless entities:
 * the text is DISPLAYED instead of INTERPRETED.
 */
function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/* For debugging only if something goes wrong.*/
/*
echo '<pre>';
echo e(print_r($meters, true));
echo '</pre>';

echo '<pre>';
echo e(print_r($aggregates, true));
echo '</pre>';
*/

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>optimasolar-freiamt — IoT Metering Backend</title>
    <style>
        /* minimal, self-contained styling — no external files needed */
        body  { font-family: system-ui, sans-serif; max-width: 46rem;
                margin: 3rem auto; padding: 0 1rem; color: #222; }
        h1    { border-bottom: 2px solid #f5a623; padding-bottom: .3rem; }
        table { border-collapse: collapse; width: 100%; margin: 1rem 0; }
        td, th{ border: 1px solid #ddd; padding: .5rem .7rem; text-align: left; }
        th    { background: #fafafa; }
        code  { background: #f4f4f4; padding: .1rem .3rem; border-radius: 3px; }
        .ok   { color: #1a7f37; font-weight: bold; }
        .warn { color: #b35900; font-weight: bold; }
        footer{ margin-top: 2rem; font-size: .85rem; color: #888; }
    </style>
</head>
<body>

<h1>⚡ IoT Metering Backend</h1>

<h2>Meter dashboard</h2>
<?php if ($dashboardError !== null): ?>
    <p class="warn"><?= e($dashboardError) ?></p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Meter</th>
                <th>Description</th>
                <th>Latest window (UTC)</th>
                <th>Energy</th>
                <th>Received (UTC)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($aggregates as $meter): ?>
                <tr>
                    <td> <!--<strong><?= e((string) $meter['name']) ?></strong><br> --> <code><?= e((string) $meter['meter_id']) ?></code>  </td>
                    <td><?= e((string) $meter['description']) ?></td>
                    <?php if ($meter['window_start_utc'] === null): ?>
                        <td colspan="3">
                            <span class="warn">No readings received</span>
                        </td>
                    <?php else: ?>
                        <td>
                            <?= e((string) $meter['window_start_utc']) ?><br>
                            <?= e((string) $meter['window_end_utc']) ?>
                        </td>
                
                        <td><?= e((string) $meter['energy_delta_kwh']) ?> kWh</td>
                        <td><?= e((string) $meter['received_at_utc']) ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>


<h2>Your connection</h2>
<table>
    <tr><th>Your IP address</th>
        <td><code><?= e($visitorIp) ?></code></td></tr>
    <tr><th>Your browser says it is</th>
        <td><code><?= e($userAgent) ?></code></td></tr>
    <tr><th>Encrypted (HTTPS)</th>
        <td><?= $isHttps
            ? '<span class="ok">yes</span>'
            : '<span class="warn">NO — how did you get here?</span>' ?></td></tr>
    <tr><th>Server time</th>
        <td><code><?= e($nowUtc) ?></code></td></tr>
</table>

<h2>API</h2>
<table>
    <tr><th>Endpoint</th><th>Method</th><th>Auth</th><th>Purpose</th></tr>
    <tr>
        <td><code>/api-v1.php?date=YYYY-MM-DD</code></td>
        <td>GET</td>
        <td>Bearer token</td>
        <td>15-minute price slots (mock data for now)</td>
    </tr>
</table>

<footer>
    The Project is not jet finished. 
</footer>

</body>
</html>