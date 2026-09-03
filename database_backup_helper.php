<?php
/**
 * SQLite database backups (info.db, pos.db, active.db, user.db).
 */

const DATABASE_BACKUP_FILES = ['info.db', 'pos.db', 'active.db', 'user.db'];

function getDatabaseBackupRootDir(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'backups';
}

function ensureDatabaseBackupRootDir(): string
{
    $dir = getDatabaseBackupRootDir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }

    $index = $dir . DIRECTORY_SEPARATOR . 'index.php';
    if (!is_file($index)) {
        file_put_contents($index, "<?php\nhttp_response_code(403);\nexit('Forbidden');\n");
    }

    return $dir;
}

function getDatabaseBackupSettingsDb(): PDO
{
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'info.db';
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    ensureDatabaseBackupSettingsSchema($db);
    return $db;
}

function ensureDatabaseBackupSettingsSchema(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS system_settings (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        backup_on_logout INTEGER NOT NULL DEFAULT 0
    )');

    $count = (int) $db->query('SELECT COUNT(*) FROM system_settings')->fetchColumn();
    if ($count < 1) {
        $db->exec('INSERT INTO system_settings (id, backup_on_logout) VALUES (1, 0)');
    }
}

function loadDatabaseBackupSettings(): array
{
    $db = getDatabaseBackupSettingsDb();
    $row = $db->query('SELECT backup_on_logout FROM system_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

    return [
        'backup_on_logout' => !empty($row['backup_on_logout']),
    ];
}

function saveDatabaseBackupSettings(bool $backupOnLogout): void
{
    $db = getDatabaseBackupSettingsDb();
    $stmt = $db->prepare('UPDATE system_settings SET backup_on_logout = ? WHERE id = 1');
    $stmt->execute([$backupOnLogout ? 1 : 0]);
}

function sanitizeDatabaseBackupFolderName(string $name): string
{
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', $name) ?? '';
}

function buildDatabaseBackupFolderName(string $trigger, ?string $username = null): string
{
    $timestamp = date('Y-m-d_H-i-s');
    $trigger = preg_replace('/[^a-z_]/', '', strtolower($trigger)) ?: 'manual';
    $userPart = '';
    if ($username !== null && $username !== '') {
        $safeUser = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $username);
        $userPart = '_' . substr($safeUser, 0, 32);
    }

    return $timestamp . '_' . $trigger . $userPart;
}

/**
 * @return array{success:bool,folder?:string,path?:string,copied:array,skipped:array,errors:array}
 */
function createDatabaseBackup(string $trigger = 'manual', ?string $username = null): array
{
    $root = ensureDatabaseBackupRootDir();
    $folder = buildDatabaseBackupFolderName($trigger, $username);
    $destDir = $root . DIRECTORY_SEPARATOR . $folder;

    if (is_dir($destDir)) {
        return [
            'success' => false,
            'errors' => ['Backup folder already exists.'],
            'copied' => [],
            'skipped' => [],
        ];
    }

    if (!mkdir($destDir, 0755, true)) {
        return [
            'success' => false,
            'errors' => ['Could not create backup folder.'],
            'copied' => [],
            'skipped' => [],
        ];
    }

    $copied = [];
    $skipped = [];
    $errors = [];

    foreach (DATABASE_BACKUP_FILES as $file) {
        $source = __DIR__ . DIRECTORY_SEPARATOR . $file;
        $target = $destDir . DIRECTORY_SEPARATOR . $file;

        if (!is_file($source)) {
            $skipped[] = $file;
            continue;
        }

        if (@copy($source, $target)) {
            $copied[] = $file;
        } else {
            $errors[] = 'Failed to copy ' . $file;
        }
    }

    $manifest = [
        'created_at' => date('c'),
        'trigger' => $trigger,
        'username' => $username,
        'files' => $copied,
        'skipped' => $skipped,
    ];
    file_put_contents(
        $destDir . DIRECTORY_SEPARATOR . 'manifest.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    if (empty($copied)) {
        $errors[] = 'No database files were copied.';
    }

    return [
        'success' => empty($errors),
        'folder' => $folder,
        'path' => $destDir,
        'copied' => $copied,
        'skipped' => $skipped,
        'errors' => $errors,
    ];
}

/**
 * @return array<int,array{folder:string,created_at:string,trigger:string,username:string,files:array,total_size:int}>
 */
function listDatabaseBackups(): array
{
    $root = ensureDatabaseBackupRootDir();
    $entries = [];

    foreach (glob($root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
        $folder = basename($dir);
        if ($folder === '' || $folder[0] === '.') {
            continue;
        }

        $manifestPath = $dir . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifest = is_file($manifestPath)
            ? json_decode((string) file_get_contents($manifestPath), true)
            : [];

        $files = [];
        $totalSize = 0;
        foreach (DATABASE_BACKUP_FILES as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_file($path)) {
                $size = filesize($path) ?: 0;
                $files[] = [
                    'name' => $file,
                    'size' => $size,
                ];
                $totalSize += $size;
            }
        }

        if (empty($files)) {
            continue;
        }

        $createdAt = $manifest['created_at'] ?? date('c', filemtime($dir) ?: time());
        $entries[] = [
            'folder' => $folder,
            'created_at' => (string) $createdAt,
            'trigger' => (string) ($manifest['trigger'] ?? 'unknown'),
            'username' => (string) ($manifest['username'] ?? ''),
            'files' => $files,
            'total_size' => $totalSize,
        ];
    }

    usort($entries, static function (array $a, array $b): int {
        return strcmp($b['created_at'], $a['created_at']);
    });

    return $entries;
}

function formatDatabaseBackupBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / 1048576, 2) . ' MB';
}

function getDatabaseBackupFolderPath(string $folder): ?string
{
    $folder = sanitizeDatabaseBackupFolderName($folder);
    if ($folder === '') {
        return null;
    }

    $path = getDatabaseBackupRootDir() . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($path)) {
        return null;
    }

    return $path;
}

function deleteDatabaseBackup(string $folder): bool
{
    $path = getDatabaseBackupFolderPath($folder);
    if ($path === null) {
        return false;
    }

    $items = glob($path . DIRECTORY_SEPARATOR . '*') ?: [];
    foreach ($items as $item) {
        if (is_file($item)) {
            unlink($item);
        }
    }

    return rmdir($path);
}

function createDatabaseBackupZip(string $folder): ?string
{
    if (!class_exists('ZipArchive')) {
        return null;
    }

    $path = getDatabaseBackupFolderPath($folder);
    if ($path === null) {
        return null;
    }

    $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'db_backup_' . $folder . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return null;
    }

    foreach (glob($path . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file)) {
            $zip->addFile($file, basename($file));
        }
    }

    $zip->close();
    return $zipPath;
}
