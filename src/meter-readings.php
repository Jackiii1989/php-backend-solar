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
 * Every registered meter, for the picker on the graph page.
 *
 * ORDER BY is not cosmetic here: the client assigns each meter a colour
 * from its POSITION in this list, so the order must be the same on every
 * request. name can repeat between meters, so meter_id (the primary key,
 * therefore unique) breaks ties and makes the order total.
 *
 * Test: php -r "require 'src/meter-readings.php'; print_r(fetch_meters());"
 * 
 * @return array<int, array<string, mixed>>
 */
function fetch_meters(): array
{
    return db_fetch_all(
        'SELECT meter_id, name, description
         FROM meters
         ORDER BY name, meter_id'
    );
}


/**
 * Raw readings for one meter over a half-open range [start, end).
 *
 * Nothing is derived here. Each row goes out exactly as the meter reported
 * it, so the chart draws measurements rather than arithmetic.
 *
 * The loop is not business logic — it is the storage-format-to-wire-format
 * boundary, and it does exactly two jobs:
 *
 *   1. MySQL DATETIME carries no timezone, so format_utc_atom() re-attaches
 *      the explicit +00:00 the client needs.
 *   2. PDO returns DECIMAL columns as STRINGS ("0.244000"). Casting to float
 *      means the JSON holds numbers, so JavaScript can plot them without
 *      calling Number() on every value.
 *
 * @return array<int, array<string, mixed>>
 */
function fetch_meter_readings(
    string $meterId,
    DateTimeImmutable $rangeStart,
    DateTimeImmutable $rangeEnd
): array {
    $rows = db_fetch_all(
        'SELECT pi_time_utc,
                import_kwh, export_kwh,
                power_l1_w, power_l2_w, power_l3_w
         FROM meter_readings
         WHERE meter_id = :meter_id
           AND pi_time_utc >= :range_start
           AND pi_time_utc <  :range_end
         ORDER BY pi_time_utc',
        [
            ':meter_id'    => $meterId,
            ':range_start' => $rangeStart->format('Y-m-d H:i:s'),
            ':range_end'   => $rangeEnd->format('Y-m-d H:i:s'),
        ]
    );

    $readings = [];
    foreach ($rows as $row) {
        $readings[] = [
            'timestamp'  => format_utc_atom($row['pi_time_utc']),
            'import_kwh' => (float) $row['import_kwh'],
            'export_kwh' => (float) $row['export_kwh'],
            'power_l1_w' => (float) $row['power_l1_w'],
            'power_l2_w' => (float) $row['power_l2_w'],
            'power_l3_w' => (float) $row['power_l3_w'],
        ];
    }

    return $readings;
}
