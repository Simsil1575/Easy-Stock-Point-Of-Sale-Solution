<?php
/**
 * App updater — download a GitHub zip and apply it over htdocs,
 * while leaving local databases and the products folder untouched.
 */

date_default_timezone_set('Africa/Harare');

define('APP_UPDATER_SETTINGS_FILE', 'app_updater_settings.json');
define('APP_UPDATER_TMP_DIR', '.update_tmp');
define('APP_UPDATER_DEFAULT_OWNER', 'Simsil1575');
define('APP_UPDATER_DEFAULT_REPO', 'Easy-Stock-Point-Of-Sale-Solution');
define('APP_UPDATER_DEFAULT_BRANCH', 'main');

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
function appUpdaterHttpGet(string $url, array $headers = [], int $timeout = 120): array
{
    $defaultHeaders = [
        'User-Agent: EasyStock-POS-Updater',
        'Accept: application/vnd.github+json',
    ];
    $allHeaders = array_merge($defaultHeaders, $headers);

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
            $sha = '';
            if (!empty($data['target_commitish'])) {
                $sha = (string) $data['target_commitish'];
            }
            // Prefer zipball_url from API (works with private repos + token)
            $zipUrl = !empty($data['zipball_url'])
                ? (string) $data['zipball_url']
                : "https://api.github.com/repos/{$owner}/{$repo}/zipball/" . rawurlencode($tag);

            $settings['last_check_at'] = date('c');
            $settings['last_remote_sha'] = $sha !== '' ? $sha : $tag;
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
 * Download remote zip and apply over the install.
 *
 * @return array{ok:bool,error:?string,copied?:int,skipped?:int,failed?:int,errors?:string[],remote?:array}
 */
function appUpdaterApplyUpdate(?array $remote = null): array
{
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => 'PHP ZipArchive extension is required.'];
    }

    $settings = appUpdaterLoadSettings();
    if ($remote === null) {
        $remote = appUpdaterCheckRemote($settings);
    }
    if (empty($remote['ok'])) {
        return ['ok' => false, 'error' => $remote['error'] ?? 'Could not resolve remote version.'];
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
            'copied' => $stats['copied'],
            'skipped' => $stats['skipped'],
            'failed' => $stats['failed'],
            'errors' => $stats['errors'],
            'remote' => $remote,
        ];
    }

    $settings = appUpdaterLoadSettings();
    $settings['installed_sha'] = (string) ($remote['sha'] ?? '');
    $settings['installed_tag'] = (string) ($remote['tag'] ?? '');
    $settings['installed_at'] = date('c');
    appUpdaterSaveSettings($settings);

    return [
        'ok' => true,
        'error' => null,
        'copied' => $stats['copied'],
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
