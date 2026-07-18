<?php
/**
 * App updater — apply GitHub updates over htdocs.
 * Prefers downloading only changed files (compare API); falls back to full zip
 * for first install or large updates. Local DBs and products/ stay protected.
 */

date_default_timezone_set('Africa/Harare');

define('APP_UPDATER_SETTINGS_FILE', 'app_updater_settings.json');
define('APP_UPDATER_TMP_DIR', '.update_tmp');
define('APP_UPDATER_DEFAULT_OWNER', 'Simsil1575');
define('APP_UPDATER_DEFAULT_REPO', 'Easy-Stock-Point-Of-Sale-Solution');
define('APP_UPDATER_DEFAULT_BRANCH', 'main');
define('APP_UPDATER_INCREMENTAL_MAX_FILES', 250);

/**
 * Absolute path to the project root (htdocs).
 */
function appUpdaterRoot(): string
{
    static $root = null;
    if ($root === null) {
        $resolved = realpath(__DIR__);
        $root = $resolved !== false ? $resolved : __DIR__;
    }
    return $root;
}

/**
 * Protected file basenames (never overwritten).
 */
function appUpdaterProtectedFiles(): array
{
    return ['pos.db', 'active.db', 'user.db', 'info.db', APP_UPDATER_SETTINGS_FILE];
}

/**
 * Protected top-level / nested directory names (never overwritten).
 */
function appUpdaterProtectedDirs(): array
{
    return ['products', APP_UPDATER_TMP_DIR, '.git'];
}

function appUpdaterSettingsPath(): string
{
    return appUpdaterRoot() . DIRECTORY_SEPARATOR . APP_UPDATER_SETTINGS_FILE;
}

/**
 * Load or create updater settings.
 */
function appUpdaterLoadSettings(): array
{
    $defaults = [
        'github_owner' => APP_UPDATER_DEFAULT_OWNER,
        'github_repo' => APP_UPDATER_DEFAULT_REPO,
        'github_branch' => APP_UPDATER_DEFAULT_BRANCH,
        'github_token' => '',
        'installed_sha' => '',
        'installed_tag' => '',
        'installed_at' => '',
        'last_check_at' => '',
        'last_remote_sha' => '',
        'last_remote_tag' => '',
        'last_remote_name' => '',
    ];

    $path = appUpdaterSettingsPath();
    if (!is_file($path)) {
        appUpdaterSaveSettings($defaults);
        return $defaults;
    }

    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return $defaults;
    }

    return array_merge($defaults, $data);
}

function appUpdaterSaveSettings(array $settings): bool
{
    $path = appUpdaterSettingsPath();
    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    return file_put_contents($path, $json . "\n", LOCK_EX) !== false;
}

/**
 * Whether a relative path inside the install must not be replaced.
 */
function appUpdaterIsProtectedPath(string $relativePath): bool
{
    $normalized = str_replace('\\', '/', $relativePath);
    $normalized = ltrim($normalized, '/');
    if ($normalized === '' || $normalized === '.') {
        return true;
    }

    $parts = explode('/', $normalized);
    $protectedDirs = array_map('strtolower', appUpdaterProtectedDirs());
    $protectedFiles = array_map('strtolower', appUpdaterProtectedFiles());

    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if (in_array(strtolower($part), $protectedDirs, true)) {
            return true;
        }
    }

    $base = strtolower(basename($normalized));
    if (in_array($base, $protectedFiles, true)) {
        return true;
    }

    return false;
}

/**
 * HTTP GET via cURL (preferred) or file_get_contents.
 *
 * @return array{ok:bool,status:int,body:string,error:?string}
 */
/**
 * Merge HTTP headers; later entries override earlier ones for the same name.
 *
 * @param string[] ...$headerLists
 * @return string[]
 */
function appUpdaterMergeHeaders(array ...$headerLists): array
{
    $map = [];
    foreach ($headerLists as $list) {
        foreach ($list as $h) {
            if (!is_string($h) || !preg_match('/^([^:]+):\s*(.*)$/s', $h, $m)) {
                continue;
            }
            $map[strtolower(trim($m[1]))] = trim($m[1]) . ': ' . $m[2];
        }
    }
    return array_values($map);
}

function appUpdaterHttpGet(string $url, array $headers = [], int $timeout = 120): array
{
    $allHeaders = appUpdaterMergeHeaders([
        'User-Agent: EasyStock-POS-Updater',
        'Accept: application/vnd.github+json',
    ], $headers);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $allHeaders,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $error ?: 'cURL request failed'];
        }
        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $body,
            'error' => ($status >= 200 && $status < 300) ? null : ('HTTP ' . $status),
        ];
    }

    $headerLines = '';
    foreach ($allHeaders as $h) {
        $headerLines .= $h . "\r\n";
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => $headerLines,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }
    if ($body === false) {
        return ['ok' => false, 'status' => $status, 'body' => '', 'error' => 'HTTP request failed'];
    }
    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $body,
        'error' => ($status >= 200 && $status < 300) ? null : ('HTTP ' . $status),
    ];
}

function appUpdaterLooksLikeCommitSha(string $value): bool
{
    return (bool) preg_match('/^[0-9a-f]{7,40}$/i', trim($value));
}

/**
 * Resolve a tag / branch / short sha to a full commit SHA.
 */
function appUpdaterResolveCommitSha(string $ref, array $settings): ?string
{
    $ref = trim($ref);
    if ($ref === '') {
        return null;
    }
    if (preg_match('/^[0-9a-f]{40}$/i', $ref)) {
        return strtolower($ref);
    }

    $owner = rawurlencode((string) $settings['github_owner']);
    $repo = rawurlencode((string) $settings['github_repo']);
    $headers = appUpdaterAuthHeaders($settings);
    $url = "https://api.github.com/repos/{$owner}/{$repo}/commits/" . rawurlencode($ref);
    $res = appUpdaterHttpGet($url, $headers, 60);
    if (!$res['ok']) {
        return appUpdaterLooksLikeCommitSha($ref) ? strtolower($ref) : null;
    }
    $data = json_decode($res['body'], true);
    if (!is_array($data) || empty($data['sha'])) {
        return null;
    }
    return strtolower((string) $data['sha']);
}

/**
 * URL-encode each path segment for the Contents API.
 */
function appUpdaterEncodeRepoPath(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');
    $parts = array_filter(explode('/', $path), static function ($p) {
        return $p !== '';
    });
    return implode('/', array_map('rawurlencode', $parts));
}

/**
 * Download a single file at a given ref (raw bytes).
 *
 * @return array{ok:bool,body:?string,error:?string}
 */
function appUpdaterFetchFileAtRef(string $relativePath, string $ref, array $settings): array
{
    $owner = rawurlencode((string) $settings['github_owner']);
    $repo = rawurlencode((string) $settings['github_repo']);
    $encodedPath = appUpdaterEncodeRepoPath($relativePath);
    $url = "https://api.github.com/repos/{$owner}/{$repo}/contents/{$encodedPath}?ref=" . rawurlencode($ref);
    $headers = appUpdaterMergeHeaders(
        appUpdaterAuthHeaders($settings),
        ['Accept: application/vnd.github.raw']
    );
    $res = appUpdaterHttpGet($url, $headers, 120);
    if ($res['ok']) {
        return ['ok' => true, 'body' => $res['body'], 'error' => null];
    }

    // Fallback: JSON contents response (base64) or download_url for large files
    $jsonHeaders = appUpdaterAuthHeaders($settings);
    $jsonRes = appUpdaterHttpGet($url, $jsonHeaders, 120);
    if (!$jsonRes['ok']) {
        return ['ok' => false, 'body' => null, 'error' => $res['error'] ?: ('Could not download ' . $relativePath)];
    }
    $data = json_decode($jsonRes['body'], true);
    if (!is_array($data)) {
        return ['ok' => false, 'body' => null, 'error' => 'Invalid contents response for ' . $relativePath];
    }
    if (!empty($data['download_url']) && is_string($data['download_url'])) {
        $dl = appUpdaterHttpGet($data['download_url'], appUpdaterAuthHeaders($settings), 180);
        if ($dl['ok']) {
            return ['ok' => true, 'body' => $dl['body'], 'error' => null];
        }
    }
    if (($data['encoding'] ?? '') === 'base64' && isset($data['content']) && is_string($data['content'])) {
        $decoded = base64_decode(preg_replace('/\s+/', '', $data['content']), true);
        if ($decoded !== false) {
            return ['ok' => true, 'body' => $decoded, 'error' => null];
        }
    }
    return ['ok' => false, 'body' => null, 'error' => 'Could not read file content for ' . $relativePath];
}

/**
 * Write file bytes under the install root (creates parent dirs).
 */
function appUpdaterWriteInstallFile(string $relativePath, string $contents): bool
{
    $relativePath = str_replace('\\', '/', $relativePath);
    $relativePath = ltrim($relativePath, '/');
    $dest = appUpdaterRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $destDir = dirname($dest);
    if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
        return false;
    }
    return file_put_contents($dest, $contents, LOCK_EX) !== false;
}

/**
 * Delete a file under the install root if it exists and is not protected.
 */
function appUpdaterDeleteInstallFile(string $relativePath): bool
{
    if (appUpdaterIsProtectedPath($relativePath)) {
        return false;
    }
    $relativePath = str_replace('\\', '/', $relativePath);
    $relativePath = ltrim($relativePath, '/');
    $dest = appUpdaterRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_file($dest)) {
        return true;
    }
    return @unlink($dest);
}

/**
 * Apply only files that changed between two commits.
 *
 * @return array{ok:bool,fallback:bool,error:?string,mode?:string,copied?:int,deleted?:int,skipped?:int,failed?:int,errors?:string[]}
 */
function appUpdaterApplyIncremental(string $baseSha, string $headSha, array $settings): array
{
    $owner = rawurlencode((string) $settings['github_owner']);
    $repo = rawurlencode((string) $settings['github_repo']);
    $headers = appUpdaterAuthHeaders($settings);
    $compareUrl = "https://api.github.com/repos/{$owner}/{$repo}/compare/"
        . rawurlencode($baseSha) . '...' . rawurlencode($headSha);

    $compare = appUpdaterHttpGet($compareUrl, $headers, 90);
    if (!$compare['ok']) {
        return [
            'ok' => false,
            'fallback' => true,
            'error' => 'Could not compare commits (' . ($compare['error'] ?: 'HTTP ' . $compare['status']) . ').',
        ];
    }

    $data = json_decode($compare['body'], true);
    if (!is_array($data)) {
        return ['ok' => false, 'fallback' => true, 'error' => 'Unexpected compare response from GitHub.'];
    }

    $files = isset($data['files']) && is_array($data['files']) ? $data['files'] : [];
    if (count($files) > APP_UPDATER_INCREMENTAL_MAX_FILES) {
        return [
            'ok' => false,
            'fallback' => true,
            'error' => 'Too many changed files (' . count($files) . '); using full package download.',
        ];
    }

    $copied = 0;
    $deleted = 0;
    $skipped = 0;
    $failed = 0;
    $errors = [];

    foreach ($files as $file) {
        if (!is_array($file)) {
            continue;
        }
        $filename = str_replace('\\', '/', (string) ($file['filename'] ?? ''));
        $status = (string) ($file['status'] ?? '');
        $previous = str_replace('\\', '/', (string) ($file['previous_filename'] ?? ''));

        if ($filename === '') {
            continue;
        }

        if ($status === 'renamed' && $previous !== '' && !appUpdaterIsProtectedPath($previous)) {
            if (appUpdaterDeleteInstallFile($previous)) {
                $deleted++;
            }
        }

        if ($status === 'removed') {
            if (appUpdaterIsProtectedPath($filename)) {
                $skipped++;
                continue;
            }
            if (appUpdaterDeleteInstallFile($filename)) {
                $deleted++;
            } else {
                $failed++;
                $errors[] = 'Could not delete: ' . $filename;
            }
            continue;
        }

        if (appUpdaterIsProtectedPath($filename)) {
            $skipped++;
            continue;
        }

        $fetch = appUpdaterFetchFileAtRef($filename, $headSha, $settings);
        if (!$fetch['ok'] || !is_string($fetch['body'])) {
            $failed++;
            $errors[] = $fetch['error'] ?: ('Failed: ' . $filename);
            continue;
        }
        if (!appUpdaterWriteInstallFile($filename, $fetch['body'])) {
            $failed++;
            $errors[] = 'Could not write: ' . $filename;
            continue;
        }
        $copied++;
    }

    if ($failed > 0 && $copied === 0 && $deleted === 0) {
        return [
            'ok' => false,
            'fallback' => count($files) > 0,
            'error' => 'Incremental update failed — no files could be applied.',
            'mode' => 'incremental',
            'copied' => $copied,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => array_slice($errors, 0, 20),
        ];
    }

    return [
        'ok' => true,
        'fallback' => false,
        'error' => null,
        'mode' => 'incremental',
        'copied' => $copied,
        'deleted' => $deleted,
        'skipped' => $skipped,
        'failed' => $failed,
        'errors' => array_slice($errors, 0, 20),
    ];
}

function appUpdaterAuthHeaders(array $settings): array
{
    $token = trim((string) ($settings['github_token'] ?? ''));
    if ($token === '') {
        return [];
    }
    return ['Authorization: Bearer ' . $token];
}

/**
 * Resolve latest remote version (release preferred, else branch tip).
 *
 * @return array{ok:bool,error:?string,source:?string,sha:?string,tag:?string,name:?string,zip_url:?string,published_at:?string}
 */
function appUpdaterCheckRemote(?array $settings = null): array
{
    $settings = $settings ?? appUpdaterLoadSettings();
    $owner = rawurlencode((string) $settings['github_owner']);
    $repo = rawurlencode((string) $settings['github_repo']);
    $branch = (string) $settings['github_branch'];
    $headers = appUpdaterAuthHeaders($settings);

    // Prefer latest GitHub release
    $releaseUrl = "https://api.github.com/repos/{$owner}/{$repo}/releases/latest";
    $release = appUpdaterHttpGet($releaseUrl, $headers, 60);

    if ($release['ok']) {
        $data = json_decode($release['body'], true);
        if (is_array($data) && !empty($data['tag_name'])) {
            $tag = (string) $data['tag_name'];
            // Resolve tag to a full commit SHA (target_commitish is often just a branch name)
            $sha = appUpdaterResolveCommitSha($tag, $settings);
            if ($sha === null && !empty($data['target_commitish'])) {
                $sha = appUpdaterResolveCommitSha((string) $data['target_commitish'], $settings);
            }
            if ($sha === null) {
                $sha = $tag;
            }
            // Prefer zipball_url from API (works with private repos + token)
            $zipUrl = !empty($data['zipball_url'])
                ? (string) $data['zipball_url']
                : "https://api.github.com/repos/{$owner}/{$repo}/zipball/" . rawurlencode($tag);

            $settings['last_check_at'] = date('c');
            $settings['last_remote_sha'] = $sha;
            $settings['last_remote_tag'] = $tag;
            $settings['last_remote_name'] = (string) ($data['name'] ?? $tag);
            appUpdaterSaveSettings($settings);

            return [
                'ok' => true,
                'error' => null,
                'source' => 'release',
                'sha' => $settings['last_remote_sha'],
                'tag' => $tag,
                'name' => $settings['last_remote_name'],
                'zip_url' => $zipUrl,
                'published_at' => (string) ($data['published_at'] ?? ''),
            ];
        }
    }

    // Fall back to branch tip
    $branchEnc = rawurlencode($branch);
    $refUrl = "https://api.github.com/repos/{$owner}/{$repo}/commits/{$branchEnc}";
    $ref = appUpdaterHttpGet($refUrl, $headers, 60);
    if (!$ref['ok']) {
        $hint = '';
        if ($ref['status'] === 404) {
            $hint = ' Repository not found or private — add a GitHub token in app_updater_settings.json.';
        } elseif ($ref['status'] === 401 || $ref['status'] === 403) {
            $hint = ' Authentication failed — check the GitHub token.';
        }
        return [
            'ok' => false,
            'error' => 'Could not reach GitHub (' . ($ref['error'] ?: 'HTTP ' . $ref['status']) . ').' . $hint,
            'source' => null,
            'sha' => null,
            'tag' => null,
            'name' => null,
            'zip_url' => null,
            'published_at' => null,
        ];
    }

    $data = json_decode($ref['body'], true);
    if (!is_array($data) || empty($data['sha'])) {
        return [
            'ok' => false,
            'error' => 'Unexpected GitHub response for branch tip.',
            'source' => null,
            'sha' => null,
            'tag' => null,
            'name' => null,
            'zip_url' => null,
            'published_at' => null,
        ];
    }

    $sha = (string) $data['sha'];
    $short = substr($sha, 0, 7);
    $zipUrl = "https://api.github.com/repos/{$owner}/{$repo}/zipball/" . rawurlencode($branch);

    $settings['last_check_at'] = date('c');
    $settings['last_remote_sha'] = $sha;
    $settings['last_remote_tag'] = '';
    $settings['last_remote_name'] = $branch . '@' . $short;
    appUpdaterSaveSettings($settings);

    return [
        'ok' => true,
        'error' => null,
        'source' => 'branch',
        'sha' => $sha,
        'tag' => '',
        'name' => $settings['last_remote_name'],
        'zip_url' => $zipUrl,
        'published_at' => (string) ($data['commit']['committer']['date'] ?? ''),
    ];
}

/**
 * Download a binary URL to a local file.
 */
function appUpdaterDownloadFile(string $url, string $destPath, array $headers = [], int $timeout = 600): array
{
    if (function_exists('curl_init')) {
        $fp = fopen($destPath, 'wb');
        if ($fp === false) {
            return ['ok' => false, 'error' => 'Could not open temp file for writing.'];
        }
        $defaultHeaders = [
            'User-Agent: EasyStock-POS-Updater',
            'Accept: application/vnd.github+json',
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $headers),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $status < 200 || $status >= 300) {
            @unlink($destPath);
            return ['ok' => false, 'error' => $error ?: ('Download failed (HTTP ' . $status . ')')];
        }
        return ['ok' => true, 'error' => null];
    }

    $res = appUpdaterHttpGet($url, $headers, $timeout);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error'] ?: 'Download failed'];
    }
    if (file_put_contents($destPath, $res['body']) === false) {
        return ['ok' => false, 'error' => 'Could not save downloaded zip.'];
    }
    return ['ok' => true, 'error' => null];
}

function appUpdaterRemoveTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            appUpdaterRemoveTree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * Find the single top-level folder inside a GitHub zip extract.
 */
function appUpdaterFindExtractRoot(string $extractDir): ?string
{
    $items = @scandir($extractDir);
    if ($items === false) {
        return null;
    }
    $dirs = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $extractDir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            $dirs[] = $path;
        }
    }
    if (count($dirs) === 1) {
        return $dirs[0];
    }
    // Flat zip — treat extract dir as root
    return $extractDir;
}

/**
 * Copy files from source tree into htdocs, skipping protected paths.
 *
 * @return array{copied:int,skipped:int,failed:int,errors:string[]}
 */
function appUpdaterCopyTree(string $sourceRoot, string $destRoot): array
{
    $copied = 0;
    $skipped = 0;
    $failed = 0;
    $errors = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $sourceRootReal = realpath($sourceRoot);
    if ($sourceRootReal === false) {
        return ['copied' => 0, 'skipped' => 0, 'failed' => 1, 'errors' => ['Invalid extract root.']];
    }

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $full = $item->getPathname();
        $relative = substr($full, strlen($sourceRootReal) + 1);
        $relative = str_replace('\\', '/', $relative);

        if (appUpdaterIsProtectedPath($relative)) {
            $skipped++;
            continue;
        }

        $dest = $destRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if ($item->isDir()) {
            if (!is_dir($dest) && !@mkdir($dest, 0755, true)) {
                $failed++;
                $errors[] = 'Could not create folder: ' . $relative;
            }
            continue;
        }

        if (!$item->isFile()) {
            $skipped++;
            continue;
        }

        $destDir = dirname($dest);
        if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
            $failed++;
            $errors[] = 'Could not create folder for: ' . $relative;
            continue;
        }

        if (!@copy($full, $dest)) {
            $failed++;
            $errors[] = 'Could not copy: ' . $relative;
            continue;
        }
        $copied++;
    }

    return [
        'copied' => $copied,
        'skipped' => $skipped,
        'failed' => $failed,
        'errors' => array_slice($errors, 0, 20),
    ];
}

/**
 * Download remote changes and apply over the install.
 * Uses incremental (changed files only) when a previous install SHA is known;
 * otherwise downloads the full zip package.
 *
 * @return array{ok:bool,error:?string,mode?:string,copied?:int,deleted?:int,skipped?:int,failed?:int,errors?:string[],remote?:array}
 */
function appUpdaterApplyUpdate(?array $remote = null): array
{
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    $settings = appUpdaterLoadSettings();
    if ($remote === null) {
        $remote = appUpdaterCheckRemote($settings);
        $settings = appUpdaterLoadSettings();
    }
    if (empty($remote['ok'])) {
        return ['ok' => false, 'error' => $remote['error'] ?? 'Could not resolve remote version.'];
    }

    $headSha = (string) ($remote['sha'] ?? '');
    if (!appUpdaterLooksLikeCommitSha($headSha)) {
        $resolvedHead = appUpdaterResolveCommitSha(
            $headSha !== '' ? $headSha : (string) ($remote['tag'] ?? $settings['github_branch']),
            $settings
        );
        if ($resolvedHead !== null) {
            $headSha = $resolvedHead;
            $remote['sha'] = $headSha;
        }
    } else {
        $headSha = strtolower($headSha);
        $remote['sha'] = $headSha;
    }

    $baseSha = trim((string) ($settings['installed_sha'] ?? ''));
    // Only compare when we have a real commit SHA (old installs may have stored a branch name)
    if ($baseSha !== '' && !appUpdaterLooksLikeCommitSha($baseSha)) {
        $baseSha = '';
    } elseif ($baseSha !== '') {
        $baseSha = strtolower($baseSha);
    }

    // Incremental path: only download files that changed since last install
    if ($baseSha !== '' && appUpdaterLooksLikeCommitSha($baseSha) && appUpdaterLooksLikeCommitSha($headSha)
        && strcasecmp($baseSha, $headSha) !== 0) {
        $incremental = appUpdaterApplyIncremental($baseSha, $headSha, $settings);
        if (!empty($incremental['ok'])) {
            $settings = appUpdaterLoadSettings();
            $settings['installed_sha'] = $headSha;
            $settings['installed_tag'] = (string) ($remote['tag'] ?? '');
            $settings['installed_at'] = date('c');
            appUpdaterSaveSettings($settings);

            return [
                'ok' => true,
                'error' => null,
                'mode' => 'incremental',
                'copied' => $incremental['copied'] ?? 0,
                'deleted' => $incremental['deleted'] ?? 0,
                'skipped' => $incremental['skipped'] ?? 0,
                'failed' => $incremental['failed'] ?? 0,
                'errors' => $incremental['errors'] ?? [],
                'remote' => $remote,
            ];
        }
        // Hard failure without fallback (e.g. every file failed and compare was empty-ish)
        if (empty($incremental['fallback'])) {
            return [
                'ok' => false,
                'error' => $incremental['error'] ?? 'Incremental update failed.',
                'mode' => 'incremental',
                'copied' => $incremental['copied'] ?? 0,
                'deleted' => $incremental['deleted'] ?? 0,
                'skipped' => $incremental['skipped'] ?? 0,
                'failed' => $incremental['failed'] ?? 0,
                'errors' => $incremental['errors'] ?? [],
                'remote' => $remote,
            ];
        }
        // else fall through to full zip
    }

    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => 'PHP ZipArchive extension is required for full package updates.'];
    }

    $root = appUpdaterRoot();
    $tmpBase = $root . DIRECTORY_SEPARATOR . APP_UPDATER_TMP_DIR;
    if (is_dir($tmpBase)) {
        appUpdaterRemoveTree($tmpBase);
    }
    if (!@mkdir($tmpBase, 0755, true)) {
        return ['ok' => false, 'error' => 'Could not create temporary update folder.'];
    }

    $zipPath = $tmpBase . DIRECTORY_SEPARATOR . 'update.zip';
    $extractDir = $tmpBase . DIRECTORY_SEPARATOR . 'extract';
    if (!@mkdir($extractDir, 0755, true)) {
        appUpdaterRemoveTree($tmpBase);
        return ['ok' => false, 'error' => 'Could not create extract folder.'];
    }

    $download = appUpdaterDownloadFile(
        (string) $remote['zip_url'],
        $zipPath,
        appUpdaterAuthHeaders($settings),
        600
    );
    if (!$download['ok']) {
        appUpdaterRemoveTree($tmpBase);
        return ['ok' => false, 'error' => $download['error']];
    }

    $zip = new ZipArchive();
    $opened = $zip->open($zipPath);
    if ($opened !== true) {
        appUpdaterRemoveTree($tmpBase);
        return ['ok' => false, 'error' => 'Could not open downloaded zip (code ' . $opened . ').'];
    }
    if (!$zip->extractTo($extractDir)) {
        $zip->close();
        appUpdaterRemoveTree($tmpBase);
        return ['ok' => false, 'error' => 'Could not extract update zip.'];
    }
    $zip->close();

    $sourceRoot = appUpdaterFindExtractRoot($extractDir);
    if ($sourceRoot === null || !is_dir($sourceRoot)) {
        appUpdaterRemoveTree($tmpBase);
        return ['ok' => false, 'error' => 'Unexpected zip layout — no source root found.'];
    }

    $stats = appUpdaterCopyTree($sourceRoot, $root);
    appUpdaterRemoveTree($tmpBase);

    if ($stats['failed'] > 0 && $stats['copied'] === 0) {
        return [
            'ok' => false,
            'error' => 'Update failed — no files could be copied.',
            'mode' => 'full',
            'copied' => $stats['copied'],
            'deleted' => 0,
            'skipped' => $stats['skipped'],
            'failed' => $stats['failed'],
            'errors' => $stats['errors'],
            'remote' => $remote,
        ];
    }

    $settings = appUpdaterLoadSettings();
    $settings['installed_sha'] = $headSha !== '' ? $headSha : (string) ($remote['sha'] ?? '');
    $settings['installed_tag'] = (string) ($remote['tag'] ?? '');
    $settings['installed_at'] = date('c');
    appUpdaterSaveSettings($settings);

    return [
        'ok' => true,
        'error' => null,
        'mode' => 'full',
        'copied' => $stats['copied'],
        'deleted' => 0,
        'skipped' => $stats['skipped'],
        'failed' => $stats['failed'],
        'errors' => $stats['errors'],
        'remote' => $remote,
    ];
}

/**
 * Public status payload for the settings UI.
 */
function appUpdaterStatusPayload(): array
{
    $settings = appUpdaterLoadSettings();
    $installedLabel = 'Not recorded';
    if ($settings['installed_tag'] !== '') {
        $installedLabel = $settings['installed_tag'];
    } elseif ($settings['installed_sha'] !== '') {
        $installedLabel = substr($settings['installed_sha'], 0, 7);
    }

    return [
        'ok' => true,
        'github_owner' => $settings['github_owner'],
        'github_repo' => $settings['github_repo'],
        'github_branch' => $settings['github_branch'],
        'has_token' => trim((string) $settings['github_token']) !== '',
        'installed_sha' => $settings['installed_sha'],
        'installed_tag' => $settings['installed_tag'],
        'installed_label' => $installedLabel,
        'installed_at' => $settings['installed_at'],
        'last_check_at' => $settings['last_check_at'],
        'last_remote_sha' => $settings['last_remote_sha'],
        'last_remote_tag' => $settings['last_remote_tag'],
        'last_remote_name' => $settings['last_remote_name'],
        'protected_files' => appUpdaterProtectedFiles(),
        'protected_dirs' => array_values(array_filter(appUpdaterProtectedDirs(), static function ($d) {
            return $d !== APP_UPDATER_TMP_DIR && $d !== '.git';
        })),
        'update_available' => appUpdaterIsUpdateAvailable($settings),
    ];
}

function appUpdaterIsUpdateAvailable(array $settings): bool
{
    $remote = (string) ($settings['last_remote_sha'] ?? '');
    $local = (string) ($settings['installed_sha'] ?? '');
    if ($remote === '') {
        return false;
    }
    if ($local === '') {
        return true;
    }
    // Compare tag or full/short sha
    if ($settings['last_remote_tag'] !== '' && $settings['installed_tag'] !== '') {
        return $settings['last_remote_tag'] !== $settings['installed_tag'];
    }
    return strcasecmp($remote, $local) !== 0
        && strncasecmp($remote, $local, 7) !== 0
        && strncasecmp($local, $remote, 7) !== 0;
}
