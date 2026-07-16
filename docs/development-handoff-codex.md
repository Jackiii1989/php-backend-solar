# Development Handoff — Codex

## How to work with the user

The user wants to implement application code personally. For every development
step:

1. Present only one small, reviewable step.
2. State the exact file and insertion or replacement location.
3. Show the complete code needed for that step before it is implemented.
4. Include useful comments explaining decisions and non-obvious behavior.
5. Explain why the approach fits this repository.
6. Give exact test commands and expected results.
7. Wait for the user's result and questions before continuing.

Do not modify application files unless the user explicitly asks. Documentation
may be changed only when explicitly requested. Do not suggest removing `.env`
from Git for now: the user intentionally keeps it tracked for easier local
development. Never print its secret values.

## Current implementation

- Plain PHP 8 application with MySQL/MariaDB and no Composer dependencies.
- `public/ingest.php` validates and stores authenticated energy aggregates.
- `public/api-v1.php` reads database-backed daily energy slots.
- `public/api-v1.php` catches unexpected read-side failures, logs technical
  details server-side, and returns a generic JSON `500` response.
- Both endpoints use the shared Bearer token from configuration.
- `src/db.php` provides the shared PDO connection and `db_fetch_all()` read
  helper.
- `public/.htaccess` is correctly named and forwards `Authorization` for
  Apache/FastCGI.
- The API intentionally returns `"unit": "kWh"` both at response level and
  inside each slot.
- `public/index.php` renders dashboard controls and an initially empty table;
  it does not query the database directly.
- `public/dashboard.js` calls the authenticated GET API and updates the table
  without reloading the complete page.

## Completed dashboard task

The browser dashboard is now working with this flow:

```text
public/index.php
    |
    | loads public/dashboard.js
    v
User enters API token, meter ID, and UTC date
    |
    | GET /api-v1.php + Authorization: Bearer <token>
    v
public/api-v1.php
    |
    | authenticated prepared SELECT
    v
MySQL/MariaDB
```

Dashboard behavior:

- The first load is triggered by the **Load measurements** button.
- The API token is read from a password input and sent only in the
  `Authorization` header. It is not embedded in PHP or JavaScript, included in
  the URL, or written to `localStorage`.
- After a load, retryable outcomes schedule another request 15 seconds after
  the current request finishes. `setTimeout()` is used so requests do not
  overlap.
- A `4xx` response stops polling until the user corrects the controls and loads
  again.
- API values are rendered with DOM creation and `textContent`, not
  `innerHTML`.
- An empty `slots` array hides the table and displays a normal “no
  measurements” status.
- A later network or server failure leaves the most recent successful table
  visible and displays a warning.

## Security boundary

- `public/dashboard.js` is intentionally public because browsers must download
  it. It contains no credentials.
- Measurement data remains protected by `api-v1.php` Bearer authentication.
- The same global token currently authorizes both reads and ingestion. Token
  separation, dashboard sessions, and rate limiting are future improvements.
- Production dashboard use requires verified HTTPS.
- Never place backup PHP files inside `public/`. During development,
  `public/_index.php` was found to be reachable without the new dashboard
  authentication flow and was moved to `backup/_index.php` outside the web
  document root.

## Verification completed

On 2026-07-15, the following checks passed:

- Every PHP file under `src/` and `public/` passed `php -l`.
- `public/dashboard.js` passed `node --check` when a local Node runtime was
  available.
- `GET /` returned `200`.
- `GET /dashboard.js` returned `200`.
- The rendered page referenced `/dashboard.js`.
- `GET /api-v1.php` without a token returned `401`.
- `GET /_index.php` returned `404` after the backup was moved.
- The user confirmed that authenticated measurement loading and polling work
  in the browser.

On 2026-07-16, the read-side error boundary was verified:

- `public/api-v1.php` passed `php -l` after the `Throwable` boundary was added.
- A request without the Bearer header returned `401` before database access.
- With a valid token and MySQL stopped, the endpoint returned generic JSON
  `500` without a stack trace or database details.
- After MySQL restarted, the same authenticated request returned the normal
  `200` response.

## Current limitations and next work

- The dashboard supports one meter and one UTC day at a time.
- The user must enter the shared API token after opening the page.
- There is no dashboard login/session, rate limiting, or audit log.
- There is no automated test suite.

Recommended next development step: make the GET API status checks repeatable,
starting with `200`, `400`, `401`, `404`, and `405` integration tests under
`tests/`. Keep the database-outage `500` test manual until the test harness has
a safe way to control an isolated database service.

Continue to present one confirmed implementation step at a time.
