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

// ── GET /api/health ───────────────────────────────────────────────────────

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

// ── GET /api/calls ────────────────────────────────────────────────────────

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
        $stmt = $pdo->prepare("
            SELECT
                xml_cdr_uuid        AS uid,
                caller_id_number    AS from_number,
                caller_destination  AS to_number,
                billsec             AS billable_seconds,
                start_stamp         AS started_at,
                direction,
                domain_name         AS domain,
                hangup_cause
            FROM v_xml_cdr
            WHERE domain_name  = :domain
              AND billsec       > 0
              AND start_stamp   > :after
            ORDER BY start_stamp ASC
            LIMIT :limit
        ");

        // Fetch one extra record to determine has_more without a COUNT query
        $stmt->execute([
            ':domain' => $domain,
            ':after'  => $after->format('Y-m-d H:i:s'),
            ':limit'  => $limit + 1,
        ]);

        $rows    = $stmt->fetchAll();
        $hasMore = count($rows) > $limit;
        $rows    = array_slice($rows, 0, $limit);

        json_out(200, [
            'data' => array_map(fn($r) => [
                'uid'              => $r->uid,
                'from_number'      => $r->from_number,
                'to_number'        => $r->to_number,
                'billable_seconds' => (int) $r->billable_seconds,
                'started_at'       => (new DateTimeImmutable($r->started_at))
                                        ->format(DateTimeInterface::ATOM),
                'direction'        => $r->direction,
                'domain'           => $r->domain,
                'hangup_cause'     => $r->hangup_cause,
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

// ── 404 ───────────────────────────────────────────────────────────────────

abort(404, 'Not found');

