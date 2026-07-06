-- Schema for the IoT metering backend (MySQL/MariaDB).
-- Load with: mysql -u root -p < db_schema.sql

-- Design: docs/backend_php_project_plan.md, revised to a normalized
-- two-table layout — meters (entities) and meter_aggregates (events),
-- linked by a foreign key.

CREATE DATABASE IF NOT EXISTS iot_metering
	-- here we define the charachterset to be utf8mb4(UTF-8). PHP and MySQL agreeing on how bytes travel over the wire.
	CHARACTER SET utf8mb4 
	-- collate defines how text is compared and sorted. It affects =, ORDER BY, GROUP BY, DISTINCT
	COLLATE utf8mb4_unicode_ci; --

USE iot_metering;

-- One row per meter is 15-minute window, mirroring the Pis local database
CREATE TABLE IF NOT EXISTS meter_aggregates(
	id INT AUTO_INCREMENT PRIMARY KEY 
)
