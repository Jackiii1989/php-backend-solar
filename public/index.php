<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/session.php';
require_once __DIR__ . '/../src/rate-limit.php';
$loginError = '';

// ── Sign out: ?logout=1 ─────────────────────────────────────────────────
// A GET that changes state isn't ideal in principle (a link prefetcher
// could trigger it), but for logout the worst case is a harmless
// inconvenience, and it saves a whole file.
if (isset($_GET['logout'])) {
    log_out();
    header('Location: /index.php', true, 303);
    exit;
}

// ── Sign in: the form posts back to this same page ──────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['password'])) {

    // Password guessing is exactly what a rate limiter is for — and you
    // already have one. Five attempts per minute per IP.
    require_rate_limit(
        'login',
        config_positive_int('RATE_LIMIT_LOGIN_MAX_REQUESTS', 5),
        config_positive_int('RATE_LIMIT_WINDOW_SECONDS_LOGIN', 60)
    );

    $password = $_POST['password'];

    // password_verify() re-hashes the candidate with the stored salt and
    // compares in constant time — the same reasoning as hash_equals()
    // for the Bearer token.
    if (is_string($password) && password_verify($password, config('DASHBOARD_PASSWORD_HASH'))) {
        log_in();

        // POST -> redirect -> GET. Without the redirect, refreshing the
        // page would re-submit the password, and the browser would nag
        // about it forever.
        header('Location: /index.php', true, 303);
        exit;
    }

    // Deliberately vague: never hint that a password was "close".
    $loginError = 'Wrong password.';
}

$signedIn = is_logged_in();

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


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Dashboard behavior; defer waits until the HTML has been parsed. -->
    <script src="/dashboard.js" defer></script> 

    <title>IoT Metering Backend</title>
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

<h2>Access</h2>

<?php if ($signedIn): ?>
    <p>
        <span class="ok">Signed in.</span>
        <a href="/graph.php" target="_blank" rel="noopener">📈 Open the graph(click one me!)</a> ·
        <a href="/index.php?logout=1">Sign out</a>
    </p>
<?php else: ?>
    <?php if ($loginError !== ''): ?>
        <p class="warn"><?= e($loginError) ?></p>
    <?php endif; ?>

    <form method="post" action="/index.php">
        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               autocomplete="current-password" required>
        <button type="submit">Sign in</button>
    </form>
<?php endif; ?>

<h2>Meter dashboard</h2>

<div id="dashboard-controls">
    <p>
        <label for="dashboard-token">API token</label><br>

        <input
            id="dashboard-token"
            type="password"
            autocomplete="current-password"
        >
    </p>

    <p>
        <label for="dashboard-meter">Meter ID</label><br>

        <input
            id="dashboard-meter"
            type="text"
            value="mock-meter-001"
        >
    </p>

    <p>
        <label for="dashboard-date">UTC date</label><br>

        <input
            id="dashboard-date"
            type="date"
            value="<?= e(gmdate('Y-m-d')) ?>"
        >
    </p>

    <!--
        This is deliberately not a submit button. Until JavaScript is added,
        the token must not accidentally be placed in a URL or form request.
    -->
    <button id="dashboard-load" type="button">
        Load measurements
    </button>
</div>

<p id="dashboard-status">
    Enter the API token, meter ID, and date to load measurements.
</p>

<table id="dashboard-table" hidden>
    <thead>
        <tr>
            <th>Time (UTC)</th>
            <th>Import (kWh)</th>
            <th>Export (kWh)</th>
            <th>L1 (W)</th>
            <th>L2 (W)</th>
            <th>L3 (W)</th> 
        </tr>
    </thead>

    <!-- JavaScript will safely create and insert rows here. -->
    <tbody id="dashboard-slots"></tbody>
</table>


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
        <td><code>/get-meter-data.php?date=YYYY-MM-DD</code></td>
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