<?php

/**
 * Ingest endpoint — receives one 15-minute aggregate per POST from the Pi.
 * Contract: 201 stored / 200 already stored (retry) / 401 bad token /
 * 405 wrong method / 400 bad payload / 422 unknown meter.
 */

declare(strict_types=1);
require_once __DIR__ . '/../src/db.php';
header('Content-Type: application/json');