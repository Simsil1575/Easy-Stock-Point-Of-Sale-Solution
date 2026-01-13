<?php
/**
 * Activation Helper Functions
 * Manages software key activation and expiration checking
 */

date_default_timezone_set('Africa/Harare');

/**
 * Check if the current activation has expired
 * Returns array with status and details
 */
function checkActivationStatus() {
    try {
        $pdo = new PDO('sqlite:active.db');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Get the currently activated key
        $stmt = $pdo->prepare("SELECT * FROM software_keys WHERE is_used = 1 LIMIT 1");
        $stmt->execute();
        $activeKey = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$activeKey) {
            return [
                'status' => 'not_activated',
                'message' => 'No active key found. Please activate the software.',
                'expired' => false
            ];
        }
        
        // Check if expiration date is set
        if (empty($activeKey['expires_at'])) {
            return [
                'status' => 'active',
                'message' => 'Software is activated.',
                'expired' => false,
                'key_data' => $activeKey
            ];
        }
        
        // Check if expired
        $expiresAt = new DateTime($activeKey['expires_at']);
        $now = new DateTime();
        
        if ($now > $expiresAt) {
            // Mark as expired by setting is_used to 0
            $updateStmt = $pdo->prepare("UPDATE software_keys SET is_used = 0 WHERE id = :id");
            $updateStmt->execute([':id' => $activeKey['id']]);
            
            return [
                'status' => 'expired',
                'message' => 'Your activation key has expired. Please enter a new activation key.',
                'expired' => true,
                'expired_date' => $activeKey['expires_at']
            ];
        }
        
        // Calculate days remaining
        $daysRemaining = $now->diff($expiresAt)->days;
        
        return [
            'status' => 'active',
            'message' => 'Software is activated.',
            'expired' => false,
            'days_remaining' => $daysRemaining,
            'expires_at' => $activeKey['expires_at'],
            'key_data' => $activeKey
        ];
        
    } catch (PDOException $e) {
        return [
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage(),
            'expired' => false
        ];
    }
}

/**
 * Activate a software key
 * Returns array with success status and message
 */
function activateKey($keyToActivate) {
    try {
        $pdo = new PDO('sqlite:active.db');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Check if key exists and is not used
        $stmt = $pdo->prepare("SELECT * FROM software_keys WHERE key = :key");
        $stmt->execute([':key' => $keyToActivate]);
        $key = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$key) {
            return [
                'success' => false,
                'message' => 'Invalid activation key.'
            ];
        }
        
        if ($key['is_used'] == 1) {
            return [
                'success' => false,
                'message' => 'This key has already been used. Please use a different key.'
            ];
        }
        
        // Deactivate all other keys first
        $deactivateStmt = $pdo->prepare("UPDATE software_keys SET is_used = 0");
        $deactivateStmt->execute();
        
        // Activate the new key with expiration date (30 days from now)
        $activatedAt = (new DateTime())->format('Y-m-d H:i:s');
        $expiresAt = (new DateTime())->modify('+30 days')->format('Y-m-d H:i:s');
        
        $activateStmt = $pdo->prepare("UPDATE software_keys SET is_used = 1, activated_at = :activated_at, expires_at = :expires_at WHERE id = :id");
        $activateStmt->execute([
            ':activated_at' => $activatedAt,
            ':expires_at' => $expiresAt,
            ':id' => $key['id']
        ]);
        
        return [
            'success' => true,
            'message' => 'Software activated successfully! Valid until ' . date('M d, Y', strtotime($expiresAt)),
            'expires_at' => $expiresAt
        ];
        
    } catch (PDOException $e) {
        return [
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
}

/**
 * Get list of unused keys
 */
function getUnusedKeys() {
    try {
        $pdo = new PDO('sqlite:active.db');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->prepare("SELECT * FROM software_keys WHERE is_used = 0");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Get activation history
 */
function getActivationHistory() {
    try {
        $pdo = new PDO('sqlite:active.db');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->prepare("SELECT * FROM software_keys WHERE activated_at IS NOT NULL ORDER BY activated_at DESC");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Check if database needs migration to new schema
 */
function migrateActivationDatabase() {
    try {
        $pdo = new PDO('sqlite:active.db');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Check if columns exist
        $result = $pdo->query("PRAGMA table_info(software_keys)");
        $columns = $result->fetchAll(PDO::FETCH_ASSOC);
        
        $hasActivatedAt = false;
        $hasExpiresAt = false;
        
        foreach ($columns as $column) {
            if ($column['name'] === 'activated_at') $hasActivatedAt = true;
            if ($column['name'] === 'expires_at') $hasExpiresAt = true;
        }
        
        // Add missing columns
        if (!$hasActivatedAt) {
            $pdo->exec("ALTER TABLE software_keys ADD COLUMN activated_at TEXT DEFAULT NULL");
        }
        
        if (!$hasExpiresAt) {
            $pdo->exec("ALTER TABLE software_keys ADD COLUMN expires_at TEXT DEFAULT NULL");
        }
        
        return true;
        
    } catch (PDOException $e) {
        error_log('Migration error: ' . $e->getMessage());
        return false;
    }
}

// Auto-migrate on include
migrateActivationDatabase();

