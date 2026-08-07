# Security and architecture review

**Date:** 2026-08-07
**Scope:** `src/`, `public/`, `db/schema.sql`, and the project documentation, at
commit `0bf4821` (includes the `list-meters.php` endpoint added that day).
**Status:** review only. No application file was changed to produce this
document, in line with the workflow rule in `AGENTS.md`.

---

## How to read this document

Each finding follows the same four-part shape, because a finding without all
four parts is not actionable:

1. **What the code does now** — the exact lines, so the claim can be checked.
2. **Why this is bad** — a concrete scenario, not an abstract principle. If a
   problem cannot be described as "an attacker does X and gets Y", it probably
   is not a problem worth spending time on.
3. **How to fix it** — real code, not advice.
4. **How to verify** — the command or observation that proves the fix worked.

The severity labels mean:

| Severity     | Meaning                                                                        |
| ------------ | ------------------------------------------------------------------------------ |
| **Critical** | Secrets are exposed, or one mistake gives an attacker full access.             |
| **High**     | A protection that the design claims to have does not actually work in production. |
| **Medium**   | Real weakness, but needs another condition or only degrades behavior.          |
| **Low**      | Correctness or hardening nit. Cheap to fix, small consequence if not.          |

### What is *not* wrong

Worth stating first, because a review that only lists problems gives a false
impression of the whole.

- **SQL injection: not present.** Every query in `src/meter-readings.php` and
  `public/ingest.php` uses named placeholders, and `src/db.php:41` sets
  `PDO::ATTR_EMULATE_PREPARES => false`, so the statement and its values really
  do travel to MySQL separately. This is the strong form, not the cosmetic one.
- **XSS: not present.** Server-side output goes through `e()`
  (`public/index.php:84`), and both JavaScript files build rows with
  `textContent` and `document.createElement`, never `innerHTML`
  (`dashboard.js:52`, `graph.js:228`).
- **Error leakage: handled.** `src/db.php:43-53` deliberately rethrows a fresh
  `RuntimeException` instead of the original `PDOException`, precisely because
  a `PDOException` stack trace contains `PDO::__construct`'s arguments — that
  is, the database password. That is a subtle trap, and the code avoids it on
  purpose.
- **Timing attacks on the token: handled.** `require_bearer_token()` uses
  `hash_equals()` over the whole header (`src/api-helper.php:50`).

The problems below are concentrated in three places instead: **how secrets are
stored**, **a rate limiter that fails open**, and **an unfinished migration
from "token typed into the browser" to "session cookie" authentication.**

---

## Summary table

| #  | Severity | Finding                                                          | Where                              |
| -- | -------- | ---------------------------------------------------------------- | ---------------------------------- |
| 1  | Critical | `.env` is tracked in git; `.gitignore` does not ignore it        | `.gitignore:1`                     |
| 2  | High     | Session cookie likely ships without `Secure` behind Plesk's proxy | `src/session.php:32-33`            |
| 3  | High     | Rate limiter fails open, including for the login form            | `src/rate-limit.php:86,103,115`    |
| 4  | Medium   | Two auth schemes for one endpoint; token form shown to anonymous users | `public/index.php:142`, `get-meter-data.php:81` |
| 5  | Medium   | `respond()` can emit an empty body and break the JSON contract   | `src/api-helper.php:28`            |
| 6  | Medium   | Read path has no ownership scoping, write path does; `list-meters.php` discloses every meter | `src/meter-readings.php:23,103`    |
| 7  | Low      | `graph.php` links to a `/logout.php` that does not exist         | `public/graph.php:113`             |
| 8  | Low      | `?meter_id[]=x` produces a 500 instead of a 400                  | `get-meter-data.php:134`           |
| 9  | Low      | Bearer scheme comparison is case-sensitive                       | `src/api-helper.php:50`            |
| 10 | Low      | `getenv()` reads the whole CGI environment                       | `src/config.php:69`                |
| 11 | Low      | No upper bound on ingested energy values                         | `public/ingest.php:83-96`          |
| 12 | Low      | No security headers on HTML pages or JSON responses              | `src/api-helper.php:22`            |
| 13 | Note     | The main dashboard query cannot use any index                    | `db/schema.sql:81`                 |
| 14 | Note     | Documentation describes a version of the code that no longer exists | `README.md`, `AGENTS.md`, `docs/` |

---

## 1. `.env` is tracked in git, and `.gitignore` does not ignore it

**Severity: Critical**

### What the code does now

`.gitignore` begins with this line:

```gitignore
# Secrets: DB credentials + API token live here. Never commit.
```

…and then never names `.env`. It is a comment, not a rule. `git ls-files`
confirms the consequence:

```
$ git ls-files | head -1
.env
```

So the following are committed and present in the repository history:

```dotenv
DB_PASS=test12345test
API_TOKEN_INGEST=test123
API_TOKEN_GET_USAGE=test123
DASHBOARD_PASSWORD_HASH='$2y$10$y23bFeY5NIXczniHtxXabex6EBDEfTY.kRmgB8MEVO0VwErY5rvEi'
```

`AGENTS.md` records this as a deliberate decision ("Leave `.env` tracking
unchanged unless the user revisits that decision"), and the values do look like
local development placeholders. This section is the invitation to revisit it.

### Why this is bad

**First: the values in a tracked file have a way of becoming the real ones.**

`docs/arhitecture-decisions.md:349` states the intended discipline: *"Server-specific
`.env` values should be installed outside version control with restrictive file
permissions."* Nothing in the repository enforces that, and the mechanics of
git actively fight it. Picture the ordinary deployment sequence on the Plesk
box:

```
1. git clone ...              → .env arrives with test12345test in it
2. edit .env on the server    → real production password
3. git pull                   → git sees a modified tracked file
```

At step 3 git either refuses the pull ("your local changes would be
overwritten") or, if someone resolves that with `git checkout -- .env` or
`git stash`, **silently replaces the production password with
`test12345test`.** The application then fails to connect, and the natural
"quick fix" under pressure is to commit the real value so it stops happening.
That is how tracked `.env` files end up holding production secrets — not
through a single bad decision, but through three reasonable ones in a row.

**Second: a committed password hash is worse than a committed password.**

This is the part that is easy to underestimate. `DB_PASS` protects a MySQL
account bound to `127.0.0.1` (see the `GRANT` in `README.md`), so an attacker
must already be on the host to use it. `DASHBOARD_PASSWORD_HASH` is different:
it protects `/index.php`, which is reachable from the entire internet, and the
hash is *offline-crackable*. Nobody has to talk to your server to attack it.

Concretely, an attacker who obtains the repository — a future contractor, a
laptop backup, an accidental public push, a compromised GitHub account — copies
that one line into a file and runs:

```
hashcat -m 3200 hash.txt rockyou.txt
```

Mode 3200 is bcrypt. Cost 10 is deliberately slow, which is why bcrypt was the
right choice for storing the password. But "slow" is relative: a single modern
GPU still tries several thousand bcrypt-cost-10 candidates per second. The
`rockyou` wordlist of leaked real-world passwords is 14 million entries — about
an hour of work, and it is the *first* thing anyone tries. A
human-chosen password like `Solar2026!` falls in that window; only a genuinely
random passphrase does not. And this attack is **completely invisible to you**:
no failed logins, no rate limit triggered, no log line. The first evidence is
a successful login that looks exactly like your own.

The rate limiter in finding #3 is what makes an *online* guessing attack slow.
Committing the hash removes the need to go online at all — it routes around
every defence the application has.

**Third: git history is forever.**

Deleting the file in a later commit does not help. `git log -p -- .env` still
shows every value the file ever held, and so does any clone, fork, or mirror
made before the deletion. Rotation, not deletion, is the fix.

### How to fix it

Three steps, in this order.

**Step 1 — stop tracking the file, and make `.gitignore` actually say so:**

```gitignore
# Secrets: DB credentials + API tokens live here. Never commit.
/.env

# project internal files
/public/_*
/backup/
```

```powershell
git rm --cached .env
git commit -m "Stop tracking .env; add the ignore rule the comment promised"
```

`--cached` removes it from git's index but leaves the file on disk, so local
development keeps working.

**Step 2 — commit a template instead**, so the file's *shape* stays documented
(which is presumably the reason it was tracked in the first place):

```dotenv
# .env.example — copy to .env and fill in. Never commit the filled-in copy.
DB_HOST=127.0.0.1
DB_NAME=iot_metering
DB_USER=iot_backend
DB_PASS=

# php -r "echo bin2hex(random_bytes(32));"
API_TOKEN_INGEST=
API_TOKEN_GET_USAGE=

RATE_LIMIT_WINDOW_SECONDS_API=60
RATE_LIMIT_API_MAX_REQUESTS=90
RATE_LIMIT_WINDOW_SECONDS_INGEST=60
RATE_LIMIT_INGEST_MAX_REQUESTS=150
RATE_LIMIT_WINDOW_SECONDS_LOGIN=60
RATE_LIMIT_LOGIN_MAX_REQUESTS=5

# php -r "echo password_hash('your password here', PASSWORD_DEFAULT);"
DASHBOARD_PASSWORD_HASH=
```

This is strictly better than a tracked real `.env`: a new developer sees every
key they must set, and a missing value produces the clean "Server
misconfigured." from `config()` (`src/config.php:86-88`) instead of a
confusing connection error.

**Step 3 — rotate everything that was ever committed.** The old values are
public knowledge from now on, regardless of what happens to the file:

```powershell
# new API tokens
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # for API_TOKEN_INGEST
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # for API_TOKEN_GET_USAGE

# new dashboard password hash — use a long random passphrase, not a word
php -r "echo password_hash('correct horse battery staple', PASSWORD_DEFAULT), PHP_EOL;"

# new database password
mysql.exe -u root -p -e "ALTER USER 'iot_backend'@'127.0.0.1' IDENTIFIED BY 'new-password-here';"
```

Rewriting history with `git filter-repo` is possible but rarely worth it on a
personal project — rotation makes the leaked values useless, which is the
actual goal.

**Also set file permissions on the server**, since the file now lives only
there:

```bash
chmod 600 .env
```

### How to verify

```powershell
git ls-files | Select-String "^\.env$"    # must print nothing
git check-ignore -v .env                  # must print the .gitignore rule
```

---

## 2. The session cookie probably ships without `Secure` in production

**Severity: High**

### What the code does now

`src/session.php:26-43` sets the cookie flags. The `secure` flag is computed:

```php
'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? '') === '443',
```

### Why this is bad

`docs/arhitecture-decisions.md:346` names the deployment target: **Plesk with
TLS, `public/` as the document root.** In that architecture nginx terminates
TLS and proxies the request onward to Apache/PHP-FPM. The important detail is
what the *backend* request looks like after the proxy hop:

```
Browser  ──── https:443 ────►  nginx  ──── http:80 ────►  PHP-FPM
                                             ▲
                          TLS ends here; the backend request is plain HTTP
```

By the time PHP sees the request, `$_SERVER['HTTPS']` is unset and
`$_SERVER['SERVER_PORT']` is `80`. Both halves of the condition are false, so
`secure` is set to `false`. The standard way a proxy communicates the original
scheme is the `X-Forwarded-Proto: https` header, which this code never reads.

Why does the missing flag matter? Without `Secure`, the browser is *permitted*
to send the session cookie over plain HTTP. An attacker on the same network —
a café Wi-Fi, a hotel, a compromised home router — does not need to break TLS.
They only need to make the browser issue one HTTP request to your domain:

```html
<!-- on any page the victim visits, or injected into any unencrypted page -->
<img src="http://your-domain.example/favicon.ico">
```

That request goes out unencrypted with `Cookie: PHPSESSID=…` attached. The
attacker reads the session ID off the wire and replays it. They are now signed
in as you, with full access to `/graph.php` and the read API — no password, no
token, and nothing in your logs distinguishes them from you. This attack has a
name (*sidejacking*) and the `Secure` flag is the entire defence against it.

The failure mode is what makes this High rather than Medium: **it is silent.**
Nothing errors. Login works. The dashboard works. The only symptom is a missing
attribute in a `Set-Cookie` header that nobody looks at.

The redeeming detail is that **you already built the detector.**
`public/index.php:207-210` renders a row using the *identical* expression:

```php
<tr><th>Encrypted (HTTPS)</th>
    <td><?= $isHttps
        ? '<span class="ok">yes</span>'
        : '<span class="warn">NO — how did you get here?</span>' ?></td></tr>
```

So this finding is directly testable before touching any code: open the
deployed site over `https://` and read that row. If it says **NO**, the cookie
flag is off too, because it is the same expression. That "how did you get
here?" message was written as a joke about an impossible state — it may be
describing your production server.

### How to fix it

Detect the proxy header, then use one shared helper so the page and the cookie
can never disagree again. Add to `src/session.php`:

```php
/**
 * Is the ORIGINAL client connection encrypted?
 *
 * Three ways to be on HTTPS, because three deployments differ:
 *   1. PHP terminates TLS itself          -> $_SERVER['HTTPS']
 *   2. the request arrived on port 443    -> SERVER_PORT
 *   3. a reverse proxy terminated TLS     -> X-Forwarded-Proto  (Plesk/nginx)
 *
 * SECURITY NOTE: X-Forwarded-Proto is a plain request header, so a client
 * can send it directly. Trusting it is only safe when a proxy we control
 * always OVERWRITES it — which nginx does with proxy_set_header. If this
 * app is ever served without that proxy in front, remove case 3.
 */
function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }

    return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}
```

Then use it in both places — `src/session.php:32`:

```php
'secure' => request_is_https(),
```

…and `public/index.php:66-67`, replacing the duplicated expression:

```php
require_once __DIR__ . '/../src/session.php';   // request_is_https()

$isHttps = request_is_https();
```

Removing the duplication is half the value here: the diagnostic row on the
landing page becomes a *true* report of the cookie flag rather than a
coincidence that happens to match.

Confirm on the Plesk side that nginx actually sets the header:

```nginx
proxy_set_header X-Forwarded-Proto $scheme;
```

Plesk's default nginx proxy template includes this, but it is worth verifying
rather than assuming — the whole finding is about an assumption that did not
hold.

Consider also adding HSTS once HTTPS is confirmed working, which removes the
plain-HTTP request the attack depends on:

```php
if (request_is_https()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
```

### How to verify

```powershell
curl.exe -I https://your-domain.example/index.php
```

The `Set-Cookie` line must contain `Secure`, `HttpOnly`, and `SameSite=Lax`.
And the "Encrypted (HTTPS)" row on the landing page must read **yes**.

---

## 3. The rate limiter fails open — including on the login form

**Severity: High**

### What the code does now

`src/rate-limit.php` returns silently, allowing the request through, on three
separate paths:

```php
// :86  — storage directory could not be created
error_log('Rate limiter could not create its storage directory.');
return;

// :103 — counter file could not be opened
error_log('Rate limiter could not open its counter file.');
return;

// :115 — lock could not be acquired
error_log('Rate limiter could not lock its counter file.');
return;
```

The comment at `:82-85` states the reasoning explicitly: *"Fail open: if
counter storage is unavailable, preserve API availability."*

`public/index.php:25-29` uses the same function to protect the login form:

```php
require_rate_limit(
    'login',
    config_positive_int('RATE_LIMIT_LOGIN_MAX_REQUESTS', 5),
    config_positive_int('RATE_LIMIT_WINDOW_SECONDS_LOGIN', 60)
);
```

### Why this is bad

**Fail-open is the correct choice for `ingest` and `get-meters`, and the wrong
one for `login`.** The two cases have opposite cost structures, and using one
policy for both is the actual flaw:

| Endpoint      | Cost of failing open                      | Cost of failing closed             |
| ------------- | ----------------------------------------- | ---------------------------------- |
| `ingest`      | Some extra writes from a misbehaving Pi   | **Measurement data is lost forever** |
| `get-meters`  | Some extra database reads                 | Dashboard breaks                   |
| `login`       | **Unlimited password guessing**           | You wait for the tmp dir to be fixed |

For `ingest`, data loss is irreversible and the abuse ceiling is low — fail
open, clearly right. For `login`, failing open removes the *only* thing
standing between the internet and unlimited guesses at your single dashboard
password.

The conditions that trigger it are mundane, not exotic:

- **The disk fills up.** `sys_get_temp_dir()` is usually `/tmp`, which is
  shared with everything else on the box. `fopen(..., 'c+')` fails, the limiter
  returns at `:103`, and the login form is now unprotected.
- **A tmp-cleaner runs.** `systemd-tmpfiles` and `tmpwatch` delete files under
  `/tmp` that have not been touched recently. This does not disable the
  limiter, but it does silently reset counters — an attacker pacing their
  guesses gets a fresh allowance for free.
- **PHP-FPM uses `PrivateTmp=yes`** (a systemd default on many distributions).
  Each pool gets a *private* `/tmp` namespace. Counters may then not be shared
  the way the code assumes.
- **Shared hosting.** On Plesk with several subscriptions on one host,
  `/tmp` semantics and permissions are not under your control.

And the attack itself needs no cleverness:

```powershell
# guesses per minute, limited only by network round-trips
foreach ($p in Get-Content wordlist.txt) {
    curl.exe -s -X POST https://your-domain.example/index.php -d "password=$p"
}
```

You will not notice. `error_log` writes one line per request into a log nobody
watches, and successful *and* failed logins look identical from outside.

A second, independent weakness: the counter key is
`hash('sha256', $endpointName . "\0" . $clientAddress)` — **IP only**
(`:55-58`). Five attempts per minute *per IP* means an attacker with a
$5 proxy pool, or simply an IPv6 /64 (which is 18 quintillion addresses, one
per request if they like), has no practical limit at all. The per-IP counter
is a speed bump for a bored person, not a defence against someone who wants in.

Third: counter files are **never deleted** (`:91-94` writes them, nothing
removes them). One file per IP per endpoint, accumulating forever, in the same
directory whose exhaustion disables the limiter. The failure mode feeds itself.

### How to fix it

**Fix A — let the caller choose the failure policy.** Add a parameter, and make
login fail closed:

```php
function require_rate_limit(
    string $endpointName,
    int $maxRequests,
    int $windowSeconds,
    bool $failOpen = true          // default keeps today's behavior for the APIs
): void {
    // ... unchanged up to the storage checks ...

    if (
        !is_dir($storageDirectory)
        && !@mkdir($storageDirectory, 0700, true)
        && !is_dir($storageDirectory)
    ) {
        error_log('Rate limiter could not create its storage directory.');

        if (!$failOpen) {
            /*
             * For credential endpoints an unavailable counter means the
             * brute-force protection is gone. Refusing the request is the
             * safe direction: a locked-out owner is recoverable, an
             * unlimited guessing window is not.
             */
            respond(503, ['error' => 'Service temporarily unavailable.']);
        }

        return;
    }
    // ... same treatment at the fopen and flock failure paths ...
}
```

`public/index.php:25`:

```php
require_rate_limit(
    'login',
    config_positive_int('RATE_LIMIT_LOGIN_MAX_REQUESTS', 5),
    config_positive_int('RATE_LIMIT_WINDOW_SECONDS_LOGIN', 60),
    false                          // login fails CLOSED — see review finding #3
);
```

Note that `respond()` emits JSON while `index.php` is an HTML page. For a
503 on a login form that is acceptable (it is a rare, terminal state), but a
small HTML branch would be friendlier.

**Fix B — add a second, global login counter** so the per-IP limit cannot be
bypassed by rotating addresses:

```php
// public/index.php, immediately after the per-IP limit
require_rate_limit('login-global', 30, 300, false);   // 30 attempts / 5 min, all IPs
```

This requires `require_rate_limit()` to support a constant key. The smallest
change is an optional argument that replaces `$clientAddress` with a fixed
string:

```php
function require_rate_limit(
    string $endpointName,
    int $maxRequests,
    int $windowSeconds,
    bool $failOpen = true,
    bool $perClient = true
): void {
    $clientAddress = $perClient
        ? ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
        : 'all-clients';
    // ...
}
```

30 attempts per 5 minutes globally is invisible to you (you get 30 tries to
recall your own password) and reduces a distributed attack from "unlimited" to
roughly 8,600 guesses per day — slow enough that a strong password is safe for
centuries rather than months.

**Fix C — move the storage out of `/tmp`** into a directory you control, and
sweep old files:

```php
// A project-owned directory: outside public/, so a browser can never
// request it, and not subject to the system tmp-cleaner.
$storageDirectory = __DIR__ . '/../var/rate-limits';
```

Add `/var/` to `.gitignore`, and clean up occasionally — roughly one request in
a thousand, so it costs nothing on the common path:

```php
// After a successful write, sometimes sweep expired counters.
if (random_int(1, 1000) === 1) {
    foreach (glob($storageDirectory . '/*.json') ?: [] as $file) {
        // Two windows old = certainly expired.
        if (filemtime($file) < $now - (2 * $windowSeconds)) {
            @unlink($file);
        }
    }
}
```

**Fix D — consider the window length for login.** Five per minute is 7,200
guesses per day, per IP. Against a human-chosen password that is a real threat
over weeks. A longer window (`RATE_LIMIT_WINDOW_SECONDS_LOGIN=900` with a max
of 10) barely affects a legitimate user who mistypes twice, and cuts an
attacker's rate by an order of magnitude.

### How to verify

```powershell
# 1. the limit still triggers normally
1..10 | ForEach-Object { curl.exe -s -o NUL -w "%{http_code}`n" -X POST http://127.0.0.1:8000/index.php -d "password=wrong" }
# expect: 200 200 200 200 200 then 429s

# 2. it now fails CLOSED when storage is unavailable
#    (make the storage directory unwritable, then repeat)
# expect: 503, not 200
```

---

## 4. Two authentication schemes for one endpoint, and the weaker one is offered to anonymous visitors

**Severity: Medium**

### What the code does now

`public/v1/get-meter-data.php:81-83` accepts *either* credential:

```php
if (!(isset($_COOKIE[session_name()]) && is_logged_in())) {
    require_bearer_token($_SERVER['HTTP_AUTHORIZATION'] ?? '', config('API_TOKEN_GET_USAGE'));
}
```

Meanwhile the whole dashboard block in `public/index.php:142-198` — including
the **"API token" password field** — is rendered outside the
`<?php if ($signedIn): ?>` branch, so every visitor sees it, signed in or not.

### Why this is bad

**First, the design contradicts itself.** `src/session.php:6-7` states the
intent plainly:

> *"The API uses Bearer tokens (machine to machine). Humans get a session
> cookie instead, so the API token never leaves the server."*

But `dashboard.js:204` sends `Authorization: Bearer ${controls.token}` from a
value a human typed into the page. The API token leaves the server on every
dashboard load — exactly what that comment says must not happen.

This is not carelessness; it is **archaeology**. `arhitecture-decisions.md:363`
still lists as a current limitation: *"The user must enter the shared API
token after opening the page; there is no dashboard login or server-side
session."* That was true when `dashboard.js` was written. Then session login
was added, `graph.js` was written the new way (`credentials: 'same-origin'`,
no token — `graph.js:248-254`), and `dashboard.js` was never migrated. Two
files, two eras, both still live.

**Second, the leftover creates real exposure.** Because the token form renders
for anonymous visitors, anyone on the internet gets a purpose-built interface
for guessing `API_TOKEN_GET_USAGE` against an endpoint that allows 90 requests
per minute (`RATE_LIMIT_API_MAX_REQUESTS=90`). With the current `.env` value of
`test123` that is not a theoretical concern.

**Third, a token typed into a browser is a weaker credential than a session
cookie**, for reasons the code itself already documents at
`src/session.php:35-38`:

| Property               | Session cookie                          | Token in a form field                     |
| ---------------------- | --------------------------------------- | ----------------------------------------- |
| Readable by JavaScript | No — `httponly` blocks it               | **Yes** — an XSS bug reads `.value` directly |
| Expires                | Yes — dies with the browser             | **No** — a static shared secret            |
| Revocable              | Yes — destroy the session               | **No** — must rotate and redeploy the Pi  |
| Scope                  | One browser, one person                 | **Everything**, forever                    |
| Ends up in clipboards? | No                                      | **Yes** — it must be copy-pasted to be used |

The `httponly` row is the one that matters most: the session design chose
`httponly` *specifically* so an XSS bug cannot steal credentials — and then the
token field puts a permanent, unrevocable credential in the DOM where any XSS
bug reads it in one line. The stronger mechanism is undermined by the weaker
one sitting next to it.

### How to fix it

Finish the migration `graph.js` already completed. Three small edits.

**Step 1 — put the dashboard behind the login** in `public/index.php`. Move the
whole `#dashboard-controls` block inside the signed-in branch, and delete the
token input:

```php
<?php if ($signedIn): ?>
    <h2>Meter dashboard</h2>

    <div id="dashboard-controls">
        <!-- The API token input is gone: the session cookie authenticates
             this page's requests now. See review finding #4. -->
        <p>
            <label for="dashboard-meter">Meter ID</label><br>
            <input id="dashboard-meter" type="text" value="mock-meter-001">
        </p>
        <p>
            <label for="dashboard-date">UTC date</label><br>
            <input id="dashboard-date" type="date" value="<?= e(gmdate('Y-m-d')) ?>">
        </p>
        <button id="dashboard-load" type="button">Load measurements</button>
    </div>

    <p id="dashboard-status">Choose a meter and date to load measurements.</p>
    <table id="dashboard-table" hidden>
        <!-- unchanged -->
    </table>
<?php endif; ?>
```

**Step 2 — `dashboard.js` uses the cookie**, matching `graph.js:248-254`:

```js
// The token input no longer exists.
const meterInput = document.getElementById('dashboard-meter');
// ... rest unchanged ...

function readDashboardControls() {
    const meterId = meterInput.value.trim();
    const date = dateInput.value;

    if (meterId === '' || date === '') {
        pollingEnabled = false;
        clearRefreshTimer();
        tableElement.hidden = true;
        setDashboardStatus('Meter ID and date are required.', 'warn');
        return null;
    }

    return { meterId, date };
}

const response = await fetch(requestUrl, {
    method: 'GET',
    headers: { 'Accept': 'application/json' },
    // The session cookie authenticates this request. No token in the page.
    credentials: 'same-origin',
    cache: 'no-store'
});
```

Since `dashboard.js` is now only served inside a signed-in page, its elements
may be absent when signed out — guard the boot code:

```js
if (loadButton !== null) {
    loadButton.addEventListener('click', () => { /* ... */ });
}
```

**Step 3 — handle the expired session** the way `graph.js:259-262` does, so a
tab left open overnight redirects to the login form instead of showing a
confusing error:

```js
if (response.status === 401) {
    window.location.href = '/index.php';
    return;
}
```

The dual-auth check at `get-meter-data.php:81` can stay exactly as it is — that
part of the design is sound. Browsers present a cookie, machines present a
token, and one endpoint serves both. What changes is that browsers stop
presenting the *machine's* credential.

### How to verify

```powershell
# signed out: no token field is served at all
curl.exe -s http://127.0.0.1:8000/index.php | Select-String "dashboard-token"
# expect: no match

# the token still works for machines
curl.exe -i "http://127.0.0.1:8000/v1/get-meter-data.php?meter_id=mock-meter-001" -H "Authorization: Bearer $TOKEN"
# expect: 200
```

---

## 5. `respond()` can send an empty body and break the JSON contract

**Severity: Medium**

### What the code does now

`src/api-helper.php:22-28`:

```php
header('Content-Type: application/json');
echo json_encode($body);
exit;
```

The return value of `json_encode` is not checked. And
`public/v1/get-meter-data.php:150` reflects raw user input into the body:

```php
respond(404, ['error' => "Unknown meter_id '{$meterId}'."]);
```

### Why this is bad

`json_encode` returns `false` — not a JSON string — when the data contains
invalid UTF-8. `echo false` prints the empty string. Confirmed on PHP 8.4:

```
$ php -r 'var_dump(json_encode(["error" => "Unknown meter_id \x27\xFF\xFE\x27."]));'
bool(false)
```

So this request, with valid credentials:

```
GET /v1/get-meter-data.php?meter_id=%FF
```

returns **HTTP 404 with a zero-byte body**. Every documented contract in the
file header (`get-meter-data.php:10-13`) promises JSON on every path.

Both clients then produce a misleading error, because both correctly assume
the API keeps its promise — `graph.js:269-271` and `dashboard.js:161-165` both
report *"Server returned invalid JSON"*. A user reading that will suspect the
server crashed or a proxy interfered. The real problem is one bad byte in a
query string, and nothing in the message points there.

The severity is Medium rather than Low because of what this class of bug does
to trust: the JSON contract is the assumption every consumer is built on, and
`respond()` is the single chokepoint that guarantees it (*"Every exit path of
every endpoint goes through here, so clients always get the same shape"* —
`api-helper.php:7-9`). A chokepoint with an unchecked failure mode is not a
guarantee. And any future `respond()` call carrying user data, database text,
or an exception message inherits the same bug for free.

### How to fix it

Make the encoder substitute bad bytes rather than fail, and handle the
remaining impossible case honestly:

```php
function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');

    /*
     * JSON_INVALID_UTF8_SUBSTITUTE replaces malformed byte sequences with
     * U+FFFD instead of making json_encode() return false. Without it, one
     * bad byte in a reflected query parameter produces an EMPTY body and
     * silently breaks the contract this function exists to guarantee.
     *
     * JSON_THROW_ON_ERROR turns any remaining failure (recursion, INF/NAN)
     * into an exception rather than a false we would have to remember to check.
     */
    try {
        $json = json_encode(
            $body,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
        );
    } catch (JsonException $exception) {
        // Encoding our OWN response failed: a server bug, not client input.
        error_log('respond() could not encode the response: ' . $exception->getMessage());

        http_response_code(500);
        $json = '{"error":"Internal server error."}';
    }

    echo $json;
    exit;
}
```

While in this function, add two headers that every authenticated JSON API
should send:

```php
header('Content-Type: application/json');

// This response is private measurement data: never store it in a shared
// or browser cache.
header('Cache-Control: no-store');

// Do not let a browser second-guess Content-Type. This matters here because
// we reflect user input into error messages: without nosniff, a client that
// sniffs could decide a 404 body is HTML.
header('X-Content-Type-Options: nosniff');
```

A defensible alternative is to stop reflecting input entirely — `respond(404,
['error' => 'Unknown meter_id.'])` — which removes this particular trigger.
Both are worth doing: the reflection is a symptom, the unchecked encode is the
bug.

### How to verify

```powershell
curl.exe -i "http://127.0.0.1:8000/v1/get-meter-data.php?meter_id=%FF" -H "Authorization: Bearer $TOKEN"
# expect: 404 with a real JSON body, not zero bytes
```

---

## 6. The read path has no ownership scoping, while the write path does

**Severity: Medium (design)**

### What the code does now

The **write** path checks ownership carefully. `public/ingest.php:109-121`
verifies the `(device_id, meter_id)` *pair*, and `db/schema.sql:91-95` backs it
with a composite foreign key whose stated purpose is explicit:

```sql
/*
 * Reference the ownership pair as one unit. This prevents a registered
 * gateway from submitting readings for another gateway's meter.
 */
CONSTRAINT fk_meter_readings_meter
    FOREIGN KEY (device_id, meter_id)
    REFERENCES meters (device_id, meter_id)
```

The **read** path has no equivalent. `src/meter-readings.php:23-31`:

```php
function meter_exists(string $meterId): bool
{
    $rows = db_fetch_all(
        'SELECT meter_id FROM meters WHERE meter_id = :meter_id LIMIT 1',
        [':meter_id' => $meterId]
    );

    return $rows !== [];
}
```

"Does this meter exist anywhere in the system?" — not "may *this caller* read
it?"

The `list-meters.php` endpoint added in commit `0bf4821` has the same shape, and
makes the gap larger. `fetch_meters()` (`src/meter-readings.php:103-110`) is:

```sql
SELECT meter_id, name, description
  FROM meters
 ORDER BY name, meter_id
```

No `WHERE` clause at all. Every holder of `API_TOKEN_GET_USAGE` receives the
complete inventory of every meter in the system, including the free-text `name`
and `description` fields — which is exactly where a human writes something like
"Garage, Müller house" or "Cabin — empty Nov–Mar".

### Why this is bad

Today the answer is "there is one device and one token, so it does not matter",
and that is correct. This is a design finding, not a live vulnerability.

But the schema has already committed to multi-device. `devices` and `meters`
are separate tables joined by a foreign key, the composite key exists solely to
express ownership, and the code comment calls the goal out by name. The moment
a second Raspberry Pi is registered — a friend's install, a second site, a
rented meter — the read endpoint hands **every** meter's history to **any**
holder of `API_TOKEN_GET_USAGE`, while the ingest endpoint carefully keeps the
same two devices apart.

That asymmetry is the part worth reconsidering, because of *why* reads are
authenticated at all. `arhitecture-decisions.md:150-151` gives the reason:

> *"Load data can reveal occupancy patterns, so the read endpoint is
> authenticated even though the public ESIT price API that inspired its shape
> is not."*

That is exactly right, and it is a privacy argument. Fifteen-minute energy
readings show when a household wakes, leaves, returns, cooks, travels, and
when the house is empty for a week. If that data is worth protecting from the
public, it is worth protecting from *other tenants of the same system* — who,
unlike the public, know the meter IDs and have a reason to be curious.

`list-meters.php` also changes the *difficulty* of the attack, not just its
scope. Before it existed, reading another tenant's data required guessing their
`meter_id` — real friction, since the ID is arbitrary. Now there is a
documented, authenticated endpoint whose entire job is to hand out the list.
Enumeration is no longer a step; it is a feature. That is fine when the
allowlist and the caller's scope are the same set, and it is a disclosure
endpoint the moment they are not.

The practical trap: the failure is invisible at the moment it is introduced.
Nobody adds a second device and thinks "this changes the security properties of
a read endpoint I wrote months ago." It looks like configuration, not a code
change. The right time to close it is while there is only one device and the
change is free.

### How to fix it

The clean fix is per-device tokens, which also removes the "one global token"
limitation already listed at `arhitecture-decisions.md:365`.

**Step 1 — store token hashes on the device**, in `db/schema.sql`:

```sql
ALTER TABLE devices
    -- Store a HASH, never the token itself. If the database leaks, the
    -- attacker gets hashes, not working credentials — the same reasoning
    -- that applies to DASHBOARD_PASSWORD_HASH.
    ADD COLUMN api_token_hash CHAR(64) NULL COMMENT 'sha256 of the read token',
    ADD UNIQUE KEY uq_devices_token (api_token_hash);
```

SHA-256 rather than bcrypt is right here: an API token is 256 bits of random
data, so there is no dictionary to attack and no need for a deliberately slow
hash on every request.

**Step 2 — resolve the caller to a device**, in `src/api-helper.php`:

```php
/**
 * Identify the calling device from its Bearer token.
 *
 * Returns the device_id, or null when the header is missing, malformed,
 * or unknown. The caller decides what that means for its protocol.
 */
function authenticate_device(string $authHeader): ?string
{
    if (!str_starts_with($authHeader, 'Bearer ')) {
        return null;
    }

    $token = substr($authHeader, 7);

    /*
     * Look the token up by its hash. A hash lookup is a single indexed
     * query and — unlike comparing a list of tokens in PHP — it does not
     * get slower or leak timing as the number of devices grows.
     */
    $rows = db_fetch_all(
        'SELECT device_id FROM devices WHERE api_token_hash = :hash LIMIT 1',
        [':hash' => hash('sha256', $token)]
    );

    return $rows[0]['device_id'] ?? null;
}
```

**Step 3 — scope the read query** to that device. Change
`src/meter-readings.php`:

```php
/** May this device read this meter? The ownership pair is the allowlist. */
function meter_belongs_to_device(string $meterId, string $deviceId): bool
{
    $rows = db_fetch_all(
        'SELECT meter_id FROM meters
          WHERE meter_id = :meter_id AND device_id = :device_id
          LIMIT 1',
        [':meter_id' => $meterId, ':device_id' => $deviceId]
    );

    return $rows !== [];
}
```

Note the response should stay **404**, not 403, for a meter owned by someone
else. A 403 confirms the meter exists — which is itself information the caller
has not earned. "I have no such meter for you" is both true and silent.

**Step 4 — scope the listing too**, or `list-meters.php` hands back everything
the other three steps just protected:

```php
/**
 * The meters this device may read. Same allowlist as meter_belongs_to_device(),
 * expressed as a list instead of a question.
 */
function fetch_meters_for_device(string $deviceId): array
{
    return db_fetch_all(
        'SELECT meter_id, name, description
           FROM meters
          WHERE device_id = :device_id
          ORDER BY name, meter_id',
        [':device_id' => $deviceId]
    );
}
```

The `ORDER BY` must stay: the comment at `src/meter-readings.php:95-98`
explains the client assigns colours by list position, so a non-deterministic
order would make the chart's colours shuffle between requests.

**A smaller interim step**, if per-device tokens are more work than the project
wants right now: keep the single token but add an explicit allowlist of meter
IDs it may read, in `.env`:

```dotenv
API_READ_METERS=mock-meter-001
```

```php
$allowedMeters = explode(',', config('API_READ_METERS', ''));
if (!in_array($meterId, $allowedMeters, true)) {
    respond(404, ['error' => 'Unknown meter_id.']);
}
```

Crude, but it converts "every meter forever" into "the meters you listed", and
it takes ten minutes.

The browser-session path needs the same treatment eventually — a signed-in
human is currently also unscoped — but that only becomes meaningful once there
is more than one human, which is further away than more than one device.

---

## 7–12. Lower-severity findings

### 7. `graph.php` links to a page that does not exist

`public/graph.php:113`:

```html
<a href="/logout.php">Sign out</a>
```

There is no `logout.php`. Logout lives at `public/index.php:14` and is reached
as `/index.php?logout=1`. The Sign out link on the graph page returns 404.

**Why it matters beyond tidiness:** a sign-out button that does not sign out is
a security control that silently does nothing. A user on a shared machine
clicks it, sees a 404, assumes they are out, and walks away with a live
session.

**Fix:**

```html
<a href="/index.php?logout=1">Sign out</a>
```

### 8. `?meter_id[]=x` produces a 500 instead of a 400

`public/v1/get-meter-data.php:134`:

```php
$meterId = $_GET['meter_id'] ?? '';
if ($meterId === '') { ... }
```

PHP turns `?meter_id[]=x` into an **array**. `$meterId === ''` does not match
it, so execution reaches `meter_exists($meterId)`, which is declared
`string $meterId` under `declare(strict_types=1)` — a `TypeError`. The
`Throwable` catch at `:154` converts it into a 500.

**Why it matters:** a 500 means "the server is broken"; this is a malformed
request and should say 400. Misreporting it puts noise in the logs and hides
the class of error. Note that `from`/`to` get this right already — they go
through `parse_utc_date(mixed $value)`, which type-checks properly
(`api-helper.php:229`). Only `meter_id` skips the check.

**Fix:**

```php
$meterId = $_GET['meter_id'] ?? '';
if (!is_string($meterId) || $meterId === '') {
    respond(400, ['error' => 'meter_id is required.']);
}
```

### 9. The Bearer scheme comparison is case-sensitive

`src/api-helper.php:50` compares the full header byte-for-byte:

```php
if (!hash_equals('Bearer ' . $expectedToken, $authHeader)) {
```

RFC 7235 defines the auth *scheme* as case-insensitive, so `bearer <token>` is
a legal request. Here it fails with a 401 that is indistinguishable from a bad
token, which could cost real debugging time with an HTTP client that
normalises header case.

Comparing the whole header is a genuinely good idea — it collapses "missing",
"malformed" and "wrong" into one branch, as the comment explains. Keep that,
just normalise the scheme first:

```php
// Split off the scheme so it can be compared case-insensitively (RFC 7235)
// while the TOKEN keeps its constant-time, byte-exact comparison.
$parts = explode(' ', $authHeader, 2);
$scheme = strtolower($parts[0] ?? '');
$presented = $parts[1] ?? '';

if ($scheme !== 'bearer' || !hash_equals($expectedToken, $presented)) {
    respond(401, ['error' => 'Missing or invalid token.']);
}
```

`hash_equals` still guards the secret; only the scheme name relaxes. A missing
header still arrives as `''`, still fails, still returns the same 401.

### 10. `getenv()` reads the whole CGI environment

`src/config.php:69`:

```php
$value = getenv($key);
```

Without the second argument, `getenv()` reads the full environment, which under
CGI/FastCGI includes request-derived variables the web server injects.

No current config key (`DB_PASS`, `API_TOKEN_INGEST`, …) collides with anything
a client can influence — CGI variables are `HTTP_*`, `REMOTE_*`, `SERVER_*`,
`REDIRECT_*` — so there is no live issue. But the project *does* already
inject an environment variable from a client-supplied header, in
`public/.htaccess`:

```apache
SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1
```

That one is intentional and safe. It is also a reminder that the environment
this code reads is not purely server-controlled. One character closes the
category permanently:

```php
// true = local_only: read only variables set for THIS process, never
// request-derived CGI variables.
$value = getenv($key, true);
```

Check first that the production process manager really does export the
variables via `putenv`/`SetEnv` rather than the SAPI environment, since
`local_only` is stricter.

### 11. No upper bound on ingested energy values

`public/ingest.php:83-96` validates type and non-negativity but no ceiling:

```php
if (!(is_float($energyNow) || is_int($energyNow)) || $energyNow < 0) {
```

The column is `DECIMAL(12,6)` (`db/schema.sql:73`) — six digits before the
decimal point, so the maximum is `999999.999999`. A payload with
`"energy_consumption_now_kwh": 1e30` passes validation, reaches MySQL, and
raises "Out of range value" (error 1264). That is not error 1062 or 1452, so it
falls through to the generic handler at `ingest.php:200-203` and returns a
**500**.

Same misreporting problem as finding #8: the client sent bad data, the server
blames itself. Add the bound the column already implies:

```php
// DECIMAL(12,6) holds at most 999999.999999. Reject out-of-range values
// HERE, so the client gets a 400 that names the field instead of a 500
// that says nothing.
const MAX_ENERGY_KWH = 999999.999999;

$energyNow = $payload['energy_consumption_now_kwh'] ?? null;
if (!(is_float($energyNow) || is_int($energyNow))
    || $energyNow < 0
    || $energyNow > MAX_ENERGY_KWH
) {
    $errors[] = 'energy_consumption_now_kwh must be a number between 0 and '
              . MAX_ENERGY_KWH . '.';
}
```

Minor related nit at `:90`: the message for `energy_consumption_last_kwh` reads
*"must be a number"* while the check also rejects negatives. The other two
fields say *"non-negative number"*. Make it consistent.

### 12. No security headers

Neither HTML page sends `Content-Security-Policy` or `X-Frame-Options`, and
`respond()` sends only `Content-Type`.

- **`X-Frame-Options: DENY`** stops another site loading `/graph.php` in a
  hidden iframe over its own page (*clickjacking*). Low impact here — there
  are no destructive buttons to trick a click onto — but it is one line.
- **`Content-Security-Policy`** is defence-in-depth for XSS: if an escaping bug
  ever appears, CSP is what stops the injected script from actually running.
  The existing pages use inline `<style>` blocks, so a starting policy is:

```php
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; object-src 'none'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
```

`frame-ancestors 'none'` is the modern replacement for `X-Frame-Options`;
sending both covers older browsers. `'unsafe-inline'` for styles is a
compromise the current markup forces — moving the `<style>` blocks into `.css`
files would let it be dropped, which is a worthwhile follow-up.

Best placed in one shared helper (`src/http-headers.php`) called from
`index.php`, `graph.php`, and `respond()`, so a new page cannot forget.

---

## 13. Note: the dashboard's main query cannot use any index

**Not a security issue.** Raised because it is baked into the schema, and
`arhitecture-decisions.md:366` records that schema changes are manual with no
migration tooling — so it is much cheaper to fix now than later.

`meter_readings` has exactly two indexes (`db/schema.sql:66-96`):

```sql
id INT AUTO_INCREMENT PRIMARY KEY,
UNIQUE KEY uq_meter_readings (device_id, meter_id, pi_time_utc),
```

The dashboard's main read, `fetch_meter_slots()`
(`src/meter-readings.php:46-58`), filters like this:

```sql
WHERE meter_id = :meter_id
  AND pi_time_utc >= :range_start
  AND pi_time_utc <  :range_end
ORDER BY pi_time_utc
```

A composite B-tree index is usable only from its **leftmost column** inward —
the same reason a phone book sorted by (surname, first name) is useless for
finding everyone named "Peter". The index starts with `device_id`, which this
query never mentions. So MySQL cannot use it, and the dashboard's most frequent
query is a **full table scan** on the largest table.

The numbers are harmless today: one reading per 15 minutes is ~35,000 rows per
meter-year. But the query runs every 15 seconds per open tab
(`graph.js:341`), it scans the whole table each time, and the cost grows with
history while the polling never stops.

Two fixes, either fine:

**Option A — reorder the unique key.** The uniqueness guarantee is identical
(a set of columns is unique regardless of order), and the leftmost column then
matches the query:

```sql
ALTER TABLE meter_readings
    DROP INDEX uq_meter_readings,
    ADD UNIQUE KEY uq_meter_readings (meter_id, device_id, pi_time_utc);
```

Caution: the composite foreign key at `db/schema.sql:91-95` needs an index it
can use, so drop and re-add it in the same migration, or verify MySQL keeps a
usable one.

**Option B — add a second index.** Simpler and touches nothing existing:

```sql
ALTER TABLE meter_readings
    ADD KEY idx_meter_time (meter_id, pi_time_utc);
```

This also serves the lookback query at `src/meter-readings.php:62-73`
(`WHERE meter_id = ? AND pi_time_utc < ? ORDER BY pi_time_utc DESC LIMIT 1`),
turning it into a single index seek.

Option B costs one extra B-tree per insert — irrelevant at one insert per
meter per 15 minutes, exactly as the existing schema comment
(`db/schema.sql:112-120`) reasons about the unique key. Verify with:

```sql
EXPLAIN SELECT pi_time_utc, energy_now_kwh, energy_delta_kwh
  FROM meter_readings
 WHERE meter_id = 'mock-meter-001'
   AND pi_time_utc >= '2026-08-01 00:00:00'
   AND pi_time_utc <  '2026-08-08 00:00:00'
 ORDER BY pi_time_utc;
```

`type: ALL` and `key: NULL` means a full scan. After the fix expect
`type: range` and `key: idx_meter_time`.

---

## 14. Note: the documentation describes code that no longer exists

Not a code flaw, but this project's documentation *is* its design record — the
comments and `docs/` carry more reasoning than most commercial codebases. That
makes drift costly: a future reader (or a future you) trusts these files.

Current mismatches:

| Document                                | Says                                                   | Reality                                                     |
| --------------------------------------- | ------------------------------------------------------ | ----------------------------------------------------------- |
| `README.md`, `AGENTS.md`                | `public/api-v1.php`                                     | `public/v1/get-meter-data.php`                              |
| `README.md`                             | one `API_TOKEN`                                         | `API_TOKEN_INGEST` **and** `API_TOKEN_GET_USAGE`            |
| `README.md` meter registration          | `INSERT INTO meters (meter_id, name, description, created_at_utc)` | `device_id` is `NOT NULL` — **the command fails as written** |
| `README.md`, `body.json`, `arhitecture-decisions.md:179` | `window_start_utc` / `window_end_utc`  | `pi_time_utc`, plus three energy columns                    |
| `arhitecture-decisions.md:363`          | "no dashboard login or server-side session"             | `src/session.php` exists; `graph.php` requires login        |
| `arhitecture-decisions.md:365`          | "There is no rate limiting or audit log"                | `src/rate-limit.php` exists and is used by all three entry points |
| `README.md` structure diagram           | no `graph.php`, `graph.js`, `dashboard.js`, `session.php`, `rate-limit.php`, `meter-readings.php` | all exist |

The README meter-registration line is the one to fix first, because it is not
merely stale — **it fails**. Anyone following the setup instructions hits a
`NOT NULL` error with no explanation. The corrected sequence needs the device
row first:

```powershell
mysql.exe -u root -p -e "INSERT INTO iot_metering.devices (device_id, name, description, created_at_utc) VALUES ('mock-device-001', 'Development gateway', 'Local testing', UTC_TIMESTAMP());"

mysql.exe -u root -p -e "INSERT INTO iot_metering.meters (meter_id, device_id, name, description, created_at_utc) VALUES ('mock-meter-001', 'mock-device-001', 'Development meter', 'Local testing', UTC_TIMESTAMP());"
```

`docs/security_ideas.txt` is worth keeping and expanding — its single entry
(`display_errors=Off`, `log_errors=On` on Plesk) is correct and important, and
`src/db.php:48-52` was clearly written with exactly that concern in mind.

---

## Suggested order of work

Grouped so that related edits land together, cheapest-per-risk first.

**Round 1 — secrets and transport (highest value, small diffs)**

1. Verify on the live host whether the landing page says "Encrypted (HTTPS):
   NO". This is one browser visit and it decides how urgent #2 is.
2. Finding #1: untrack `.env`, add the ignore rule, commit `.env.example`,
   **rotate all four secrets.**
3. Finding #2: add `request_is_https()`, use it in both places.

**Round 2 — the authentication surface**

4. Finding #3: `$failOpen` parameter, login fails closed, global login counter,
   move counter storage out of `/tmp`.
5. Finding #4: dashboard behind the login, delete the token field, `dashboard.js`
   switches to `credentials: 'same-origin'`.
6. Finding #7: fix the Sign out link (one line, and it belongs with #4).

**Round 3 — contract correctness**

7. Finding #5: `JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE` in
   `respond()`, plus `Cache-Control: no-store` and `X-Content-Type-Options`.
8. Findings #8 and #11: return 400 where the client is at fault.
9. Findings #9, #10, #12: scheme case, `getenv($key, true)`, security headers.

**Round 4 — before the project grows**

10. Finding #13: add `idx_meter_time`. Cheapest while the table is small.
11. Finding #14: fix the README registration commands, refresh the limitations
    list in `arhitecture-decisions.md`.
12. Finding #6: per-device tokens, and scope both `get-meter-data.php` and
    `list-meters.php` to the calling device. Do this **before** registering a
    second device, not after — afterwards it is a data-disclosure incident
    rather than a refactor.

Findings #1, #2, #3 and #4 are the ones that would change an attacker's odds.
Everything else is craft.
