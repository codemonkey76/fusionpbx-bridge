# AGENTS.md — FusionPBX Bridge

## What this is

A zero-dependency plain-PHP read-only API deployed on each FusionPBX telephony server. It bridges FusionPBX's PostgreSQL CDR data to the Phoneus billing system. The entire application is a single file: `index.php`.

No Composer. No framework. No build step. Deployment = copy files + edit config.

---

## Commands

```bash
# Format code
php vendor/bin/php-cs-fixer fix

# Generate an API key for config.php
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"

# Deploy
cp config.example.php config.php
# then edit config.php manually
```

There is no test suite, no build pipeline, no CI config.

---

## Architecture

Everything lives in `index.php`:
1. Load `config.php` (one directory up from `index.php`, i.e. `../config.php`)
2. Authenticate via `Authorization: Bearer` header using `hash_equals()` (timing-safe)
3. Route on `$_SERVER['REQUEST_URI']` path — two routes only
4. DB connection via a lazy static singleton `db()` function using PDO + PostgreSQL

`config.php` is never committed (gitignored). `config.example.php` is the template.

---

## Routing

| Route | Handler |
|---|---|
| `GET /api/health` | DB ping, returns status + server time |
| `GET /api/calls` | Paginated CDR query from `v_xml_cdr` |
| Anything else | 404 |

Both routes require a valid Bearer token. Auth runs before routing — no unauthenticated path reaches any handler.

---

## Key Gotchas

**`has_more` detection without COUNT:** The calls query fetches `$limit + 1` rows, then checks `count($rows) > $limit` and slices back to `$limit`. Never add a `COUNT(*)` query for pagination.

**`billsec` not `duration`:** Only `billsec` (post-answer seconds) is exposed as `billable_seconds`. `duration` includes ring time and must never be substituted.

**Cursor column is `start_stamp`, not `answer_stamp`:** Pagination uses `start_stamp > :after` ordered by `start_stamp ASC`. The caller advances the cursor using `started_at` of the last returned record.

**ISO 8601 parsing:** The `after` parameter accepts both `DateTimeInterface::ATOM` format and `Y-m-d\TH:i:s\Z` (UTC with literal Z). If neither parses, returns 400.

**`started_at` output format:** Always formatted as `DateTimeInterface::ATOM` (includes timezone offset). The DB stores timestamps without timezone; `DateTimeImmutable` constructs from whatever PostgreSQL returns.

**Path stripping:** `$path = rtrim(parse_url(...), '/')` — trailing slashes are stripped, so `/api/health/` and `/api/health` are the same route.

**DB connection timeout:** PDO is configured with `PDO::ATTR_TIMEOUT => 5` (5 seconds). The health endpoint catches `Throwable` (not just `PDOException`) to handle both connection and query failures gracefully.

**Error visibility:** `error_log()` is used for DB errors. `display_errors` must be `Off` in production — never echo raw exception messages.

---

## Config Shape

```php
return [
    'api_key' => 'hex64chars',
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 5432,
        'name'     => 'fusionpbx',
        'user'     => 'phoneus_bridge',
        'password' => 'secret',
    ],
];
```

---

## Database

- PostgreSQL, read-only access to `v_xml_cdr` only
- The DB user (`phoneus_bridge`) has `SELECT` on `v_xml_cdr` only — no other tables
- Relevant columns: `xml_cdr_uuid`, `domain_name`, `caller_id_number`, `caller_destination`, `direction`, `start_stamp`, `billsec`, `hangup_cause`
- Filter: `billsec > 0` (excludes unanswered/missed calls at query level)

---

## Code Style

- `declare(strict_types=1)` at top of every PHP file
- `php-cs-fixer` with `@auto` ruleset (`setRiskyAllowed(false)`)
- Functions return `never` (via `exit`) where applicable — `json_out()` and `abort()` are `never`-typed
- Named PDO parameters (`:domain`, `:after`, `:limit`) — never positional

---

## What Phoneus Expects

- Deduplicates on `uid` (`xml_cdr_uuid`) — duplicate records from overlapping poll windows are silently ignored
- Polls per `domain` — one FusionPBX server can host multiple domains/customers
- Stores `last_polled_at` per account; uses `has_more` to page through large windows before advancing cursor
