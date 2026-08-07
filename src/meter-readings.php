<?php

/**
 * Meter reading queries.
 *
 * Knows about SQL and rows. Knows NOTHING about HTTP: no respond(), no
 * status codes. The caller decides what a missing meter or a failed
 * query means for its own protocol — that's what lets one query serve
 * two different authentication schemes.
 * 
 * Test:
 * php -r "require 'src/meter-readings.php'; var_dump(meter_exists('mock-meter-001'), meter_exists('ghost')); print_r(fetch_meter_slots('mock-meter-001', new DateTimeImmutable('2026-08-01', new DateTimeZone('UTC')), new DateTimeImmutable('2026-08-08', new DateTimeZone('UTC'))));"
 * 
 * 
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/api-helper.php';   // format_utc_atom()

/** Is this meter registered? The meters table is the allowlist for reads. */
function meter_exists(string $meterId): bool
{
    $rows = db_fetch_all(
        'SELECT meter_id FROM meters WHERE meter_id = :meter_id LIMIT 1',
        [':meter_id' => $meterId]
    );

    return $rows !== [];
}

/**
 * Readings for one meter over a half-open range [start, end), as slots.
 *
 * @return array<int, array<string, mixed>>
 */
function fetch_meter_slots(
    string $meterId,
    DateTimeImmutable $rangeStart,
    DateTimeImmutable $rangeEnd
): array {
    $startSql = $rangeStart->format('Y-m-d H:i:s');
    $endSql   = $rangeEnd->format('Y-m-d H:i:s');

    $readings = db_fetch_all(
        'SELECT pi_time_utc, energy_now_kwh, energy_delta_kwh
         FROM meter_readings
         WHERE meter_id = :meter_id
           AND pi_time_utc >= :range_start
           AND pi_time_utc <  :range_end
         ORDER BY pi_time_utc',
        [
            ':meter_id'    => $meterId,
            ':range_start' => $startSql,
            ':range_end'   => $endSql,
        ]
    );

    // The reading immediately before the range gives the FIRST slot its
    // start — energy_delta_kwh means "energy since the previous reading".
    $earlier = db_fetch_all(
        'SELECT pi_time_utc
         FROM meter_readings
         WHERE meter_id = :meter_id
           AND pi_time_utc < :range_start
         ORDER BY pi_time_utc DESC
         LIMIT 1',
        [
            ':meter_id'    => $meterId,
            ':range_start' => $startSql,
        ]
    );

    $previousTimestamp = $earlier[0]['pi_time_utc'] ?? null;

    $slots = [];
    foreach ($readings as $row) {
        $slots[] = [
            'start_timestamp' => $previousTimestamp === null
                ? null
                : format_utc_atom($previousTimestamp),
            'end_timestamp'   => format_utc_atom($row['pi_time_utc']),
            'meter_reading_delta_kwh' => (float) $row['energy_delta_kwh'],
            'meter_reading_now_kwh'   => (float) $row['energy_now_kwh'],
        ];

        $previousTimestamp = $row['pi_time_utc'];   // becomes the next slot's start
    }

    return $slots;
}