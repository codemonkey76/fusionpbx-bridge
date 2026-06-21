<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config.php';

// ── Helpers ───────────────────────────────────────────────────────────────

function json_out(int $status, array $data): never
{
  http_response_code($status);
  header('Content-Type: application/json');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function abort(int $status, string $message): never
{
  json_out($status, ['error' => $message]);
}

// ── Auth ─────────────────────────────────────────────────────────────────

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token      = str_starts_with($authHeader, 'Bearer ')
  ? substr($authHeader, 7)
  : '';

if (! hash_equals($config['api_key'], $token)) {
  abort(401, 'Unauthorized');
}

// ── Routing ──────────────────────────────────────────────────────────────

$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

// ── Database connection (lazy — only opened if needed) ────────────────────

function db(array $cfg): PDO
{
  static $pdo = null;
  if ($pdo === null) {
    $dsn = "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']}";
    $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
      PDO::ATTR_TIMEOUT            => 5,
    ]);
  }
  return $pdo;
}

// ── GET /health ───────────────────────────────────────────────────────

if ($path === '/health') {
  try {
    db($config['db'])->query('SELECT 1');
    json_out(200, [
      'status'      => 'ok',
      'database'    => 'connected',
      'server_time' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    ]);
  } catch (Throwable) {
    json_out(503, [
      'status'   => 'error',
      'database' => 'unavailable',
    ]);
  }
}

// ── GET /calls ────────────────────────────────────────────────────────

if ($path === '/calls') {
  $domain = trim($_GET['domain'] ?? '');
  if ($domain === '') {
    abort(400, 'Missing required parameter: domain');
  }

  // after: ISO 8601 datetime, default 24 hours ago
  $afterRaw = trim($_GET['after'] ?? '');
  if ($afterRaw !== '') {
    $after = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $afterRaw)
      ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $afterRaw)
      ?: null;
    if ($after === null) {
      abort(400, 'Invalid after parameter — expected ISO 8601 datetime');
    }
  } else {
    $after = new DateTimeImmutable('-24 hours');
  }

  $limit = min(500, max(1, (int) ($_GET['limit'] ?? 200)));

  try {
    $pdo  = db($config['db']);

    // Leg-aware billing selection. This query is correct regardless of the
    // server's `log-b-leg` setting:
    //
    //   • log-b-leg OFF — only a-legs exist in v_xml_cdr, so only the first
    //     branch (COALESCE(leg,'a') = 'a') ever matches. Output is identical
    //     to the original single-leg behaviour.
    //
    //   • log-b-leg ON — every call writes an a-leg AND a b-leg. We bill:
    //       - every a-leg (normal inbound, normal outbound, and the inbound
    //         side of a forwarded call), PLUS
    //       - a b-leg ONLY when it is the outbound side of a call whose
    //         a-leg was inbound — i.e. an inbound call that was bridged back
    //         out a gateway (call-forward to PSTN, IVR-to-external, external
    //         ring-group member, etc.). This is the second billable leg.
    //     The b-leg of a *normal outbound* call is excluded because its
    //     parent a-leg is outbound, not inbound — so ordinary calls are
    //     never double-billed.
    //
    // Leg linkage: FreeSWITCH stores the bridged peer's call-uuid in
    // bridge_uuid. Depending on version the pointer sits on either leg, so
    // we match both directions. VERIFY against your own data with the
    // diagnostic in the notes, and adjust the EXISTS join if needed.
    //
    // NOTE: xml_cdr_uuid and bridge_uuid are NOT the same type across
    // FusionPBX versions -- some store them as native `uuid`, others as
    // `text`/`varchar`. Postgres refuses `uuid = text`, so both sides of
    // the join are cast to ::text, which is valid no matter how each
    // server typed the columns.
    $stmt = $pdo->prepare("
            SELECT
                c.xml_cdr_uuid     AS uid,
                c.caller_id_number AS from_number,
                COALESCE(NULLIF(c.caller_destination, ''), c.destination_number) AS to_number,
                -- The raw dialled/bridged destination. On a forwarded call it
                -- differs from to_number: to_number resolves to caller_destination
                -- (the DID that was called), while destination_number is where the
                -- call was actually sent (e.g. an external mobile). Phoneus uses
                -- this to split a diverted DID call into its inbound + outbound legs.
                c.destination_number AS destination_number,
                c.billsec          AS billable_seconds,
                c.start_stamp      AS started_at,
                c.direction,
                c.domain_name      AS domain,
                c.hangup_cause
            FROM v_xml_cdr c
            WHERE c.domain_name = :domain
              AND c.billsec     > 0
              AND c.start_stamp > :after
              AND (
                    COALESCE(c.leg, 'a') = 'a'
                    OR (
                        c.leg = 'b'
                        AND c.direction = 'outbound'
                        AND EXISTS (
                            SELECT 1
                            FROM v_xml_cdr p
                            WHERE p.direction = 'inbound'
                              AND (
                                    p.xml_cdr_uuid::text = c.bridge_uuid::text
                                 OR p.bridge_uuid::text  = c.xml_cdr_uuid::text
                              )
                        )
                    )
                  )
            ORDER BY c.start_stamp ASC
            LIMIT :limit
        ");

    // Fetch one extra record to determine has_more without a COUNT query
    $stmt->bindValue(':domain', $domain);
    $stmt->bindValue(':after', $after->format('Y-m-d H:i:s'));
    $stmt->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
    $stmt->execute();

    $rows    = $stmt->fetchAll();
    $hasMore = count($rows) > $limit;
    $rows    = array_slice($rows, 0, $limit);

    json_out(200, [
      'data' => array_map(fn($r) => [
        'uid'                => $r->uid,
        'from_number'        => $r->from_number,
        'to_number'          => $r->to_number,
        'destination_number' => $r->destination_number,
        'billable_seconds'   => (int) $r->billable_seconds,
        'started_at'         => (new DateTimeImmutable($r->started_at))
          ->format(DateTimeInterface::ATOM),
        'direction'          => $r->direction,
        'domain'             => $r->domain,
        'hangup_cause'       => $r->hangup_cause,
      ], $rows),
      'meta' => [
        'count'    => count($rows),
        'limit'    => $limit,
        'has_more' => $hasMore,
      ],
    ]);
  } catch (PDOException $e) {
    error_log('fusionpbx-bridge db error: ' . $e->getMessage());
    abort(500, 'Database error');
  }
}

// ── GET /domains ────────────────────────────────────────────────────────

if ($path === '/domains') {
  try {
    $pdo  = db($config['db']);
    $stmt = $pdo->query("
              SELECT domain_name
              FROM v_domains
              WHERE domain_enabled = 'true'
              ORDER BY domain_name ASC
          ");
    $domains = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'domain_name');
    json_out(200, ['data' => $domains]);
  } catch (PDOException $e) {
    error_log('fusionpbx-bridge db error: ' . $e->getMessage());
    abort(500, 'Database error');
  }
}
// ── 404 ───────────────────────────────────────────────────────────────────

abort(404, 'Not found');
