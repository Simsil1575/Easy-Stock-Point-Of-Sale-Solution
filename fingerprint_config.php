<?php
/**
 * Local fingerprint verification tuning (finger/ Intermediate samples vs user.db v2 JSON).
 *
 * - FP_MATCH_THRESHOLD — auto-login minimum (default 0.65).
 * - FP_SCOPED_MATCH_THRESHOLD — when username narrows to one account (default 0.60).
 * - FP_MATCH_MARGIN — 1:N only: winner must beat 2nd place by this (default 0.08).
 *
 * Enter username before scanning for the most reliable match.
 */
$t = getenv('FP_MATCH_THRESHOLD');
$scopedRaw = getenv('FP_SCOPED_MATCH_THRESHOLD');
$marginRaw = getenv('FP_MATCH_MARGIN');

return [
    'match_threshold' => ($t !== false && $t !== '')
        ? max(0.35, min(0.99, (float) $t))
        : 0.65,
    'scoped_match_threshold' => ($scopedRaw !== false && $scopedRaw !== '')
        ? max(0.35, min(0.99, (float) $scopedRaw))
        : 0.55,
    'match_margin' => ($marginRaw !== false && $marginRaw !== '')
        ? max(0.0, min(0.5, (float) $marginRaw))
        : 0.05,
    /* Enrollment: min avg pairwise similarity within one finger (default 0.42) */
    'enroll_min_consistency' => ($e = getenv('FP_ENROLL_MIN_CONSISTENCY')) !== false && $e !== ''
        ? max(0.30, min(0.99, (float) $e))
        : 0.42,
    /* Enrollment: index vs middle must not look like the same finger (default 0.58) */
    'enroll_cross_finger_max' => ($e = getenv('FP_ENROLL_CROSS_FINGER_MAX')) !== false && $e !== ''
        ? max(0.30, min(0.99, (float) $e))
        : 0.58,
    'enroll_samples_keep' => 4,
    /* Native dpfj_compare: max score for match (default 21474 = PROBABILITY_ONE/100000) */
    'native_max_score' => ($e = getenv('FP_NATIVE_MAX_SCORE')) !== false && $e !== ''
        ? max(1, (int) $e)
        : 21474,
    /* Build/store true FMD via dpfj.dll + bundled tools/python (see tools/python/README.md). */
    'native_fmd_enabled' => getenv('FP_NATIVE_FMD') !== '0',
    /* Optional override; empty = tools/python/python.exe then system Python. FP_PYTHON env also works. */
    'python_bin' => ($p = getenv('FP_PYTHON')) !== false && $p !== '' ? $p : '',
];