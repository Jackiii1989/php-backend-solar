<?php

// Strict type checking 
declare(strict_types=1);

/**
 * Send a JSON response and stop. Every exit path of every endpoint
 * goes through here, so clients always get the same shape.
 */
function respond(int $status, array $body): never
{
	
	// Sets the STATUS LINE of the HTTP response — the very first line the
    // client sees, e.g. "HTTP/1.1 401 Unauthorized". This is the
    // machine-readable verdict that clients (and curl -i) branch on
    http_response_code($status);
	
	// Response header declaring what the body's bytes ARE. Without it PHP
    // defaults to text/html and clients have to guess.
    // HTTP ordering rule: status line + headers travel BEFORE the body,
    // so header() must be called before any echo.
    header('Content-Type: application/json');
	
    // json_encode converts the PHP array to a JSON string:
    //   ['error' => 'x']  ─►  {"error":"x"}
    // echo writes it into the response body. This is the moment the
    // internal PHP world is serialized for the outside world.
    echo json_encode($body);
    exit;
}

/**
 * Stop the request with 401 unless the given Authorization header
 * matches the expected Bearer token (constant-time comparison).
 */
function require_bearer_token(string $authHeader, string $expectedToken): void
{
	// Build the full expected header value ("Bearer abc123...") and compare
    // against what the client sent — in CONSTANT TIME.
    //
    // Why hash_equals and not === : a normal comparison stops at the first
    // wrong byte, so rejecting "Axxx" is measurably faster than rejecting
    // the almost-correct token. An attacker with a stopwatch can crack the
    // token byte by byte. hash_equals always compares every byte; timing
    // reveals nothing.
    //
    // Bonus of comparing the ENTIRE header instead of extracting the token:
    // a missing header arrives here as '' and fails the same comparison —
    // one condition covers "absent", "malformed" and "wrong".
    if (!hash_equals('Bearer ' . $expectedToken, $authHeader)) {
		// 401 = HTTP's "who are you?". The message deliberately does NOT
        // distinguish missing from invalid — that would confirm to an
        // attacker that their header format is already right.
        respond(401, ['error' => 'Missing or invalid token.']);
    }
}

?>