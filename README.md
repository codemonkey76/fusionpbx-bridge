# FusionPBX Bridge API — Implementation Plan

## Overview

A minimal plain-PHP API deployed on each FusionPBX server. It connects read-only to the
FusionPBX PostgreSQL database and exposes two endpoints that Phoneus polls to retrieve
billable call records.

One instance is deployed per FusionPBX server. Multiple customers can share a single
server — calls are scoped by `domain` parameter.

**Why plain PHP, not a framework:**
FusionPBX servers are telephony servers, not web app servers. The entire app is one SQL
query and a bearer token check. Plain PHP has zero dependencies, trivial deployment
(copy files, done), and minimal attack surface on a production PBX. FusionPBX already
runs on PHP so the runtime is guaranteed to be present.

---

## File Structure

```
fusionpbx-bridge/
├── public/
│   └── index.php       # Entire application — routing, auth, handlers
├── config.php          # Server-specific config (not in git)
├── config.example.php  # Template committed to git
├── .htaccess           # Apache rewrite (or see nginx config below)
└── README.md
```

---

## Configuration

**`config.example.php`** — commit this to git:

```php
<?php
return [
    'api_key' => 'CHANGE_ME',          // Minimum 32 random characters
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 5432,
        'name'     => 'fusionpbx',
        'user'     => 'phoneus_bridge', // Read-only user (see Deployment)
        'password' => 'CHANGE_ME',
    ],
];
```

Copy to `config.php` on the server and fill in real values. `config.php` is in `.gitignore`.

---

## Web Server Config

**Apache `.htaccess`** (place at document root):

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ public/index.php [QSA,L]
```

Or point the virtual host document root directly at `public/` and use:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]
```

**Nginx** (inside `server {}` block):

```nginx
root /var/www/fusionpbx-bridge/public;
index index.php;

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    fastcgi_index index.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

---

## API Contract

### `GET /api/health`

**Request:**

```
GET /api/health
Authorization: Bearer {api_key}
```

**Response `200`:**

```json
{
  "status": "ok",
  "database": "connected",
  "server_time": "2026-05-01T09:00:00+10:00"
}
```

**Response `503`** (database unreachable):

```json
{
  "status": "error",
  "database": "unavailable"
}
```

---

### `GET /api/calls`

**Request:**

```
GET /api/calls?domain=company.pbx.example.com&after=2026-05-01T00:00:00Z&limit=200
Authorization: Bearer {api_key}
```

**Parameters:**

| Param    | Required | Description                                                                          |
| -------- | -------- | ------------------------------------------------------------------------------------ |
| `domain` | Yes      | FusionPBX domain name                                                                |
| `after`  | No       | ISO 8601 datetime — return records where `start_stamp > after`. Defaults to 24h ago. |
| `limit`  | No       | Max records per page. Default `200`, max `500`.                                      |

**Response `200`:**

```json
{
  "data": [
    {
      "uid": "550e8400-e29b-41d4-a716-446655440000",
      "from_number": "0299991234",
      "to_number": "0412345678",
      "billable_seconds": 142,
      "started_at": "2026-05-01T09:15:00+10:00",
      "direction": "outbound",
      "domain": "company.pbx.example.com",
      "hangup_cause": "NORMAL_CLEARING"
    }
  ],
  "meta": {
    "count": 1,
    "limit": 200,
    "has_more": false
  }
}
```

**Notes:**

- Only answered calls are returned (`billsec > 0`) — missed/unanswered calls are excluded at the query level
- `billable_seconds` is `billsec` from FusionPBX (seconds from answer to hangup), **not** `duration` —
  except on loopback-forwarded calls, where `billsec` is truncated and the value is derived from the
  leg pair instead (see [Loopback-forwarded calls](#loopback-forwarded-calls))
- `started_at` is always ISO 8601 with timezone
- `has_more: true` means page again with `after` set to the `started_at` of the last returned record

---

## Cursor Strategy

Phoneus stores `last_polled_at` per `fusion_pbx_account`. On each poll cycle:

1. Call `GET /api/calls?domain={domain}&after={last_polled_at}`
2. Process all returned records through `CallRatingService`
3. If `has_more: true` — advance `after` to the `started_at` of the last record, repeat
4. When `has_more: false` — update `last_polled_at` to `started_at` of the last record

**Deduplication:** Phoneus has a `UNIQUE(source, source_reference)` constraint on
`pending_charges` where `source = 'fusionpbx'` and `source_reference = uid`. Any
duplicate records from overlapping poll windows are silently ignored on insert.

---

## Deployment

### 1. Create a read-only PostgreSQL user on each FusionPBX server

```sql
CREATE USER phoneus_bridge WITH PASSWORD 'strong_random_password';
GRANT CONNECT ON DATABASE fusionpbx TO phoneus_bridge;
GRANT USAGE ON SCHEMA public TO phoneus_bridge;
GRANT SELECT ON v_xml_cdr TO phoneus_bridge;
```

Pairing the legs of a forwarded call looks up `v_xml_cdr` by `bridge_uuid`. If that column
is not already indexed on a busy server, add it (as a superuser, not `phoneus_bridge`):

```sql
CREATE INDEX CONCURRENTLY IF NOT EXISTS v_xml_cdr_bridge_uuid_idx ON v_xml_cdr (bridge_uuid);
```

### 2. Deploy the app

```bash
git clone {repo} /var/www/fusionpbx-bridge
cd /var/www/fusionpbx-bridge
cp config.example.php config.php
# Edit config.php — set api_key and db credentials
```

No `composer install`. No build step. Copy files, configure, done.

### 3. Generate the API key

```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

Store the output in `config.php` as `api_key`. The same value is stored (encrypted)
in Phoneus under the FusionPBX server record.

### 4. Register in Phoneus

In Phoneus → Billing Settings → FusionPBX Servers, add the server with:

- Base URL of the bridge (e.g. `https://pbx1.example.com/bridge`)
- The API key
- Customer account mappings (customer ↔ domain)

---

## Security Considerations

- Restrict access to the Phoneus server IP via firewall if possible — the bridge has no need to be publicly accessible
- `api_key` must be at minimum 32 random characters (`bin2hex(random_bytes(32))` = 64 hex chars)
- The DB user has `SELECT` only on `v_xml_cdr` — no write access to FusionPBX data whatsoever
- All traffic over HTTPS — use a self-signed cert if no public domain, configure Phoneus to pin or accept it
- PHP error display must be off in production (`display_errors = Off` in `php.ini`)
- Errors are logged via `error_log()` — check the server's PHP error log for diagnostics

---

## FusionPBX CDR Table Reference

For reference, the relevant columns in `v_xml_cdr`:

| Column               | Used | Notes                                                             |
| -------------------- | ---- | ----------------------------------------------------------------- |
| `xml_cdr_uuid`       | ✓    | Our `uid` — unique deduplication key                              |
| `domain_name`        | ✓    | Scopes calls to a customer                                        |
| `caller_id_number`   | ✓    | Originating number                                                |
| `caller_destination` | ✓    | Dialled number                                                    |
| `direction`          | ✓    | `inbound` or `outbound`                                           |
| `start_stamp`        | ✓    | Call start — used as cursor                                       |
| `billsec`            | ✓    | **Billable seconds only** (post-answer). Use this, not `duration` |
| `hangup_cause`       | ✓    | For diagnostics                                                   |
| `destination_number` | ✓    | Where the call was actually sent — differs from `caller_destination` on a divert |
| `answer_stamp`       | ✓    | Start of the billable window on a loopback-forwarded outbound leg |
| `end_stamp`          | ✓    | End of the billable window — read off the paired inbound leg      |
| `bridge_uuid`        | ✓    | Leg linkage — pairs the two legs of a forwarded call              |
| `leg`                | ✓    | `a`/`b` — drives the leg-aware selection when `log-b-leg` is on   |
| `duration`           | ✗    | Total duration including ring time — not used                     |
| `missed_call`        | ✗    | Not needed — filtered out by `billsec > 0`                        |

### Loopback-forwarded calls

When FreeSWITCH forwards an inbound DID to an external number it bridges through a
`loopback/` channel, whose other half re-enters the dialplan and bridges out a gateway.
Once the two real endpoints are talking, the loopback pair removes itself from the media
path and hangs up (`loopback_bowout`, on by default). That closes the outbound leg's CDR
immediately, so its `billsec` covers only the second or two before the bowout — while the
conversation continues on the inbound leg for as long as the parties talk. Reporting
`billsec` verbatim under-bills those calls by an order of magnitude.

Both legs are `direction`-opposite, share a `domain_name`, and — because each leg's
`bridge_uuid` points at the loopback channel rather than at the other leg — carry the
**same** `bridge_uuid`. `/api/calls` uses that to bill an outbound leg from its own
`answer_stamp` to the paired inbound leg's `end_stamp`, matching what the carrier records.
Calls with no such pair fall back to `billsec`, so ordinary traffic is untouched.

Whether a deployment is affected depends on its dialplan style — check with:

```sql
SELECT domain_name,
       count(*) AS answered_ob,
       sum(CASE WHEN billsec <= 3 THEN 1 ELSE 0 END) AS under_3s
FROM v_xml_cdr
WHERE direction = 'outbound' AND billsec > 0
  AND start_stamp >= now() - interval '30 days'
GROUP BY domain_name
HAVING count(*) > 50
ORDER BY 3 DESC;
```

A domain that forwards most of its inbound traffic shows nearly all answered outbound legs
at three seconds or less. The ratio varies enormously between domains on the same server,
so a fleet can look healthy in aggregate while individual domains are almost entirely wrong.
