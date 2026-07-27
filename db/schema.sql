-- Schema for the IoT metering backend (MySQL/MariaDB).
-- Load locally with: mysql -u root -p < db/schema.sql
--             or:    Get-Content db\schema.sql | mysql.exe -u root -p
--
-- On shared hosting (Plesk): the DB user may NOT create databases —
-- Plesk creates the database in its UI. There, skip the CREATE DATABASE
-- and USE lines below and run only the CREATE TABLE statements
-- (select the database in phpMyAdmin's sidebar first).

-- Design: docs/backend_php_project_plan.md, revised to a normalized
-- two-table layout — meters (entities) and meter_aggregates (events),
-- linked by a foreign key.

CREATE DATABASE IF NOT EXISTS iot_metering
	-- here we define the character set to be utf8mb4 (real UTF-8).
	-- PHP and MySQL agreeing on how bytes travel over the wire.
	CHARACTER SET utf8mb4
	-- collate defines how text is compared and sorted. It affects =, ORDER BY, GROUP BY, DISTINCT
	COLLATE utf8mb4_unicode_ci;

USE iot_metering;

-- NOTE (deployment lesson, 2026-07): each table states
-- ENGINE/CHARSET/COLLATE EXPLICITLY instead of inheriting a default.
-- A table without its own charset silently inherits the database default,
-- and the database default is whatever the server happens to use —
-- on our Plesk host that was utf8mb3, the broken 3-byte subset.
-- We only noticed after the tables were created (and had to drop and
-- rebuild them; converting later trips over the FK: error 1833).
-- Rule learned: defaults are hidden dependencies — pin what matters.

CREATE TABLE IF NOT EXISTS meters (
	meter_id VARCHAR(100) PRIMARY KEY,
	name VARCHAR(200) NOT NULL,
	description TEXT,
	created_at_utc DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per meter per 15-minute window, mirroring the Pi's local database
CREATE TABLE IF NOT EXISTS meter_aggregates(
	id INT AUTO_INCREMENT PRIMARY KEY,
	meter_id VARCHAR(100) NOT NULL,
	window_start_utc DATETIME NOT NULL,
	window_end_utc DATETIME NOT NULL,
	-- exact decimal number: 12 digits total, 6 of them after the decimal point
	energy_delta_kwh DECIMAL(12,6) NOT NULL,
	received_at_utc DATETIME NOT NULL,
	-- with UNIQUE KEY, the database creates an extra internal data structure beside the main table storage.
	-- The extra structure is a B-tree index.
	UNIQUE KEY uq_meter_window (meter_id, window_start_utc, window_end_utc),
	-- FK columns are ONE logical thing spread over two tables: meter_id here
	-- must stay byte-for-byte comparable with meters.meter_id — same type,
	-- same charset, same collation. (That's also why changing such a column
	-- later means treating both tables as a unit.)
	FOREIGN KEY (meter_id)
		REFERENCES meters(meter_id)
		ON UPDATE CASCADE
		ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--  Why the `UNIQUE KEY` exists — design note:
--
-- Its primary purpose is *correctness*, not query speed. If the Pi retries a
-- POST (e.g. the response was lost but the insert already succeeded), this
-- constraint makes the retried insert fail harmlessly instead of creating a
-- duplicate row that would corrupt energy totals. "Insert and let the DB
-- reject the dupe" is the idempotency strategy. As a secondary effect it is
-- also an index, which speeds up the dashboard's "latest per meter" lookup.

-- The UNIQUE KEY creates a structure that works like this:
-- "meter-001 | 2026-07-07 10:00 | 2026-07-07 10:15 -> id 1"
-- because the unique key is defined as: meter_id + window_start_utc + window_end_utc


-- What the UNIQUE KEY costs:
--
-- In InnoDB the `PRIMARY KEY` is the clustered index (rows are stored ordered by it); 		--> basically one structure
-- a `UNIQUE KEY` is a separate secondary B-tree that every insert must check and maintain. --> basically a second structure
-- Therefore the engine maintains one structure more per insert.
-- Extra disk, extra memory, extra work per insert — all irrelevant at one
-- row per meter per 15 minutes. The cost/benefit only bites at thousands of
-- inserts/sec. Here we pay a tiny, irrelevant cost for a correctness
-- guarantee and get faster lookups as a free bonus.


-- **Deliberate choices, not boilerplate:**
--
-- `ON UPDATE CASCADE` = renaming a meter_id carries its history along.
-- `ON DELETE RESTRICT` = a meter with data cannot be deleted; measurement
-- history can't be orphaned or silently destroyed.

-- **Charset/collation (Q&A learning):** `utf8mb4` = real UTF-8 (MySQL's
-- plain `utf8` is a broken 3-byte subset — never use it; on the Plesk
-- server it showed up under its honest name, utf8mb3). Collation
-- `utf8mb4_unicode_ci` decides comparison/sorting; `_ci` = case-insensitive,
-- which means the UNIQUE key treats `Mock-Meter-001` and `mock-meter-001`
-- as the same meter — accepted as a feature here. `DECIMAL`, not `FLOAT`,
-- for counters: exact, no rounding drift. `DATETIME`, not strings: real
-- date comparisons and `MAX()`