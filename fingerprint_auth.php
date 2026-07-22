<?php
/**
 * Passwordless login: Raw scan → probe FMD via dpfj.dll, compared to v3 FMD (or legacy v2) in user.db.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/userdb_fingerprint_helpers.php';

$fpMatchEngineFile = __DIR__ . '/fingerprint_local_match.php';
if (function_exists('opcache_invalidate')) {
    opcache_invalidate($fpMatchEngineFile, true);
}
require $fpMatchEngineFile;

if (function_exists('fp_ensure_native_match_loaded')) {
    fp_ensure_native_match_loaded();
}

if (!function_exists('fp_rank_user_rows') || !function_exists('fp_select_best_user_row')
    || !function_exists('fp_select_best_from_ranked') || !function_exists('fp_collect_template_entries')) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => false,
        'code' => 'server_error',
        'detail' => ['reason' => 'match_engine_outdated', 'file' => $fpMatchEngineFile],
    ]);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

/**
 * @param array<string, mixed> $detail
 * @return never
 */
function fp_json_error(string $code, array $detail = []): void {
    $payload = ['ok' => false, 'code' => $code];
    if ($detail !== []) {
        $payload['detail'] = $detail;
    }
    echo json_encode($payload);
    exit;
}

/**
 * @param list<array{row: array<string,mixed>, score: float}> $ranked
 * @return list<array{id: int, username: string, role: string, score: float}>
 */
function fp_ranked_summary(array $ranked, int $limit = 10): array {
    $out = [];
    foreach (array_slice($ranked, 0, $limit) as $entry) {
        $row = $entry['row'];
        $out[] = [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'role' => (string) $row['role'],
            'score' => round($entry['score'], 4),
        ];
    }
    return $out;
}

/**
 * @param array<string, mixed> $user
 */
function fp_complete_login(array $user): void {
    $pos_db_file = realpath(dirname(__FILE__) . '/pos.db');
    if ($pos_db_file === false) {
        throw new RuntimeException('pos.db not found');
    }
    $posDb = new PDO('sqlite:' . $pos_db_file);
    $posDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    session_regenerate_id(true);

    $logStmt = $posDb->prepare("INSERT INTO user_log (user_id, action_type) VALUES (:username, 'login')");
    $logStmt->execute([':username' => $user['username']]);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];

    session_write_close();

    if ($user['role'] === 'admin') {
        $redirect = 'admin/home';
    } elseif ($user['role'] === 'manager') {
        $redirect = 'manager/home';
    } elseif ($user['role'] === 'waitress') {
        $redirect = 'waitress/home';
    } elseif ($user['role'] === 'hubbly') {
        $redirect = 'hubbly/home';
    } else {
        $redirect = 'home';
    }

    echo json_encode(['ok' => true, 'redirect' => $redirect]);
}

/**
 * @param list<array{row: array<string,mixed>, score: float}> $ranked
 * @return never
 */
function fp_respond_no_match(array $ranked, string $sample): void {
    $cfg = fp_match_settings();
    $summary = fp_ranked_summary($ranked);
    $top = $ranked[0] ?? null;
    $second = $ranked[1] ?? null;

    fp_json_error('no_match', [
        'reason' => 'below_threshold_or_ambiguous',
        'match_threshold' => $cfg['match_threshold'],
        'match_margin' => $cfg['match_margin'],
        'top_score' => $top ? round($top['score'], 4) : null,
        'second_score' => $second ? round($second['score'], 4) : null,
        'score_gap' => ($top && $second) ? round($top['score'] - $second['score'], 4) : null,
        'enrolled_count' => count($ranked),
        'ranked' => $summary,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fp_json_error('bad_request', ['reason' => 'method_not_post']);
}

if (empty($_POST['data'])) {
    fp_json_error('no_probe', ['reason' => 'scan_missing_data']);
}

if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    $role = strtolower((string) $_SESSION['role']);
    if ($role === 'admin') {
        $redirect = 'admin/home';
    } elseif ($role === 'manager') {
        $redirect = 'manager/home';
    } elseif ($role === 'waitress') {
        $redirect = 'waitress/home';
    } elseif ($role === 'hubbly') {
        $redirect = 'hubbly/home';
    } else {
        $redirect = 'home';
    }
    echo json_encode(['ok' => true, 'redirect' => $redirect]);
    exit;
}

$user_data = json_decode($_POST['data'], true);
if (!is_array($user_data)) {
    fp_json_error('no_probe', ['reason' => 'scan_invalid_payload']);
}

// #region agent log
$authStartMs = (int) round(microtime(true) * 1000);
fp_debug_log('fingerprint_auth.php', 'auth_start', [
    'has_raw_scan' => !empty($user_data['raw_scan']),
    'has_username' => !empty($user_data['username']),
    'native_enabled' => function_exists('fp_native_fmd_enabled') && fp_native_fmd_enabled(),
], 'H2,H4');
// #endregion

try {
    $db_file = realpath(dirname(__FILE__) . '/user.db');
    if ($db_file === false) {
        throw new RuntimeException('user.db not found');
    }
    $userDb = new PDO('sqlite:' . $db_file);
    $userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    userdb_ensure_fingerprint_columns($userDb);
    $rows = userdb_fetch_users_with_templates($userDb);
} catch (Throwable $e) {
    error_log('fingerprint_auth user.db: ' . $e->getMessage());
    fp_json_error('server_error', ['reason' => 'user_db_error', 'message' => $e->getMessage()]);
}

if ($rows === []) {
    fp_json_error('no_enrolled', ['reason' => 'no_enrolled_users_in_database']);
}

$usernameHint = '';
if (!empty($user_data['username']) && is_string($user_data['username'])) {
    $usernameHint = trim($user_data['username']);
}
$scopedToSingle = false;
if ($usernameHint !== '') {
    $rows = array_values(array_filter($rows, static function (array $row) use ($usernameHint): bool {
        return strcasecmp((string) ($row['username'] ?? ''), $usernameHint) === 0;
    }));
    if ($rows === []) {
        fp_json_error('no_match', ['reason' => 'username_not_enrolled', 'username' => $usernameHint]);
    }
    $scopedToSingle = count($rows) === 1;
}

$plan = fp_collect_template_entries($rows);
$sample = '';
$ranked = [];

if (!empty($user_data['raw_scan']) && is_array($user_data['raw_scan'])
    && $plan['native_tokens'] !== []
    && function_exists('fp_native_verify_scan_raw')) {
    $verify = fp_native_verify_scan_raw($user_data['raw_scan'], $plan['native_tokens']);
    if ($verify === null) {
        fp_json_error('no_probe', ['reason' => 'scan_quality_low', 'detail' => 'Could not read fingerprint. Press firmly, cover the reader fully, and scan again.']);
    }
    $sample = $verify['fmd'];
    $ranked = fp_rank_from_verify_result($plan, $verify['scores'], $sample);
    // #region agent log
    fp_debug_log('fingerprint_auth.php', 'verify_scan', [
        'finger_pos' => $verify['finger_pos'],
        'probe_len' => strlen($sample),
    ], 'H2,H4');
    // #endregion
} else {
    $probeToken = fp_resolve_probe_token('', $user_data);
    if ($probeToken === null) {
        fp_json_error('no_probe', ['reason' => 'scan_quality_low', 'detail' => 'Could not build fingerprint template. Press firmly and scan again.']);
    }
    $sample = $probeToken;
    $ranked = fp_rank_user_rows($rows, $sample);
}

$matchedUser = fp_select_best_from_ranked($ranked, $scopedToSingle);

// #region agent log
fp_debug_log('fingerprint_auth.php', 'auth_complete', [
    'matched' => $matchedUser !== null,
    'matched_username' => $matchedUser ? (string) ($matchedUser['username'] ?? '') : null,
    'candidate_count' => count($rows),
    'total_duration_ms' => (int) round(microtime(true) * 1000) - $authStartMs,
    'probe_len' => strlen($sample),
], 'H2,H3');
// #endregion

if ($matchedUser === null) {
    fp_respond_no_match($ranked, $sample);
}

try {
    fp_complete_login($matchedUser);
} catch (PDOException $e) {
    error_log('fingerprint_auth pos.db: ' . $e->getMessage());
    fp_json_error('server_error', ['reason' => 'login_database_error', 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('fingerprint_auth: ' . $e->getMessage());
    fp_json_error('server_error', ['reason' => 'login_exception', 'message' => $e->getMessage()]);
}
