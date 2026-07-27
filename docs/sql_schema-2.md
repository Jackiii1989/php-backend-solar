-- Demolition: children first — the FK dictates the order,
-- so no FOREIGN_KEY_CHECKS tricks are needed at all.
DROP TABLE meter_aggregates;
DROP TABLE meters;

-- Rebuild, this time saying the charset EXPLICITLY on each table,
-- so it no longer depends on any database/server default.
CREATE TABLE meters (
    meter_id       VARCHAR(100) PRIMARY KEY,
    name           VARCHAR(200) NOT NULL,
    description    TEXT,
    created_at_utc DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE meter_aggregates (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    meter_id         VARCHAR(100) NOT NULL,
    window_start_utc DATETIME NOT NULL,
    window_end_utc   DATETIME NOT NULL,
    energy_delta_kwh DECIMAL(12,6) NOT NULL,
    received_at_utc  DATETIME NOT NULL,
    UNIQUE KEY uq_meter_window (meter_id, window_start_utc, window_end_utc),
    FOREIGN KEY (meter_id)
        REFERENCES meters(meter_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Re-register the meter (the DROP took it along if it existed).
INSERT INTO meters (meter_id, name, description, created_at_utc)
VALUES ('mock-meter-001', 'Mock meter', 'Development test meter', UTC_TIMESTAMP()