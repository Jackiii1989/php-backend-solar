# Database initialization

This guide initializes a new local MariaDB/MySQL database for the PHP IoT
metering backend. It creates the database, tables, example device and meter,
and the restricted application account.

> This procedure is for a clean installation. It is not a migration for an
> existing database. Run the SQL as a database administrator, such as the
> local XAMPP `root` account.

## Example identifiers

The commands use these development identifiers:

```text
device_id: gateway-001-abcd1234
meter_id:  mock-meter-001
```

## Run the initialization in phpMyAdmin

1. Open [http://localhost/phpmyadmin/](http://localhost/phpmyadmin/).
2. Open the **SQL** tab from the phpMyAdmin server home page.
3. Execute the following sections in order.

## 1. Create and select the database

```sql
CREATE DATABASE IF NOT EXISTS iot_metering
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE iot_metering;
```

The character set and collation are specified explicitly so text columns use
the same encoding and comparison rules on every installation.

## 2. Create the device registry

```sql
CREATE TABLE devices (
    device_id VARCHAR(100) NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    created_at_utc DATETIME NOT NULL,
    updated_at_utc DATETIME NULL,

    PRIMARY KEY (device_id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
```

Every Raspberry Pi gateway must be registered in this table before meters can
be assigned to it.

## 3. Create the meter registry

```sql
CREATE TABLE meters (
    meter_id VARCHAR(100) NOT NULL,
    device_id VARCHAR(100) NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    created_at_utc DATETIME NOT NULL,
    updated_at_utc DATETIME NULL,

    PRIMARY KEY (meter_id),

    /*
     * Records meter ownership and provides the referenced key for
     * meter_readings(device_id, meter_id).
     */
    UNIQUE KEY uq_meters_device_meter (
        device_id,
        meter_id
    ),

    CONSTRAINT fk_meters_device
        FOREIGN KEY (device_id)
        REFERENCES devices (device_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
```

The foreign key rejects a meter whose `device_id` is not registered. The
composite key records the meter's ownership relationship so readings can
reference the gateway and meter as one unit.

## 4. Create the readings table

```sql
CREATE TABLE meter_readings (
    id INT AUTO_INCREMENT NOT NULL,

    device_id VARCHAR(100) NOT NULL,
    meter_id VARCHAR(100) NOT NULL,
    pi_time_utc DATETIME NOT NULL,

    energy_now_kwh DECIMAL(12,6) NOT NULL,
    energy_last_kwh DECIMAL(12,6) NOT NULL,
    energy_delta_kwh DECIMAL(12,6) NOT NULL,

    PRIMARY KEY (id),

    /*
     * A retry with the same gateway, meter, and Pi timestamp represents
     * the same reading and must not create a duplicate row.
     */
    UNIQUE KEY uq_meter_readings (
        device_id,
        meter_id,
        pi_time_utc
    ),

    /*
     * Ensures that the submitted meter belongs to the submitted gateway.
     */
    CONSTRAINT fk_meter_readings_meter
        FOREIGN KEY (device_id, meter_id)
        REFERENCES meters (device_id, meter_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
```

The unique key makes repeated delivery of the same reading idempotent. The
composite foreign key prevents one registered gateway from submitting data for
another gateway's meter.

## 5. Register the example gateway

```sql
INSERT INTO devices (
    device_id,
    name,
    description,
    created_at_utc,
    updated_at_utc
)
VALUES (
    'gateway-001-abcd1234',
    'Gateway 001',
    'Raspberry Pi metering gateway',
    UTC_TIMESTAMP(),
    NULL
);
```

`UTC_TIMESTAMP()` records the registration time in UTC.

## 6. Register the example meter

```sql
INSERT INTO meters (
    meter_id,
    device_id,
    name,
    description,
    created_at_utc,
    updated_at_utc
)
VALUES (
    'mock-meter-001',
    'gateway-001-abcd1234',
    'Mock meter 1',
    'Development test meter',
    UTC_TIMESTAMP(),
    NULL
);
```

The gateway must be inserted first because the meter references it through a
foreign key.

## 7. Create the application account

Replace `PASTE_DB_PASS_HERE` with the exact `DB_PASS` value from the root
`.env` file. If the `.env` value has surrounding quotes, do not include those
quotes as part of the password.

```sql
CREATE USER 'iot_backend'@'localhost'
IDENTIFIED BY 'PASTE_DB_PASS_HERE';
```

If the account already exists, change its password instead:

```sql
ALTER USER 'iot_backend'@'localhost'
IDENTIFIED BY 'PASTE_DB_PASS_HERE';
```

Never add the real password to this document, `schema.sql`, or version
control.

## 8. Grant application permissions

```sql
GRANT SELECT, INSERT
ON `iot_metering`.*
TO 'iot_backend'@'localhost';
```

The application can read registered devices and meters and insert readings.
It cannot modify the schema or delete data. `FLUSH PRIVILEGES` is unnecessary
after `CREATE USER`, `ALTER USER`, or `GRANT`.

## 9. Verify the initialization

List the application tables:

```sql
SHOW TABLES;
```

Expected tables:

```text
devices
meter_readings
meters
```

Verify the example gateway-to-meter relationship:

```sql
SELECT
    d.device_id,
    m.meter_id,
    m.name
FROM devices AS d
INNER JOIN meters AS m
    ON m.device_id = d.device_id;
```

Expected relationship:

```text
gateway-001-abcd1234 | mock-meter-001 | Mock meter 1
```

Verify the application account's permissions:

```sql
SHOW GRANTS FOR 'iot_backend'@'localhost';
```

The result should include `SELECT` and `INSERT` on `iot_metering` and no
schema-changing or administrative privileges.

Finally, test the connection from PowerShell in the project root:

```powershell
php -r "require 'src/db.php'; echo db()->query('SELECT 1')->fetchColumn(), PHP_EOL;"
```

Expected output:

```text
1
```

## References

- [MariaDB `CREATE DATABASE`](https://mariadb.com/docs/server/reference/sql-statements/data-definition/create/create-database)
- [MariaDB `CREATE USER`](https://mariadb.com/docs/server/reference/sql-statements/account-management-sql-statements/create-user)
- [MariaDB `GRANT`](https://mariadb.com/docs/server/reference/sql-statements/account-management-sql-statements/grant)
- [MariaDB foreign keys](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/optimization-and-indexes/foreign-keys)
