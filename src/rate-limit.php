<?php

/**
 * Shared fixed-window rate limiter for the public API endpoints.
 *
 * This file depends on respond() from api-helper.php. An endpoint must load
 * api-helper.php before it calls require_rate_limit().
 */

declare(strict_types=1);

/**
 * Allow at most $maxRequests from one client during one time window.
 *
 * $endpointName gives each endpoint an independent counter. For example,
 * "api-v1" reads must not consume the separate "ingest" allowance.
 *
 * $windowSeconds is the duration of a window, not a clock-aligned interval.
 * If the first request arrives at 12:00:30 and the duration is 60 seconds,
 * that client's window ends at 12:01:30.
 *
 * When the limit has already been used, this function sends JSON status 429
 * with a Retry-After header and stops the request through respond().
 */
function require_rate_limit(
    string $endpointName,
    int $maxRequests,
    int $windowSeconds
): void
{
    // These values are supplied by our endpoint code, so invalid values mean
    // a programming/configuration error rather than a bad client request.
    if ($endpointName === '' || $maxRequests < 1 || $windowSeconds < 1) {
        throw new InvalidArgumentException(
            'Rate-limit configuration must use positive values.'
        );
    }

    /*
     * REMOTE_ADDR is the address of the client directly connected to the web
     * server. Do not use X-Forwarded-For unless the application is later put
     * behind a known, trusted reverse proxy that replaces that header.
     *
     * "unknown" also gives CLI tests a stable identity when REMOTE_ADDR is
     * unavailable. Real HTTP requests normally always have REMOTE_ADDR.
     */
    $clientAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    /*
     * Build one counter identity from endpoint + client. The null byte (\0)
     * is only a separator, preventing ambiguous concatenations. SHA-256 turns
     * the identity into a fixed-length filename containing no address or path
     * characters supplied by the request.
     */
    $counterKey = hash(
        'sha256',
        $endpointName . "\0" . $clientAddress
    );

    /*
     * Runtime counters stay outside public/, so a browser cannot request them.
     * The operating-system temporary directory also avoids needing a database
     * query just to decide whether a request may continue.
     */
    $storageDirectory = sys_get_temp_dir()
        . DIRECTORY_SEPARATOR
        . 'solar-pi-rate-limits';

    /*
     * mkdir(..., true) creates missing parent directories too. A second PHP
     * request could create the directory between is_dir() and mkdir(), so the
     * final is_dir() check treats that harmless race as success.
     *
     * The @ prevents a filesystem warning from leaking into an HTTP response;
     * we handle failure explicitly and write a controlled server-log message.
     */
    if (
        !is_dir($storageDirectory)
        && !@mkdir($storageDirectory, 0700, true)
        && !is_dir($storageDirectory)
    ) {
        /*
         * Fail open: if counter storage is unavailable, preserve API
         * availability and record that rate-limit protection was reduced.
         */
        error_log('Rate limiter could not create its storage directory.');
        return;
    }

	// Create a storage directory
    $counterPath = $storageDirectory
        . DIRECTORY_SEPARATOR
        . $counterKey
        . '.json';

    /*
     * Mode "c+" opens the file for reading and writing, creates it if needed,
     * and—importantly—does not erase an existing counter before it is locked.
     */
    $handle = @fopen($counterPath, 'c+');

    if ($handle === false) {
        error_log('Rate limiter could not open its counter file.');
        return;
    }

    /*
     * LOCK_EX obtains an exclusive lock. Only one PHP request can perform the
     * read-update-write sequence for this counter at a time, preventing two
     * simultaneous requests from both reading and incrementing the same old
     * value.
     */
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        error_log('Rate limiter could not lock its counter file.');
        return;
    }

    /*
     * time() returns the current Unix timestamp in seconds. Adding the window
     * duration therefore produces the correct future expiry timestamp:
     *
     *     reset_at = current timestamp + number of seconds in the window
     *
     * Unix timestamps do not depend on PHP's display timezone or daylight-
     * saving changes, which makes them suitable for elapsed rate-limit time.
     */
    $now = time();

    /*
     * A file handle has a cursor. rewind() moves that cursor to byte zero;
     * stream_get_contents() then reads from the beginning to the end. Reading
     * moves the cursor back to the end, so we rewind again before writing.
     */
    rewind($handle);
    $storedValue = stream_get_contents($handle);
    $counter = is_string($storedValue)
        ? json_decode($storedValue, true)
        : null;

    /*
     * Start a new window when the file is empty, malformed, incomplete, or
     * expired. json_decode(..., true) returns an associative PHP array.
     */
    if (
        !is_array($counter)
        || !isset($counter['count'], $counter['reset_at'])
        || !is_int($counter['count'])
        || !is_int($counter['reset_at'])
        || $counter['reset_at'] <= $now
    ) {
        $counter = [
            'count' => 0,
            'reset_at' => $now + $windowSeconds,
        ];
    }

    /*
     * This request is over the limit if earlier allowed requests have already
     * used the complete allowance. Denied requests do not increase the count.
     */
    if ($counter['count'] >= $maxRequests) {
        $retryAfter = max(1, $counter['reset_at'] - $now);

        // Release operating-system resources before respond() calls exit.
        flock($handle, LOCK_UN);
        fclose($handle);

        // Retry-After uses seconds here: the client may try again afterward.
        header('Retry-After: ' . $retryAfter);

        respond(429, [
            'error' => 'Too many requests.',
            'retry_after_seconds' => $retryAfter,
        ]);
    }

    // This request is allowed, so consume one request from the allowance.
    $counter['count']++;
    $encodedCounter = json_encode($counter);

    /*
     * rewind() returns the cursor to the start. ftruncate(..., 0) removes the
     * old bytes, fwrite() writes the new JSON, and fflush() asks PHP to flush
     * its buffered data to the file before the lock is released.
     */
    rewind($handle);
    $saved = $encodedCounter !== false
        && ftruncate($handle, 0)
        && fwrite($handle, $encodedCounter) === strlen($encodedCounter)
        && fflush($handle);

    if (!$saved) {
        /*
         * The current request remains allowed, but future requests might not
         * see this increment. Log the reduced protection without exposing a
         * filesystem path or internal error to the API client.
         */
        error_log('Rate limiter could not save its counter.');
    }

    // Every successful lock/open operation gets a matching unlock/close.
    flock($handle, LOCK_UN);
    fclose($handle);
}
