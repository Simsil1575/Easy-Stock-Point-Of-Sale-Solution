<?php
/**
 * Client-only flow: Intermediate samples from finger/scripts stored as v2 JSON in user.db.
 * Verification uses a blended byte similarity plus 1:N ranking with a winner margin.
 * Optional username + role from the login form limits matching to one account (recommended).
 */
declare(strict_types=1);

/** @internal bump when match helpers change (opcache) */
const FP_MATCH_ENGINE_VERSION = 202606301;

// #region agent log
function fp_debug_log(string $location, string $message, array $data = [], string $hypothesisId = '', string $runId = 'pre-fix'): void {
    $entry = [
        'sessionId' => '95eebb',
        'runId' => $runId,
        'hypothesisId' => $hypothesisId,
        'location' => $location,
        'message' => $message,
        'data' => $data,
        'timestamp' => (int) round(microtime(true) * 1000),
    ];
    @file_put_contents(__DIR__ . '/debug-95eebb.log', json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}
// #endregion

function fp_ensure_native_match_loaded(): void {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $file = __DIR__ . '/fingerprint_native_match.php';
    if (is_file($file)) {
        require_once $file;
    }
    $loaded = true;
}

function fp_is_raw_scan($sample): bool {
    if (is_object($sample)) {
        $sample = (array) $sample;
    }
    if (!is_array($sample)) {
        return false;
    }
    return isset($sample['width'], $sample['height'], $sample['data'])
        && (int) $sample['width'] > 0
        && (int) $sample['height'] > 0
        && is_string($sample['data']) && $sample['data'] !== '';
}

function fp_is_raw_scan_list(array $samples): bool {
    return $samples !== [] && fp_is_raw_scan($samples[0]);
}

/**
 * Normalize login/confirm probe to a match token (FMD base64url or intermediate string).
 */
function fp_resolve_probe_token(string $probeInput, $payload = null): ?string {
    $probeInput = trim($probeInput);
    if ($probeInput !== '' && $probeInput[0] === '{') {
        $decoded = json_decode($probeInput, true);
        if (is_array($decoded) && fp_is_raw_scan($decoded)) {
            fp_ensure_native_match_loaded();
            if (function_exists('fp_native_create_fmd_from_raw')) {
                $fmd = fp_native_create_fmd_from_raw($decoded, 0);
                if ($fmd !== null && function_exists('fp_native_bin_to_b64url')) {
                    return fp_native_bin_to_b64url($fmd);
                }
            }
        }
    }
    if ($payload !== null) {
        fp_ensure_native_match_loaded();
        if (function_exists('fp_native_probe_fmd_from_payload')) {
            $fromPayload = fp_native_probe_fmd_from_payload($payload);
            if ($fromPayload !== null) {
                // #region agent log
                fp_debug_log('fingerprint_local_match.php:fp_resolve_probe_token', 'probe_from_native_payload', [
                    'probe_len' => strlen($fromPayload),
                    'native_available' => function_exists('fp_native_is_available') && fp_native_is_available(),
                ], 'H1');
                // #endregion
                return $fromPayload;
            }
            // #region agent log
            fp_debug_log('fingerprint_local_match.php:fp_resolve_probe_token', 'native_payload_probe_failed', [
                'has_raw_scan' => is_array($payload) && !empty($payload['raw_scan']),
            ], 'H4');
            // #endregion
        }
    }
    if ($probeInput !== '') {
        $normalized = fp_normalize_intermediate_token($probeInput);
        // #region agent log
        fp_debug_log('fingerprint_local_match.php:fp_resolve_probe_token', 'probe_from_intermediate_input', [
            'probe_len' => strlen($normalized),
        ], 'H1');
        // #endregion
        return $normalized;
    }
    return null;
}

/**
 * @return array{
 *   enroll_min_consistency: float,
 *   enroll_cross_finger_max: float,
 *   enroll_samples_keep: int
 * }
 */
function fp_enroll_settings(): array {
    $path = __DIR__ . '/fingerprint_config.php';
    $defaults = [
        'enroll_min_consistency' => 0.42,
        'enroll_cross_finger_max' => 0.58,
        'enroll_samples_keep' => 4,
    ];
    if (is_file($path)) {
        $loaded = require $path;
        if (is_array($loaded)) {
            if (isset($loaded['enroll_min_consistency'])) {
                $defaults['enroll_min_consistency'] = (float) $loaded['enroll_min_consistency'];
            }
            if (isset($loaded['enroll_cross_finger_max'])) {
                $defaults['enroll_cross_finger_max'] = (float) $loaded['enroll_cross_finger_max'];
            }
            if (isset($loaded['enroll_samples_keep'])) {
                $defaults['enroll_samples_keep'] = max(2, min(8, (int) $loaded['enroll_samples_keep']));
            }
        }
    }
    $defaults['enroll_min_consistency'] = max(0.30, min(0.99, $defaults['enroll_min_consistency']));
    $defaults['enroll_cross_finger_max'] = max(0.30, min(0.99, $defaults['enroll_cross_finger_max']));
    return $defaults;
}

function fp_is_valid_enrollment_token(string $raw): bool {
    $token = fp_normalize_intermediate_token($raw);
    if ($token === '') {
        return false;
    }
    $blob = fp_decode_intermediate_blob($token);
    return $blob !== null && strlen($blob) >= 16;
}

/**
 * @param list<mixed> $samples
 * @return list<string>
 */
function fp_normalize_enrollment_samples(array $samples): array {
    $out = [];
    foreach ($samples as $s) {
        if (!is_string($s)) {
            continue;
        }
        $n = fp_normalize_intermediate_token($s);
        if ($n !== '' && fp_is_valid_enrollment_token($n)) {
            $out[] = $n;
        }
    }
    return $out;
}

/**
 * @param list<string> $samples
 */
function fp_min_pairwise_similarity(array $samples): float {
    $n = count($samples);
    if ($n < 2) {
        return $n === 1 ? 1.0 : 0.0;
    }
    $min = 1.0;
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $sim = fp_sample_similarity($samples[$i], $samples[$j]);
            if ($sim < $min) {
                $min = $sim;
            }
        }
    }
    return $min;
}

/**
 * @param list<string> $a
 * @param list<string> $b
 */
function fp_max_cross_finger_similarity(array $a, array $b): float {
    $max = 0.0;
    foreach ($a as $sa) {
        foreach ($b as $sb) {
            $sim = fp_sample_similarity($sa, $sb);
            if ($sim > $max) {
                $max = $sim;
            }
        }
    }
    return $max;
}

/**
 * Drop weak/outlier scans; keep the most mutually consistent templates.
 *
 * @param list<mixed> $samples
 * @return list<string>
 */
function fp_curate_enrollment_samples(array $samples, int $keep): array {
    $valid = fp_normalize_enrollment_samples($samples);
    $valid = array_values(array_unique($valid));
    if (count($valid) < $keep) {
        throw new RuntimeException(
            'Need at least ' . $keep . ' clear, unique scans (got ' . count($valid) . '). Rescan this finger.'
        );
    }

    $n = count($valid);
    $cohesion = [];
    for ($i = 0; $i < $n; $i++) {
        $sum = 0.0;
        $cnt = 0;
        for ($j = 0; $j < $n; $j++) {
            if ($i === $j) {
                continue;
            }
            $sum += fp_sample_similarity($valid[$i], $valid[$j]);
            $cnt++;
        }
        $cohesion[$i] = $cnt > 0 ? $sum / $cnt : 0.0;
    }

    arsort($cohesion, SORT_NUMERIC);
    $kept = [];
    foreach (array_slice(array_keys($cohesion), 0, $keep, true) as $idx) {
        $kept[] = $valid[$idx];
    }

    $cfg = fp_enroll_settings();
    $minPair = fp_min_pairwise_similarity($kept);
    if ($minPair < $cfg['enroll_min_consistency']) {
        throw new RuntimeException(
            'Scans for this finger are inconsistent (weakest match '
            . round($minPair * 100) . '%). Use the same finger, press firmly, and rescan all four slots.'
        );
    }

    return $kept;
}

/**
 * @param list<mixed> $indexSamples
 * @param list<mixed> $middleSamples
 * @return array{indexfinger: list<string>, middlefinger: list<string>}
 */
function fp_process_enrollment_samples(array $indexSamples, array $middleSamples): array {
    $cfg = fp_enroll_settings();
    $keep = $cfg['enroll_samples_keep'];

    if (count($indexSamples) < $keep || count($middleSamples) < $keep) {
        throw new RuntimeException('Capture ' . $keep . ' scans per finger (index and middle).');
    }

    if (fp_is_raw_scan_list($indexSamples) && fp_is_raw_scan_list($middleSamples)) {
        fp_ensure_native_match_loaded();
        if (!function_exists('fp_native_is_available') || !fp_native_is_available()) {
            throw new RuntimeException(
                'FMD engine unavailable. Enable PHP FFI (extension=ffi, ffi.enable=true) and restart Apache.'
            );
        }
        $indexFmd = fp_native_build_enrollment_fmd($indexSamples, 7);
        $middleFmd = fp_native_build_enrollment_fmd($middleSamples, 8);
        if ($indexFmd === null || $middleFmd === null) {
            throw new RuntimeException(
                'Could not build FMD templates. Press firmly, scan all four slots per finger, and try again.'
            );
        }
        return [
            'format' => 'dp_fmd',
            'indexfinger' => [fp_native_bin_to_b64url($indexFmd)],
            'middlefinger' => [fp_native_bin_to_b64url($middleFmd)],
        ];
    }

    $indexCurated = fp_curate_enrollment_samples($indexSamples, $keep);
    $middleCurated = fp_curate_enrollment_samples($middleSamples, $keep);

    $crossMax = fp_max_cross_finger_similarity($indexCurated, $middleCurated);
    if ($crossMax > $cfg['enroll_cross_finger_max']) {
        throw new RuntimeException(
            'Index and middle scans look too similar — use two different fingers and enroll again.'
        );
    }

    return [
        'format' => 'dp_intermediate',
        'indexfinger' => $indexCurated,
        'middlefinger' => $middleCurated,
    ];
}

/**
 * @return array{match_threshold: float, scoped_match_threshold: float, match_margin: float}
 */
function fp_match_settings(): array {
    $path = __DIR__ . '/fingerprint_config.php';
    $defaults = [
        'match_threshold' => 0.65,
        'scoped_match_threshold' => 0.55,
        'match_margin' => 0.05,
    ];
    if (!is_file($path)) {
        return $defaults;
    }
    $loaded = require $path;
    if (!is_array($loaded)) {
        return $defaults;
    }
    $thr = isset($loaded['match_threshold']) ? (float) $loaded['match_threshold'] : $defaults['match_threshold'];
    $scoped = isset($loaded['scoped_match_threshold']) ? (float) $loaded['scoped_match_threshold'] : $defaults['scoped_match_threshold'];
    $mar = isset($loaded['match_margin']) ? (float) $loaded['match_margin'] : $defaults['match_margin'];
    if ($thr < 0.35 || $thr > 0.99) {
        $thr = $defaults['match_threshold'];
    }
    if ($scoped < 0.35 || $scoped > 0.99) {
        $scoped = $defaults['scoped_match_threshold'];
    }
    if ($mar < 0.0 || $mar > 0.5) {
        $mar = $defaults['match_margin'];
    }
    return [
        'match_threshold' => $thr,
        'scoped_match_threshold' => $scoped,
        'match_margin' => $mar,
    ];
}

/**
 * @param list<string> $indexSamples
 * @param list<string> $middleSamples
 * @return array{indexfinger: string, middlefinger: string}
 */
function fp_pack_client_samples_v2(array $indexSamples, array $middleSamples): array {
    $processed = fp_process_enrollment_samples($indexSamples, $middleSamples);
    $format = (string) ($processed['format'] ?? 'dp_intermediate');
    $version = $format === 'dp_fmd' ? 3 : 2;
    $indexSamples = $processed['indexfinger'];
    $middleSamples = $processed['middlefinger'];

    $payloadIndex = json_encode(
        ['v' => $version, 'format' => $format, 'samples' => array_values($indexSamples)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    $payloadMiddle = json_encode(
        ['v' => $version, 'format' => $format, 'samples' => array_values($middleSamples)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($payloadIndex === false || $payloadMiddle === false) {
        throw new RuntimeException('Could not encode fingerprint enrollment JSON');
    }
    return [
        'indexfinger' => $payloadIndex,
        'middlefinger' => $payloadMiddle,
    ];
}

/** Normalize Intermediate payload (trim, unwrap JSON envelope, uniform base64 alphabet). */
function fp_normalize_intermediate_token(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if ($raw[0] === '{' || $raw[0] === '[') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            if (isset($j['Data']) && is_string($j['Data'])) {
                $raw = $j['Data'];
            } elseif (isset($j['data']) && is_string($j['data'])) {
                $raw = $j['data'];
            }
        }
    }
    return trim($raw);
}

function fp_decode_intermediate_blob(string $token): ?string {
    $token = fp_normalize_intermediate_token($token);
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

function fp_byte_cosine_similarity(string $a, string $b, int $len): float {
    $dot = 0.0;
    $na = 0.0;
    $nb = 0.0;
    for ($i = 0; $i < $len; $i++) {
        $va = ord($a[$i]);
        $vb = ord($b[$i]);
        $dot += $va * $vb;
        $na += $va * $va;
        $nb += $vb * $vb;
    }
    if ($na <= 0.0 || $nb <= 0.0) {
        return 0.0;
    }
    return $dot / (sqrt($na) * sqrt($nb));
}

/**
 * Similarity 0..1 between two Intermediate tokens (blended Hamming + cosine on decoded bytes).
 */
function fp_sample_similarity(string $probe, string $stored): float {
    $probe = fp_normalize_intermediate_token($probe);
    $stored = fp_normalize_intermediate_token($stored);
    if ($probe === '' || $stored === '') {
        return 0.0;
    }
    if ($probe === $stored) {
        return 1.0;
    }

    $bp = fp_decode_intermediate_blob($probe);
    $bs = fp_decode_intermediate_blob($stored);
    if ($bp !== null && $bs !== null && strlen($bp) >= 8 && strlen($bs) >= 8) {
        $len = min(strlen($bp), strlen($bs));
        if ($len < 8) {
            return 0.0;
        }
        $same = 0;
        for ($i = 0; $i < $len; $i++) {
            if ($bp[$i] === $bs[$i]) {
                $same++;
            }
        }
        $hamming = $same / $len;
        $cos = fp_byte_cosine_similarity($bp, $bs, $len);
        /* Weighted blend reduces false accepts vs max(hamming, cos) alone */
        $base = 0.35 * $hamming + 0.65 * $cos;
        /* Boost when both signals agree (typical same-finger scan) */
        if ($cos >= 0.88 && $hamming >= 0.32) {
            $base = max($base, 0.30 * $hamming + 0.70 * $cos);
        }
        $lenRatio = min(strlen($bp), strlen($bs)) / max(strlen($bp), strlen($bs));
        $scale = 0.92 + 0.08 * sqrt($lenRatio);

        return max(0.0, min(1.0, $base * $scale));
    }
    $lp = strlen($probe);
    $ls = strlen($stored);
    $len = min($lp, $ls);
    if ($len === 0) {
        return 0.0;
    }
    $same = 0;
    for ($i = 0; $i < $len; $i++) {
        if ($probe[$i] === $stored[$i]) {
            $same++;
        }
    }
    return $same / max($lp, $ls);
}

/**
 * @param list<mixed> $samples
 */
function fp_best_similarity(string $probe, array $samples): float {
    $scores = [];
    foreach ($samples as $s) {
        if (!is_string($s)) {
            continue;
        }
        $scores[] = fp_sample_similarity($probe, $s);
    }
    if ($scores === []) {
        return 0.0;
    }
    rsort($scores, SORT_NUMERIC);
    if (count($scores) === 1) {
        return $scores[0];
    }
    /* Average top-2 enrolled samples — more stable than a single lucky match */
    return ($scores[0] + $scores[1]) / 2;
}

/**
 * @return array{format: string, samples: list<string>}|null
 */
function fp_decode_v2_column(string $raw): ?array {
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['v'], $decoded['samples'])) {
        return null;
    }
    $v = (int) $decoded['v'];
    if (!is_array($decoded['samples']) || ($v !== 2 && $v !== 3)) {
        return null;
    }
    $format = (string) ($decoded['format'] ?? ($v === 3 ? 'dp_fmd' : 'dp_intermediate'));
    $out = [];
    foreach ($decoded['samples'] as $s) {
        if (is_string($s) && $s !== '') {
            $out[] = $s;
        }
    }
    return $out === [] ? null : ['format' => $format, 'samples' => $out];
}

function fp_probe_vs_sample_score(string $probe, string $stored, string $format): float {
    if ($format === 'dp_fmd') {
        fp_ensure_native_match_loaded();
        if (function_exists('fp_native_fmd_similarity')) {
            $sim = fp_native_fmd_similarity($probe, $stored);
            if ($sim !== null) {
                return $sim;
            }
            // #region agent log
            static $nativeCompareFailLogged = false;
            if (!$nativeCompareFailLogged) {
                $nativeCompareFailLogged = true;
                fp_debug_log('fingerprint_local_match.php:fp_probe_vs_sample_score', 'native_fmd_compare_returned_null', [
                    'probe_len' => strlen($probe),
                    'stored_len' => strlen($stored),
                ], 'H4');
            }
            // #endregion
        }
        return 0.0;
    }
    $sim = fp_sample_similarity($probe, $stored);
    // #region agent log
    static $intermediateMismatchLogged = false;
    if (!$intermediateMismatchLogged && $sim < 0.40) {
        $intermediateMismatchLogged = true;
        fp_debug_log('fingerprint_local_match.php:fp_probe_vs_sample_score', 'intermediate_compare_low_score', [
            'score' => round($sim, 4),
            'probe_len' => strlen($probe),
            'stored_len' => strlen($stored),
            'format' => $format,
        ], 'H1');
    }
    // #endregion
    return $sim;
}

/** Best score for one user (max over index / middle template sets). */
function fp_user_match_score(string $probe, string $indexfingerRaw, string $middlefingerRaw): float {
    if ($probe === '') {
        return 0.0;
    }
    $best = 0.0;
    foreach ([$indexfingerRaw, $middlefingerRaw] as $col) {
        $parsed = fp_decode_v2_column($col);
        if ($parsed === null) {
            continue;
        }
        foreach ($parsed['samples'] as $s) {
            $score = fp_probe_vs_sample_score($probe, $s, $parsed['format']);
            if ($score > $best) {
                $best = $score;
            }
        }
    }
    return $best;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return array{
 *   entries: list<array{row: array<string,mixed>, format: string, token: string}>,
 *   native_tokens: list<string>,
 *   native_map: list<array{entry: int}>
 * }
 */
function fp_collect_template_entries(array $rows): array {
    $entries = [];
    $nativeTokens = [];
    $nativeMap = [];

    foreach ($rows as $row) {
        foreach (['indexfinger', 'middlefinger'] as $col) {
            $raw = trim((string) ($row[$col] ?? ''));
            $parsed = fp_decode_v2_column($raw);
            if ($parsed === null) {
                continue;
            }
            foreach ($parsed['samples'] as $sample) {
                if (!is_string($sample) || $sample === '') {
                    continue;
                }
                $entryIdx = count($entries);
                $entries[] = [
                    'row' => $row,
                    'format' => $parsed['format'],
                    'token' => $sample,
                ];
                if ($parsed['format'] === 'dp_fmd') {
                    $nativeMap[] = ['entry' => $entryIdx];
                    $nativeTokens[] = $sample;
                }
            }
        }
    }

    return [
        'entries' => $entries,
        'native_tokens' => $nativeTokens,
        'native_map' => $nativeMap,
    ];
}

/**
 * @param array{
 *   entries: list<array{row: array<string,mixed>, format: string, token: string}>,
 *   native_tokens: list<string>,
 *   native_map: list<array{entry: int}>
 * } $plan
 * @param list<float|null> $nativeScores
 */
function fp_rank_from_verify_result(array $plan, array $nativeScores, string $probe): array {
    $entries = $plan['entries'];
    $entryScores = array_fill(0, count($entries), 0.0);

    foreach ($plan['native_map'] as $mapIdx => $map) {
        $sim = $nativeScores[$mapIdx] ?? null;
        if ($sim !== null) {
            $entryScores[$map['entry']] = max($entryScores[$map['entry']], $sim);
        }
    }

    foreach ($entries as $entryIdx => $entry) {
        if ($entry['format'] === 'dp_fmd') {
            continue;
        }
        $entryScores[$entryIdx] = fp_sample_similarity($probe, $entry['token']);
    }

    $bestByRow = [];
    foreach ($entries as $entryIdx => $entry) {
        $rowId = (int) ($entry['row']['id'] ?? 0);
        $score = $entryScores[$entryIdx];
        if (!isset($bestByRow[$rowId]) || $score > $bestByRow[$rowId]['score']) {
            $bestByRow[$rowId] = ['row' => $entry['row'], 'score' => $score];
        }
    }

    $scored = array_values($bestByRow);
    usort($scored, static function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    // #region agent log
    $topSummary = [];
    foreach (array_slice($scored, 0, 5) as $entry) {
        $topSummary[] = [
            'username' => (string) ($entry['row']['username'] ?? ''),
            'score' => round($entry['score'], 4),
        ];
    }
    fp_debug_log('fingerprint_local_match.php:fp_rank_from_verify_result', 'rank_complete', [
        'candidate_count' => count($scored),
        'top_scores' => $topSummary,
        'probe_len' => strlen($probe),
        'native_batch_count' => count($plan['native_tokens']),
        'path' => 'verify_scan',
    ], 'H2,H3');
    // #endregion

    return $scored;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array{row: array<string,mixed>, score: float}>
 */
function fp_rank_user_rows(array $rows, string $probe): array {
    if ($probe === '') {
        return [];
    }

    fp_ensure_native_match_loaded();

    $entries = [];
    $nativeTokens = [];
    $nativeMap = [];

    foreach ($rows as $rowIdx => $row) {
        foreach (['indexfinger', 'middlefinger'] as $col) {
            $raw = trim((string) ($row[$col] ?? ''));
            $parsed = fp_decode_v2_column($raw);
            if ($parsed === null) {
                continue;
            }
            foreach ($parsed['samples'] as $sample) {
                if (!is_string($sample) || $sample === '') {
                    continue;
                }
                $entryIdx = count($entries);
                $entries[] = [
                    'row' => $row,
                    'format' => $parsed['format'],
                    'token' => $sample,
                ];
                if ($parsed['format'] === 'dp_fmd' && function_exists('fp_native_batch_fmd_similarity')) {
                    $nativeMap[] = ['entry' => $entryIdx, 'token' => $sample];
                    $nativeTokens[] = $sample;
                }
            }
        }
    }

    $entryScores = array_fill(0, count($entries), 0.0);

    if ($nativeTokens !== []) {
        $batchScores = fp_native_batch_fmd_similarity($probe, $nativeTokens);
        foreach ($nativeMap as $mapIdx => $map) {
            $sim = $batchScores[$mapIdx] ?? null;
            if ($sim !== null) {
                $entryScores[$map['entry']] = max($entryScores[$map['entry']], $sim);
            }
        }
    }

    foreach ($entries as $entryIdx => $entry) {
        if ($entry['format'] === 'dp_fmd') {
            continue;
        }
        $entryScores[$entryIdx] = fp_sample_similarity($probe, $entry['token']);
    }

    $bestByRow = [];
    foreach ($entries as $entryIdx => $entry) {
        $rowId = (int) ($entry['row']['id'] ?? 0);
        $score = $entryScores[$entryIdx];
        if (!isset($bestByRow[$rowId]) || $score > $bestByRow[$rowId]['score']) {
            $bestByRow[$rowId] = ['row' => $entry['row'], 'score' => $score];
        }
    }

    $scored = array_values($bestByRow);
    usort($scored, static function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    // #region agent log
    $formatCounts = ['dp_fmd' => 0, 'dp_intermediate' => 0, 'other' => 0];
    foreach ($rows as $row) {
        foreach (['indexfinger', 'middlefinger'] as $col) {
            $parsed = fp_decode_v2_column(trim((string) ($row[$col] ?? '')));
            if ($parsed === null) {
                continue;
            }
            $fmt = $parsed['format'];
            if (isset($formatCounts[$fmt])) {
                $formatCounts[$fmt]++;
            } else {
                $formatCounts['other']++;
            }
        }
    }
    $topSummary = [];
    foreach (array_slice($scored, 0, 5) as $entry) {
        $topSummary[] = [
            'username' => (string) ($entry['row']['username'] ?? ''),
            'score' => round($entry['score'], 4),
        ];
    }
    fp_debug_log('fingerprint_local_match.php:fp_rank_user_rows', 'rank_complete', [
        'candidate_count' => count($scored),
        'enrolled_format_counts' => $formatCounts,
        'top_scores' => $topSummary,
        'probe_len' => strlen($probe),
        'native_batch_count' => count($nativeTokens),
    ], 'H1,H3');
    // #endregion

    return $scored;
}

/**
 * @param list<array{row: array<string,mixed>, score: float}> $ranked
 * @return array<string,mixed>|null
 */
function fp_select_best_from_ranked(array $ranked, bool $scopedToSingleAccount): ?array {
    $cfg = fp_match_settings();
    $thr = $scopedToSingleAccount ? $cfg['scoped_match_threshold'] : $cfg['match_threshold'];
    $margin = $cfg['match_margin'];

    if ($ranked === []) {
        // #region agent log
        fp_debug_log('fingerprint_local_match.php:fp_select_best_from_ranked', 'reject_no_scored_rows', [], 'H3');
        // #endregion
        return null;
    }

    $top = $ranked[0];
    if ($top['score'] < $thr) {
        // #region agent log
        fp_debug_log('fingerprint_local_match.php:fp_select_best_from_ranked', 'reject_below_threshold', [
            'top_username' => (string) ($top['row']['username'] ?? ''),
            'top_score' => round($top['score'], 4),
            'threshold' => $thr,
            'scoped' => $scopedToSingleAccount,
        ], 'H3');
        // #endregion
        return null;
    }

    if ($scopedToSingleAccount || count($ranked) < 2) {
        // #region agent log
        fp_debug_log('fingerprint_local_match.php:fp_select_best_from_ranked', 'match_accepted', [
            'username' => (string) ($top['row']['username'] ?? ''),
            'score' => round($top['score'], 4),
            'reason' => $scopedToSingleAccount ? 'scoped_single' : 'only_candidate',
        ], 'H3');
        // #endregion
        return $top['row'];
    }

    $second = $ranked[1]['score'];
    $gap = $top['score'] - $second;
    /* Margin only when runner-up also clears threshold — avoids rejecting a clear winner */
    if ($second >= $thr && $gap < $margin) {
        /* Strong top score with meaningful lead still wins in 1:N */
        if (!($top['score'] >= 0.75 && $gap >= 0.04)) {
            // #region agent log
            fp_debug_log('fingerprint_local_match.php:fp_select_best_from_ranked', 'reject_ambiguous_margin', [
                'top_username' => (string) ($top['row']['username'] ?? ''),
                'second_username' => (string) ($ranked[1]['row']['username'] ?? ''),
                'top_score' => round($top['score'], 4),
                'second_score' => round($second, 4),
                'gap' => round($gap, 4),
                'margin' => $margin,
            ], 'H3');
            // #endregion
            return null;
        }
    }

    // #region agent log
    fp_debug_log('fingerprint_local_match.php:fp_select_best_from_ranked', 'match_accepted', [
        'username' => (string) ($top['row']['username'] ?? ''),
        'score' => round($top['score'], 4),
        'second_score' => round($second, 4),
        'gap' => round($gap, 4),
    ], 'H3');
    // #endregion

    return $top['row'];
}

/**
 * Rank all candidates; enforce threshold and (for true 1:N) margin between first and second.
 * When $scopedToSingleAccount is true (username hint matched at most one row), skip margin rule.
 *
 * @param list<array<string,mixed>> $rows
 * @return array<string,mixed>|null
 */
function fp_select_best_user_row(array $rows, string $probe, bool $scopedToSingleAccount): ?array {
    return fp_select_best_from_ranked(fp_rank_user_rows($rows, $probe), $scopedToSingleAccount);
}

/** @deprecated use fp_select_best_user_row */
function fp_local_probe_matches_user(string $probe, string $indexfingerRaw, string $middlefingerRaw): bool {
    return fp_user_match_score($probe, $indexfingerRaw, $middlefingerRaw) >= fp_match_settings()['match_threshold'];
}
