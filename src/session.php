<?php

/**
 * Browser session helpers.
 *
 * The API uses Bearer tokens (machine to machine). Humans get a session
 * cookie instead, so the API token never leaves the server.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Start the session with hardened cookie flags.
 *
 * MUST run before any output — a cookie is an HTTP header, and headers
 * travel before the body.
 */
function session_start_secure(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;                       // already started in this request
    }

    session_set_cookie_params([
        'lifetime' => 0,              // dies when the browser closes
        'path'     => '/',

        // HTTPS only, when we're on HTTPS. Behind Plesk's proxy TLS shows
        // up either as $_SERVER['HTTPS'] or only in the forwarded port.
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                      || ($_SERVER['SERVER_PORT'] ?? '') === '443',

        // JavaScript cannot read this cookie, so an XSS bug can't steal
        // the session. This is why a session cookie beats a token typed
        // into a form field.
        'httponly' => true,

        // Not sent on cross-site requests: a foreign page cannot make the
        // browser act as you. CSRF defence, for free.
        'samesite' => 'Lax',
    ]);

    session_start();
}

/** Is this visitor signed in? */
function is_logged_in(): bool
{
    session_start_secure();

    return ($_SESSION['logged_in'] ?? false) === true;
}

/**
 * Gate for HTML pages: send anonymous visitors to the home page, which
 * carries the login form. (An API answers 401 instead — same rule,
 * different audience: JavaScript can branch on a status code, a human
 * needs a page.)
 */
function require_login(): void
{
    if (is_logged_in()) {
        return;
    }

    header('Location: /index.php', true, 303);
    exit;
}

/** Mark this session as authenticated. */
function log_in(): void
{
    session_start_secure();

    // New ID whenever privilege changes: an attacker who planted a known
    // session ID in your browser BEFORE login is left holding a
    // worthless one. This is the session-fixation defence.
    session_regenerate_id(true);

    $_SESSION['logged_in'] = true;
}

/** Destroy the session completely. */
function log_out(): void
{
    session_start_secure();

    $_SESSION = [];
    session_destroy();
}