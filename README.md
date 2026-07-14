# php-backend-solar-pi

PHP backend for a Raspberry Pi IoT metering project.

Architecture and design rationale are documented in[`arhitecture-decisions.md`](arhitecture-decisions.md).

## Project structure

```text
php-backend-solar-pi/
|-- .env                         Local configuration and secrets
|-- README.md                    Local setup and commands
|-- arhitecture-decisions.md     Architecture and design rationale
|-- db/
|   `-- schema.sql               MySQL/MariaDB schema
|-- docs/                        Requirements, plans, and command notes
|-- public/                      Web document root
|   |-- .htaccess                Forwards Authorization on Apache/FastCGI
|   |-- index.php                Informational landing page
|   |-- api-v1.php               Authenticated GET API
|   `-- ingest.php               Authenticated POST API
`-- src/
    |-- config.php               Environment/configuration loader
    |-- db.php                   PDO connection factory
    `-- api-helper.php           HTTP, JSON, and authentication helpers
```

## Requirements

- PHP 8.0 or newer
- PHP PDO MySQL extension (`pdo_mysql`)
- MySQL or MariaDB
- `curl.exe` when using the PowerShell examples

## Local configuration

Create or update `.env` in the repository root:

```dotenv
DB_HOST=127.0.0.1
DB_NAME=iot_metering
DB_USER=iot_meter_backend
DB_PASS=replace-with-local-password
API_TOKEN=replace-with-a-long-random-token
```

Generate an API token:

```powershell
php -r "echo bin2hex(random_bytes(32));"
```

## Database commands

Import the schema:

```powershell
Get-Content db\schema.sql | mysql.exe -u root -p
```

Create a least-privilege application user:

```powershell
mysql.exe -u root -p -e "CREATE USER IF NOT EXISTS 'iot_meter_backend'@'127.0.0.1' IDENTIFIED BY 'replace-with-local-password'; GRANT SELECT, INSERT ON iot_metering.* TO 'iot_meter_backend'@'127.0.0.1';"
```

Register a development meter:

```powershell
mysql.exe -u root -p -e "INSERT INTO iot_metering.meters (meter_id, name, description, created_at_utc) VALUES ('mock-meter-001', 'Development meter', 'Local testing', UTC_TIMESTAMP());"
```

Show the tables:

```powershell
mysql.exe -u root -p -e "SHOW TABLES FROM iot_metering;"
```

Test the configured database connection:

```powershell
php -r "require 'src/db.php'; print_r(db()->query('SELECT * FROM meters')->fetchAll());"
```

## Start the local server

Run this command from the repository root:

```powershell
php -S 127.0.0.1:8000 -t public
```

Open the landing page:

```text
http://127.0.0.1:8000/
```

## API commands

Set the token in the PowerShell session:

```powershell
$TOKEN = "<API_TOKEN from .env>"
```

Read one meter's data for a UTC day:

```powershell
curl.exe -i "http://127.0.0.1:8000/api-v1.php?date=2026-07-12&meter_id=mock-meter-001" -H "Authorization: Bearer $TOKEN"
```

Create `payload.json`:

```json
{
  "meter_id": "mock-meter-001",
  "window_start_utc": "2026-07-12T10:00:00+00:00",
  "window_end_utc": "2026-07-12T10:15:00+00:00",
  "energy_delta_kwh": 0.1
}
```

Submit the aggregate:

```powershell
curl.exe -i -X POST "http://127.0.0.1:8000/ingest.php" -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" --data-binary "@payload.json"
```

Check PHP syntax:

```powershell
Get-ChildItem src,public -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```
