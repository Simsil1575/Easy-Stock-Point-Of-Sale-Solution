<?php
/**
 * Manager void PIN stored in info.db (business_info.manager_void_pin, bcrypt hash).
 */

function ensureManagerVoidPinColumn(PDO $db): void
{
    try {
        $db->exec('ALTER TABLE business_info ADD COLUMN manager_void_pin TEXT');
    } catch (PDOException $e) {
        // column exists
    }
}

function getInfoDbForManagerPin(): PDO
{
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'info.db';
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    ensureManagerVoidPinColumn($db);
    return $db;
}

function managerVoidPinIsConfigured(): bool
{
    try {
        $db = getInfoDbForManagerPin();
        $row = $db->query('SELECT manager_void_pin FROM business_info LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $hash = $row['manager_void_pin'] ?? null;
        return is_string($hash) && $hash !== '';
    } catch (Throwable $e) {
        return false;
    }
}

function verifyManagerVoidPin(string $pin): bool
{
    $pin = trim($pin);
    if ($pin === '') {
        return false;
    }
    try {
        $db = getInfoDbForManagerPin();
        $row = $db->query('SELECT manager_void_pin FROM business_info LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $hash = $row['manager_void_pin'] ?? null;
        if (!is_string($hash) || $hash === '') {
            return false;
        }
        return password_verify($pin, $hash);
    } catch (Throwable $e) {
        return false;
    }
}

function setManagerVoidPin(string $plainPin): void
{
    $plainPin = trim($plainPin);
    if ($plainPin === '') {
        throw new InvalidArgumentException('PIN cannot be empty.');
    }
    $hash = password_hash($plainPin, PASSWORD_DEFAULT);
    $db = getInfoDbForManagerPin();
    $count = (int) $db->query('SELECT COUNT(*) FROM business_info')->fetchColumn();
    if ($count < 1) {
        throw new RuntimeException('Add business information first (Business settings), then set the manager PIN.');
    }
    $stmt = $db->prepare('UPDATE business_info SET manager_void_pin = ? WHERE id = (SELECT id FROM business_info ORDER BY id LIMIT 1)');
    $stmt->execute([$hash]);
}
