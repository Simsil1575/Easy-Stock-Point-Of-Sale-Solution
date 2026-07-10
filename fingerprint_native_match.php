<?php
/**
 * Native DigitalPersona FMD via dpfj.dll.
 * PHP FFI cannot use __stdcall on Windows, so dpfj calls run in fingerprint_dpfj_worker.py.
 */
declare(strict_types=1);

const FP_NATIVE_DEFAULT_MAX_SCORE = 21474;
const FP_NATIVE_MAX_FMD_SIZE = 1562;
const FP_NATIVE_FMD_TYPE = 0x001B0001; // ANSI 378-2004
const FP_NATIVE_CBEFF_ID = 51;

/** @var bool|null */
$GLOBALS['__fp_native_ready'] = null;

function fp_native_fmd_enabled(): bool {
    static $enabled = null;
    if ($enabled !== null) {
        return $enabled;
    }
    $enabled = true;
    $path = __DIR__ . '/fingerprint_config.php';
    if (is_file($path)) {
        $loaded = require $path;
        if (is_array($loaded) && array_key_exists('native_fmd_enabled', $loaded)) {
            $enabled = (bool) $loaded['native_fmd_enabled'];
        }
    }
    return $enabled;
}

function fp_native_dll_path(): string {
    $env = getenv('FP_NATIVE_DLL');
    if ($env !== false && $env !== '' && is_file($env)) {
        return $env;
    }
    foreach (['C:\\Windows\\System32\\dpfj.dll', 'C:\\Program Files\\DigitalPersona\\Bin\\dpfj.dll'] as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return 'dpfj.dll';
}

function fp_native_worker_script(): string {
    return __DIR__ . DIRECTORY_SEPARATOR . 'fingerprint_dpfj_worker.py';
}

function fp_native_bundled_python_path(): string {
    return __DIR__ . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'python.exe';
}

/**
 * @return list<string>
 */
function fp_native_python_candidates(): array {
    $root = __DIR__ . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'python';
    return [
        $root . DIRECTORY_SEPARATOR . 'python.exe',
        $root . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe',
        'C:\\Python312\\python.exe',
        'C:\\Python311\\python.exe',
        'C:\\Python310\\python.exe',
    ];
}

function fp_native_python_bin(): string {
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $env = getenv('FP_PYTHON');
    if ($env !== false && $env !== '') {
        $resolved = $env;
        return $resolved;
    }

    $path = __DIR__ . '/fingerprint_config.php';
    if (is_file($path)) {
        $loaded = require $path;
        if (is_array($loaded) && !empty($loaded['python_bin'])) {
            $cfgBin = (string) $loaded['python_bin'];
            if (is_file($cfgBin)) {
                $resolved = $cfgBin;
                return $resolved;
            }
        }
    }

    foreach (fp_native_python_candidates() as $bin) {
        if (is_file($bin)) {
            $resolved = $bin;
            return $resolved;
        }
    }

    $resolved = 'python';
    return $resolved;
}

function fp_native_python_source(): string {
    $env = getenv('FP_PYTHON');
    if ($env !== false && $env !== '') {
        return 'env';
    }

    $path = __DIR__ . '/fingerprint_config.php';
    if (is_file($path)) {
        $loaded = require $path;
        if (is_array($loaded) && !empty($loaded['python_bin'])) {
            $cfgBin = (string) $loaded['python_bin'];
            if (is_file($cfgBin)) {
                return 'config';
            }
        }
    }

    $bundled = fp_native_bundled_python_path();
    if (is_file($bundled)) {
        return 'bundled';
    }

    $bin = fp_native_python_bin();
    foreach (fp_native_python_candidates() as $candidate) {
        if ($bin === $candidate && $candidate !== $bundled) {
            return 'system';
        }
    }

    return $bin === 'python' || $bin === 'python3' ? 'path' : 'unknown';
}

/**
 * @param array<string,mixed> $request
 * @return array<string,mixed>|null
 */
function fp_native_worker_request(array $request): ?array {
    $script = fp_native_worker_script();
    if (!is_file($script)) {
        error_log('fingerprint_native_match: worker script missing');
        return null;
    }

    $payload = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return null;
    }

    $tmpIn = tempnam(sys_get_temp_dir(), 'fpwj_');
    if ($tmpIn === false) {
        return null;
    }
    file_put_contents($tmpIn, $payload);

    $python = fp_native_python_bin();
    if (is_file($python)) {
        $cmd = '"' . $python . '" "' . $script . '" "' . $tmpIn . '"';
    } else {
        $cmd = $python . ' "' . $script . '" "' . $tmpIn . '"';
    }

    // #region agent log
    $workerStartMs = (int) round(microtime(true) * 1000);
    $workerOp = (string) ($request['op'] ?? 'unknown');
    // #endregion
    $stdout = shell_exec($cmd);
    @unlink($tmpIn);

    // #region agent log
    if (function_exists('fp_debug_log')) {
        fp_debug_log('fingerprint_native_match.php:fp_native_worker_request', 'worker_done', [
            'op' => $workerOp,
            'duration_ms' => (int) round(microtime(true) * 1000) - $workerStartMs,
            'stdout_len' => is_string($stdout) ? strlen($stdout) : 0,
            'ok' => is_string($stdout) && trim($stdout) !== '',
        ], 'H2');
    }
    // #endregion

    if (!is_string($stdout) || trim($stdout) === '') {
        error_log('fingerprint_native_match: empty worker output cmd=' . $cmd);
        return null;
    }

    $decoded = json_decode($stdout, true);
    if (!is_array($decoded)) {
        error_log('fingerprint_native_match: invalid worker JSON: ' . substr($stdout, 0, 500));
        return null;
    }

    return $decoded;
}

function fp_native_mark_unavailable(): void {
    $GLOBALS['__fp_native_ready'] = false;
}

function fp_native_is_available(): bool {
    if (!fp_native_fmd_enabled()) {
        return false;
    }
    if ($GLOBALS['__fp_native_ready'] !== null) {
        return $GLOBALS['__fp_native_ready'];
    }
    if (!is_file(fp_native_dll_path())) {
        $GLOBALS['__fp_native_ready'] = false;
        return false;
    }
    if (!is_file(fp_native_worker_script())) {
        $GLOBALS['__fp_native_ready'] = false;
        return false;
    }

    /* Skip ping subprocess — first verify/compare validates the engine. */
    $GLOBALS['__fp_native_ready'] = true;
    return true;
}

function fp_native_b64url_to_bin(string $token): ?string {
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    $s = strtr($token, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad !== 0) {
        $s .= str_repeat('=', 4 - $pad);
    }
    $bin = base64_decode($s, true);
    return $bin === false ? null : $bin;
}

function fp_native_bin_to_b64url(string $binary): string {
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

function fp_native_max_accept_score(): int {
    $path = __DIR__ . '/fingerprint_config.php';
    if (is_file($path)) {
        $loaded = require $path;
        if (is_array($loaded) && isset($loaded['native_max_score'])) {
            return max(1, (int) $loaded['native_max_score']);
        }
    }
    return FP_NATIVE_DEFAULT_MAX_SCORE;
}

function fp_native_score_to_similarity(int $score): float {
    $max = fp_native_max_accept_score();
    if ($score <= 0) {
        return 1.0;
    }
    if ($score <= $max) {
        return 0.95 + (0.05 * (1.0 - ($score / $max)));
    }
    return max(0.0, min(0.94, 1.0 - ($score / 2147483647)));
}

/**
 * @param array<string,mixed>|object $raw client raw scan {width,height,dpi,data}
 * @return array{width:int,height:int,dpi:int,pixels:string}|null
 */
function fp_native_parse_raw_scan($raw): ?array {
    if (is_object($raw)) {
        $raw = (array) $raw;
    }
    if (!is_array($raw)) {
        return null;
    }
    $w = (int) ($raw['width'] ?? $raw['iWidth'] ?? 0);
    $h = (int) ($raw['height'] ?? $raw['iHeight'] ?? 0);
    $dpi = (int) ($raw['dpi'] ?? $raw['iXdpi'] ?? $raw['iYdpi'] ?? 500);
    $data = $raw['data'] ?? $raw['Data'] ?? '';
    if (!is_string($data) || $w < 8 || $h < 8 || $dpi < 100) {
        return null;
    }
    $pixels = fp_native_b64url_to_bin($data);
    if ($pixels === null || strlen($pixels) < ($w * $h)) {
        $pixels = base64_decode(strtr($data, '-_', '+/'), true) ?: null;
    }
    if ($pixels === null || strlen($pixels) < ($w * $h)) {
        return null;
    }
    $need = $w * $h;
    return ['width' => $w, 'height' => $h, 'dpi' => $dpi, 'pixels' => substr($pixels, 0, $need)];
}

function fp_native_create_fmd_from_raw(array $rawScan, int $fingerPos = 0): ?string {
    if (!fp_native_is_available()) {
        return null;
    }
    if (fp_native_parse_raw_scan($rawScan) === null) {
        return null;
    }

    $resp = fp_native_worker_request([
        'op' => 'create_fmd_from_raw',
        'scan' => $rawScan,
        'finger_pos' => $fingerPos,
    ]);
    if (!is_array($resp) || empty($resp['ok']) || empty($resp['fmd'])) {
        return null;
    }

    $bin = fp_native_b64url_to_bin((string) $resp['fmd']);
    return $bin !== null ? $bin : null;
}

/**
 * @param list<mixed> $rawScans
 */
function fp_native_build_enrollment_fmd(array $rawScans, int $fingerPos = 0): ?string {
    if (!fp_native_is_available() || count($rawScans) < 2) {
        return null;
    }

    $scans = [];
    foreach ($rawScans as $scan) {
        if (is_object($scan)) {
            $scan = (array) $scan;
        }
        if (!is_array($scan)) {
            continue;
        }
        if (fp_native_parse_raw_scan($scan) === null) {
            continue;
        }
        $scans[] = $scan;
    }
    if (count($scans) < 2) {
        return null;
    }

    $resp = fp_native_worker_request([
        'op' => 'build_enrollment_fmd',
        'scans' => $scans,
        'finger_pos' => $fingerPos,
    ]);
    if (!is_array($resp) || empty($resp['ok']) || empty($resp['fmd'])) {
        error_log('fingerprint_native_match: build_enrollment_fmd failed: ' . json_encode($resp));
        return null;
    }

    $bin = fp_native_b64url_to_bin((string) $resp['fmd']);
    return $bin !== null ? $bin : null;
}

function fp_native_compare_fmd_bins(string $probeBin, string $storedBin, int $fmdType = FP_NATIVE_FMD_TYPE): ?array {
    if (!fp_native_is_available() || strlen($probeBin) < 16 || strlen($storedBin) < 16) {
        return null;
    }
    if (strlen($probeBin) > FP_NATIVE_MAX_FMD_SIZE || strlen($storedBin) > FP_NATIVE_MAX_FMD_SIZE) {
        return null;
    }

    $resp = fp_native_worker_request([
        'op' => 'compare',
        'probe' => fp_native_bin_to_b64url($probeBin),
        'stored' => fp_native_bin_to_b64url($storedBin),
    ]);
    if (!is_array($resp) || empty($resp['ok']) || !isset($resp['score'])) {
        return null;
    }

    return ['score' => (int) $resp['score'], 'fmd_type' => $fmdType];
}

/**
 * Compare one probe FMD token against many stored FMD tokens in a single worker call.
 *
 * @param list<string> $storedTokens
 * @return list<float|null> similarity 0..1 per stored token (null when compare failed)
 */
function fp_native_batch_fmd_similarity(string $probeToken, array $storedTokens): array {
    if (!fp_native_is_available() || $probeToken === '' || $storedTokens === []) {
        return array_fill(0, count($storedTokens), null);
    }

    $resp = fp_native_worker_request([
        'op' => 'compare_batch',
        'probe' => $probeToken,
        'stored' => array_values($storedTokens),
    ]);
    if (!is_array($resp) || empty($resp['ok']) || !isset($resp['scores']) || !is_array($resp['scores'])) {
        fp_native_mark_unavailable();
        return array_fill(0, count($storedTokens), null);
    }

    $out = [];
    foreach ($resp['scores'] as $rawScore) {
        if ($rawScore === null || !is_numeric($rawScore)) {
            $out[] = null;
            continue;
        }
        $out[] = fp_native_score_to_similarity((int) $rawScore);
    }
    while (count($out) < count($storedTokens)) {
        $out[] = null;
    }

    return $out;
}

/**
 * One worker call: build probe FMD (tries finger positions 0/7/8) + batch compare.
 *
 * @param list<string> $storedTokens
 * @return array{fmd: string, finger_pos: int, scores: list<float|null>}|null
 */
function fp_native_verify_scan_raw(array $rawScan, array $storedTokens): ?array {
    if (!fp_native_is_available() || $storedTokens === []) {
        return null;
    }

    $resp = fp_native_worker_request([
        'op' => 'verify_scan',
        'scan' => $rawScan,
        'stored' => array_values($storedTokens),
        'finger_positions' => [0, 7, 8],
    ]);
    if (!is_array($resp) || empty($resp['ok']) || empty($resp['fmd']) || !isset($resp['scores']) || !is_array($resp['scores'])) {
        fp_native_mark_unavailable();
        return null;
    }

    $scores = [];
    foreach ($resp['scores'] as $rawScore) {
        if ($rawScore === null || !is_numeric($rawScore)) {
            $scores[] = null;
            continue;
        }
        $scores[] = fp_native_score_to_similarity((int) $rawScore);
    }

    return [
        'fmd' => (string) $resp['fmd'],
        'finger_pos' => (int) ($resp['finger_pos'] ?? 0),
        'scores' => $scores,
    ];
}

function fp_native_fmd_similarity(string $probeToken, string $storedToken): ?float {
    $pb = fp_native_b64url_to_bin($probeToken);
    $sb = fp_native_b64url_to_bin($storedToken);
    if ($pb === null || $sb === null) {
        return null;
    }
    $result = fp_native_compare_fmd_bins($pb, $sb);
    if ($result === null) {
        return null;
    }
    return fp_native_score_to_similarity($result['score']);
}

/**
 * @param object|array<string,mixed> $userData login/enroll payload
 */
function fp_native_probe_fmd_from_payload($userData): ?string {
    if (is_object($userData)) {
        $userData = (array) $userData;
    }
    if (!is_array($userData)) {
        return null;
    }
    if (!empty($userData['raw_scan'])) {
        $fmd = fp_native_create_fmd_from_raw((array) $userData['raw_scan'], 0);
        return $fmd !== null ? fp_native_bin_to_b64url($fmd) : null;
    }
    if (!empty($userData['index_finger'][0]) && is_object($userData['index_finger'][0])) {
        $fmd = fp_native_create_fmd_from_raw((array) $userData['index_finger'][0], 0);
        return $fmd !== null ? fp_native_bin_to_b64url($fmd) : null;
    }
    if (!empty($userData['index_finger'][0]) && is_array($userData['index_finger'][0])) {
        $fmd = fp_native_create_fmd_from_raw($userData['index_finger'][0], 0);
        return $fmd !== null ? fp_native_bin_to_b64url($fmd) : null;
    }
    return null;
}

function fp_native_status(): array {
    return [
        'available' => fp_native_is_available(),
        'fmd_enabled' => fp_native_fmd_enabled(),
        'engine' => 'python_ctypes',
        'php_version' => PHP_VERSION,
        'python' => fp_native_python_bin(),
        'python_source' => fp_native_python_source(),
        'python_bundled_path' => fp_native_bundled_python_path(),
        'python_bundled_exists' => is_file(fp_native_bundled_python_path()),
        'worker' => fp_native_worker_script(),
        'worker_exists' => is_file(fp_native_worker_script()),
        'dll' => fp_native_dll_path(),
        'dll_exists' => is_file(fp_native_dll_path()),
    ];
}
