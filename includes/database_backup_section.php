<?php
/**
 * Database backup UI for System management settings.
 *
 * Expects: $backupSettings (array), $databaseBackups (array), $settingsBaseUrl (string, optional)
 */
$settingsBaseUrl = $settingsBaseUrl ?? 'settings?s=system';
$downloadBase = '../download_database_backup.php';
?>
<div class="mt-8 pt-6 border-t border-gray-200">
    <h3 class="text-base font-semibold text-gray-900 mb-1">Database backup</h3>
    <p class="text-sm text-gray-500 mb-5">
        Saves info.db, pos.db, active.db and user.db to the backups folder.
    </p>

    <div class="border border-gray-200 rounded-lg p-4 mb-5 bg-white">
        <form method="POST" action="<?= htmlspecialchars($settingsBaseUrl) ?>" class="mb-4">
            <input type="hidden" name="run_database_backup" value="1">
            <button type="submit" class="px-4 py-2 text-sm border border-gray-300 rounded bg-white text-gray-800 hover:bg-gray-50">
                Create backup
            </button>
        </form>

        <form method="POST" action="<?= htmlspecialchars($settingsBaseUrl) ?>">
            <input type="hidden" name="update_database_backup_settings" value="1">
            <label class="flex items-center gap-2 text-sm text-gray-700 mb-3">
                <input
                    type="checkbox"
                    name="backup_on_logout"
                    value="1"
                    class="h-4 w-4 border-gray-300 rounded"
                    <?= !empty($backupSettings['backup_on_logout']) ? 'checked' : '' ?>
                >
                Backup automatically on logout
            </label>
            <button type="submit" class="px-4 py-2 text-sm border border-gray-300 rounded bg-white text-gray-800 hover:bg-gray-50">
                Save
            </button>
        </form>
    </div>

    <div>
        <h4 class="text-sm font-medium text-gray-900 mb-3">Backups (<?= count($databaseBackups) ?>)</h4>

        <?php if (empty($databaseBackups)): ?>
            <p class="text-sm text-gray-500 border border-gray-200 rounded-lg p-4 bg-gray-50">
                No backups yet.
            </p>
        <?php else: ?>
            <div class="overflow-x-auto border border-gray-200 rounded-lg">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium text-gray-600">Date</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-600">Type</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-600">User</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-600">Size</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-600">Download</th>
                            <th class="px-4 py-2 text-right font-medium text-gray-600"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($databaseBackups as $backup): ?>
                            <tr class="border-t border-gray-100">
                                <td class="px-4 py-3 text-gray-800">
                                    <?= htmlspecialchars(date('Y-m-d H:i', strtotime($backup['created_at']))) ?>
                                </td>
                                <td class="px-4 py-3 text-gray-600 capitalize">
                                    <?= htmlspecialchars($backup['trigger']) ?>
                                </td>
                                <td class="px-4 py-3 text-gray-600">
                                    <?= $backup['username'] !== '' ? htmlspecialchars($backup['username']) : '—' ?>
                                </td>
                                <td class="px-4 py-3 text-gray-600">
                                    <?= htmlspecialchars(formatDatabaseBackupBytes((int) $backup['total_size'])) ?>
                                </td>
                                <td class="px-4 py-3 text-gray-600">
                                    <a
                                        href="<?= htmlspecialchars($downloadBase . '?folder=' . urlencode($backup['folder']) . '&zip=1') ?>"
                                        class="underline hover:text-gray-900"
                                    >All files (zip)</a>
                                    <?php foreach ($backup['files'] as $index => $file): ?>
                                        <span class="text-gray-300">|</span>
                                        <a
                                            href="<?= htmlspecialchars($downloadBase . '?folder=' . urlencode($backup['folder']) . '&file=' . urlencode($file['name'])) ?>"
                                            class="underline hover:text-gray-900"
                                        ><?= htmlspecialchars($file['name']) ?></a>
                                    <?php endforeach; ?>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <form method="POST" action="<?= htmlspecialchars($settingsBaseUrl) ?>" class="inline" onsubmit="return confirm('Delete this backup?');">
                                        <input type="hidden" name="delete_database_backup" value="1">
                                        <input type="hidden" name="backup_folder" value="<?= htmlspecialchars($backup['folder']) ?>">
                                        <button type="submit" class="text-sm text-gray-500 underline hover:text-gray-800">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
