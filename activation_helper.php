<?php
/**
 * Secure Activation Helper Functions
 * Manages software key activation and expiration checking with security features
 * 
 * Security Features:
 * - CSRF protection
 * - Rate limiting on activation attempts
 * - Input sanitization and validation
 * - Audit logging
 * - Expiration checking
 */

date_default_timezone_set('Africa/Harare');

// ============================================================================
// CONFIGURATION
// ============================================================================

define('ACTIVATION_MAX_ATTEMPTS', 5);           // Max failed attempts before lockout
define('ACTIVATION_LOCKOUT_MINUTES', 30);       // Lockout duration in minutes
define('ACTIVATION_KEY_MIN_LENGTH', 8);         // Minimum key length
define('ACTIVATION_KEY_MAX_LENGTH', 64);        // Maximum key length
define('ACTIVATION_DEFAULT_DAYS', 30);          // Default activation period in days

// ============================================================================
// CSRF PROTECTION
// ============================================================================

/**
 * Generate a CSRF token for activation form
 */
function generateActivationCSRFToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (empty($_SESSION['activation_csrf_token'])) {
        $_SESSION['activation_csrf_token'] = bin2hex(random_bytes(32));
    }
    
    return $_SESSION['activation_csrf_token'];
}

/**
 * Validate CSRF token
 */
function validateActivationCSRFToken($token) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (empty($_SESSION['activation_csrf_token']) || empty($token)) {
        return false;
    }
    
    // Use hash_equals to prevent timing attacks
    $valid = hash_equals($_SESSION['activation_csrf_token'], $token);
    
    // Regenerate token after validation
    if ($valid) {
        $_SESSION['activation_csrf_token'] = bin2hex(random_bytes(32));
    }
    
    return $valid;
}

// ============================================================================
// RATE LIMITING
// ============================================================================

/**
 * Check if IP is rate limited
 */
function isActivationRateLimited() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'activation_attempts_' . md5($ip);
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'first_attempt' => time()];
    }
    
    $attempts = $_SESSION[$key];
    
    // Reset if lockout period has passed
    if (time() - $attempts['first_attempt'] > ACTIVATION_LOCKOUT_MINUTES * 60) {
        $_SESSION[$key] = ['count' => 0, 'first_attempt' => time()];
        return false;
    }
    
    return $attempts['count'] >= ACTIVATION_MAX_ATTEMPTS;
}

/**
 * Record a failed activation attempt
 */
function recordFailedActivationAttempt() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'activation_attempts_' . md5($ip);
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'first_attempt' => time()];
    }
    
    $_SESSION[$key]['count']++;
    
    // Log the failed attempt
    logActivationAttempt('FAILED', 'Rate limit: ' . $_SESSION[$key]['count'] . '/' . ACTIVATION_MAX_ATTEMPTS);
}

/**
 * Reset rate limit on successful activation
 */
function resetActivationRateLimit() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'activation_attempts_' . md5($ip);
    unset($_SESSION[$key]);
}

/**
 * Get remaining lockout time in minutes
 */
function getActivationLockoutRemaining() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'activation_attempts_' . md5($ip);
    
    if (!isset($_SESSION[$key])) {
        return 0;
    }
    
    $elapsed = time() - $_SESSION[$key]['first_attempt'];
    $remaining = (ACTIVATION_LOCKOUT_MINUTES * 60) - $elapsed;
    
    return max(0, ceil($remaining / 60));
}

// ============================================================================
// INPUT VALIDATION
// ============================================================================

/**
 * Sanitize and validate activation key input
 */
function sanitizeActivationKey($key) {
    // Remove whitespace
    $key = trim($key);
    
    // Remove any HTML/PHP tags
    $key = strip_tags($key);
    
    // Only allow alphanumeric, hyphens, and underscores
    $key = preg_replace('/[^a-zA-Z0-9\-_]/', '', $key);
    
    return $key;
}

/**
 * Validate key format
 */
function validateKeyFormat($key) {
    $length = strlen($key);
    
    if ($length < ACTIVATION_KEY_MIN_LENGTH) {
        return [
            'valid' => false,
            'message' => 'Activation key is too short.'
        ];
    }
    
    if ($length > ACTIVATION_KEY_MAX_LENGTH) {
        return [
            'valid' => false,
            'message' => 'Activation key is too long.'
        ];
    }
    
    // Must contain at least one letter and one number for basic security
    if (!preg_match('/[a-zA-Z]/', $key) || !preg_match('/[0-9]/', $key)) {
        return [
            'valid' => false,
            'message' => 'Invalid activation key format.'
        ];
    }
    
    return ['valid' => true, 'message' => ''];
}

// ============================================================================
// AUDIT LOGGING
// ============================================================================

/**
 * Log activation attempt
 */
function logActivationAttempt($status, $details = '') {
    try {
        $pdo = getActivationDatabase();
        
        // Create log table if it doesn't exist
        $pdo->exec("CREATE TABLE IF NOT EXISTS activation_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT NOT NULL,
            status TEXT NOT NULL,
            details TEXT,
            user_agent TEXT,
            user_id INTEGER,
            username TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Get current user info if available
        $userId = $_SESSION['user_id'] ?? null;
        $username = $_SESSION['username'] ?? null;
        
        $stmt = $pdo->prepare("INSERT INTO activation_logs (ip_address, status, details, user_agent, user_id, username) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $status,
            $details,
            substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255),
            $userId,
            $username
        ]);
        
        // Keep only last 1000 log entries
        $pdo->exec("DELETE FROM activation_logs WHERE id NOT IN (SELECT id FROM activation_logs ORDER BY id DESC LIMIT 1000)");
        
    } catch (PDOException $e) {
        // Silently fail - logging shouldn't break activation
        error_log('Activation log error: ' . $e->getMessage());
    }
}

// ============================================================================
// DATABASE FUNCTIONS
// ============================================================================

/**
 * Get activation database connection with proper path handling
 */
function getActivationDatabase() {
    static $cachedPdo = null;
    static $cachedPath = null;
    
    // Return cached connection if available
    if ($cachedPdo !== null) {
        return $cachedPdo;
    }
    
    // Determine the correct path to active.db
    $possiblePaths = [
        __DIR__ . '/active.db',
        __DIR__ . '/../active.db',
        'active.db',
        '../active.db'
    ];
    
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            $realPath = realpath($path);
            $pdo = new PDO('sqlite:' . $realPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Ensure schema is up to date
            ensureActivationSchema($pdo);
            
            $cachedPdo = $pdo;
            $cachedPath = $realPath;
            return $pdo;
        }
    }
    
    // Create new database in root
    $dbPath = __DIR__ . '/active.db';
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Initialize database schema
    initializeActivationDatabase($pdo);
    
    return $pdo;
}

/**
 * Initialize activation database schema
 */
function initializeActivationDatabase($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS software_keys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        key TEXT NOT NULL UNIQUE,
        is_used INTEGER DEFAULT 0,
        activated_at TEXT DEFAULT NULL,
        expires_at TEXT DEFAULT NULL,
        machine_id TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Create index for faster lookups
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_software_keys_key ON software_keys(key)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_software_keys_is_used ON software_keys(is_used)");
}

/**
 * Ensure the activation schema has all required columns
 * Called every time we get a database connection
 */
function ensureActivationSchema($pdo) {
    try {
        // Check existing columns
        $result = $pdo->query("PRAGMA table_info(software_keys)");
        $columns = $result->fetchAll(PDO::FETCH_ASSOC);
        $existingColumns = array_column($columns, 'name');
        
        // Add missing columns
        $requiredColumns = [
            'activated_at' => 'TEXT DEFAULT NULL',
            'expires_at' => 'TEXT DEFAULT NULL',
            'machine_id' => 'TEXT DEFAULT NULL'
        ];
        
        foreach ($requiredColumns as $column => $definition) {
            if (!in_array($column, $existingColumns)) {
                try {
                    $pdo->exec("ALTER TABLE software_keys ADD COLUMN $column $definition");
                    error_log("Added missing column '$column' to software_keys table");
                } catch (PDOException $e) {
                    // Column might already exist, ignore
                }
            }
        }
    } catch (PDOException $e) {
        error_log('Schema check error: ' . $e->getMessage());
    }
}

/**
 * Migrate database schema if needed
 */
function migrateActivationDatabase() {
    try {
        $pdo = getActivationDatabase();
        
        // Check if columns exist
        $result = $pdo->query("PRAGMA table_info(software_keys)");
        $columns = $result->fetchAll(PDO::FETCH_ASSOC);
        
        $existingColumns = array_column($columns, 'name');
        
        // Add missing columns
        $newColumns = [
            'activated_at' => 'TEXT DEFAULT NULL',
            'expires_at' => 'TEXT DEFAULT NULL',
            'machine_id' => 'TEXT DEFAULT NULL'
        ];
        
        foreach ($newColumns as $column => $definition) {
            if (!in_array($column, $existingColumns)) {
                try {
                    $pdo->exec("ALTER TABLE software_keys ADD COLUMN $column $definition");
                } catch (PDOException $e) {
                    // Column might already exist
                }
            }
        }
        
        return true;
        
    } catch (PDOException $e) {
        error_log('Migration error: ' . $e->getMessage());
        return false;
    }
}

// ============================================================================
// MAIN ACTIVATION FUNCTIONS
// ============================================================================

/**
 * Check if the current activation has expired
 * Returns array with status and details
 */
function checkActivationStatus() {
    try {
        $pdo = getActivationDatabase();
        
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
            
            logActivationAttempt('EXPIRED', 'Key expired on ' . $activeKey['expires_at']);
            
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
        error_log('Activation check error: ' . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Unable to verify activation status.',
            'expired' => false
        ];
    }
}

/**
 * Activate a software key with full security checks
 * Returns array with success status and message
 */
function activateKey($keyToActivate, $csrfToken = null) {
    // CSRF validation (if token provided)
    if ($csrfToken !== null && !validateActivationCSRFToken($csrfToken)) {
        logActivationAttempt('CSRF_FAILED', 'Invalid CSRF token');
        return [
            'success' => false,
            'message' => 'Security validation failed. Please refresh the page and try again.'
        ];
    }
    
    // Rate limiting check
    if (isActivationRateLimited()) {
        $remaining = getActivationLockoutRemaining();
        logActivationAttempt('RATE_LIMITED', 'Locked out for ' . $remaining . ' minutes');
        return [
            'success' => false,
            'message' => "Too many failed attempts. Please try again in {$remaining} minutes."
        ];
    }
    
    // Sanitize input
    $keyToActivate = sanitizeActivationKey($keyToActivate);
    
    // Validate key format
    $formatCheck = validateKeyFormat($keyToActivate);
    if (!$formatCheck['valid']) {
        recordFailedActivationAttempt();
        logActivationAttempt('INVALID_FORMAT', $formatCheck['message']);
        return [
            'success' => false,
            'message' => $formatCheck['message']
        ];
    }
    
    try {
        $pdo = getActivationDatabase();
        
        // Check if key exists
        $stmt = $pdo->prepare("SELECT * FROM software_keys WHERE key = :key");
        $stmt->execute([':key' => $keyToActivate]);
        $key = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$key) {
            recordFailedActivationAttempt();
            logActivationAttempt('KEY_NOT_FOUND', 'Attempted key: ' . substr($keyToActivate, 0, 4) . '****');
            return [
                'success' => false,
                'message' => 'Invalid activation key.'
            ];
        }
        
        if ($key['is_used'] == 1) {
            recordFailedActivationAttempt();
            logActivationAttempt('KEY_ALREADY_USED', 'Key ID: ' . $key['id']);
            return [
                'success' => false,
                'message' => 'This key has already been used. Please use a different key.'
            ];
        }
        
        // Check if key has expired before first use (if expires_at was pre-set)
        if (!empty($key['expires_at'])) {
            $expiresAt = new DateTime($key['expires_at']);
            $now = new DateTime();
            
            if ($now > $expiresAt) {
                recordFailedActivationAttempt();
                logActivationAttempt('KEY_EXPIRED_UNUSED', 'Key expired before activation');
                return [
                    'success' => false,
                    'message' => 'This activation key has expired.'
                ];
            }
        }
        
        // Begin transaction
        $pdo->beginTransaction();
        
        try {
            // Deactivate all other keys first
            $deactivateStmt = $pdo->prepare("UPDATE software_keys SET is_used = 0");
            $deactivateStmt->execute();
            
            // Calculate expiration (30 days from now)
            $activatedAt = (new DateTime())->format('Y-m-d H:i:s');
            $expiresAt = (new DateTime())->modify('+' . ACTIVATION_DEFAULT_DAYS . ' days')->format('Y-m-d H:i:s');
            
            // Activate the new key with expiration (ignore machine_id)
            $activateStmt = $pdo->prepare("UPDATE software_keys SET is_used = 1, activated_at = :activated_at, expires_at = :expires_at WHERE id = :id");
            $activateStmt->execute([
                ':activated_at' => $activatedAt,
                ':expires_at' => $expiresAt,
                ':id' => $key['id']
            ]);
            
            // Verify the update worked
            $rowsAffected = $activateStmt->rowCount();
            if ($rowsAffected === 0) {
                throw new PDOException("No rows were updated during activation");
            }
            
            $pdo->commit();
            
            // Reset rate limit on success
            resetActivationRateLimit();
            
            // Log successful activation
            logActivationAttempt('SUCCESS', 'Key ID: ' . $key['id'] . ', Expires: ' . $expiresAt);
            
            return [
                'success' => true,
                'message' => 'Software activated successfully! Valid until ' . date('M d, Y', strtotime($expiresAt)),
                'expires_at' => $expiresAt
            ];
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }
        
    } catch (PDOException $e) {
        error_log('Activation error: ' . $e->getMessage());
        logActivationAttempt('DATABASE_ERROR', $e->getMessage());
        return [
            'success' => false,
            'message' => 'An error occurred during activation. Please try again.'
        ];
    }
}

/**
 * Quick check if software is activated (for use in page guards)
 * Returns boolean
 */
function isActivated() {
    $status = checkActivationStatus();
    return $status['status'] === 'active';
}

/**
 * Redirect to settings if not activated
 */
function requireActivation($settingsPath = 'settings.php') {
    if (!isActivated()) {
        header('Location: ' . $settingsPath);
        exit();
    }
}

/**
 * Get list of unused keys (admin only)
 */
function getUnusedKeys() {
    try {
        $pdo = getActivationDatabase();
        
        $stmt = $pdo->prepare("SELECT id, key, created_at FROM software_keys WHERE is_used = 0 ORDER BY created_at DESC");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Get activation history (admin only)
 */
function getActivationHistory($limit = 50) {
    try {
        $pdo = getActivationDatabase();
        
        $stmt = $pdo->prepare("SELECT * FROM activation_logs ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$limit]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Add a new activation key (admin only)
 */
function addActivationKey($key) {
    $key = sanitizeActivationKey($key);
    $formatCheck = validateKeyFormat($key);
    
    if (!$formatCheck['valid']) {
        return ['success' => false, 'message' => $formatCheck['message']];
    }
    
    try {
        $pdo = getActivationDatabase();
        
        // Check if key already exists
        $stmt = $pdo->prepare("SELECT id FROM software_keys WHERE key = :key");
        $stmt->execute([':key' => $key]);
        
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'This key already exists.'];
        }
        
        // Insert new key
        $stmt = $pdo->prepare("INSERT INTO software_keys (key, is_used, created_at) VALUES (:key, 0, datetime('now'))");
        $stmt->execute([':key' => $key]);
        
        logActivationAttempt('KEY_ADDED', 'New key added: ' . substr($key, 0, 4) . '****');
        
        return ['success' => true, 'message' => 'Activation key added successfully.'];
        
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Failed to add key: ' . $e->getMessage()];
    }
}

// ============================================================================
// AUTO-INITIALIZE
// ============================================================================

// Auto-migrate on include
migrateActivationDatabase();
