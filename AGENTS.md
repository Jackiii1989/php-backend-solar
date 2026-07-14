# Repository Guidelines

## Project Structure & Module Organization

- `public/` is the web document root. `index.php` is the landing page,
  `api-v1.php` serves authenticated reads, and `ingest.php` accepts
  aggregates. Keep secrets and shared logic outside this folder.
- `src/` contains reusable PHP helpers: configuration loading, the PDO
  connection, and API response/authentication utilities.
- `db/schema.sql` is the MySQL/MariaDB schema and source of truth for stored
  fields and constraints.
- `docs/` contains historical plans and notes. `arhitecture-decisions.md`
  describes the current design.
- There is currently no automated test directory or asset pipeline.

## Build, Test, and Development Commands

This project uses plain PHP and has no build step or Composer dependencies.

```powershell
php -S 127.0.0.1:8000 -t public
```

Starts the server with the correct document root.

```powershell
Get-ChildItem src,public -Recurse -Filter *.php |
  ForEach-Object { php -l $_.FullName }
```

Checks every application PHP file for syntax errors.

```powershell
Get-Content db\schema.sql | mysql.exe -u root -p
```

Creates the database and tables. See `README.md` for configuration, meter
registration, and API examples.

## Coding Style & Naming Conventions

Target PHP 8.0 or newer and begin PHP files with
`declare(strict_types=1);`. Use four-space indentation, braces on the same line
as function declarations and conditions, and no closing `?>` in PHP-only files.
Use `snake_case` for functions, camelCase for local variables, and lowercase
endpoint filenames. Use `require_once` with `__DIR__`-anchored paths.
Prefer prepared statements for all input-derived SQL. Comments should explain
decisions or non-obvious behavior, not restate the code. No formatter or linter
is currently configured.

## Testing Guidelines

Run syntax checks before every commit. Manually exercise both happy paths and
error responses with `curl.exe`: `201`/duplicate `200` for ingestion and
`200`, `400`, `401`, `404`, and `405` for reads as applicable. Use a registered
development meter and no real credentials. If an automated suite is introduced,
place it under `tests/` and name tests after the behavior being verified.

## Commit & Pull Request Guidelines

Recent commits use short, action-oriented summaries. Prefer an imperative,
specific subject such as `Validate ingest window timestamps`; avoid vague
messages like `updates`. Keep commits focused on one concern. Pull requests
should explain the behavior changed, list verification commands and results,
identify API or schema effects, and link relevant issues. Include screenshots
only for changes to the rendered landing page.

## Security & Configuration Tips

Configuration comes from process environment variables or the root `.env`.
Never print tokens or database passwords, commit new secrets, or expose files
outside `public/`. Keep TLS verification enabled when sending Bearer tokens.

## Interactive Development Workflow

Before proposing development work, read `docs/development-handoff-codex.md`.
The user implements application changes personally: show one small step with
exact code, placement, explanatory comments, reasons, test commands, and
expected results. Do not edit application files unless the user explicitly
asks you to. Wait for their result or questions before presenting the next
step. Leave `.env` tracking unchanged unless the user revisits that decision.
