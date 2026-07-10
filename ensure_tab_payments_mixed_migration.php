<?php
/**
 * Older pos.db files define tab_payments.payment_method CHECK as only ('cash','eft').
 * The tab Pay UI also sends 'mixed' (cash + EFT), which violates that constraint.
 * This migrates the table once so CHECK allows 'mixed', matching pos.db.sql.
 */
function ensureTabPaymentsAllowsMixedPaymentMethod(PDO $db) {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $sql = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='tab_payments'")->fetchColumn();
        if (!$sql || stripos($sql, 'payment_method') === false) {
            return;
        }
        if (stripos($sql, "'mixed'") !== false) {
            return;
        }
        if (!preg_match('/payment_method[\s\S]{0,240}IN\s*\(\s*\'cash\'\s*,\s*\'eft\'\s*\)/i', $sql)) {
            return;
        }

        $cols = $db->query('PRAGMA table_info(tab_payments)')->fetchAll(PDO::FETCH_ASSOC);
        if (!$cols) {
            return;
        }

        $indexes = $db->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='tab_payments' AND sql IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

        $parts = [];
        foreach ($cols as $c) {
            $name = '"' . str_replace('"', '""', $c['name']) . '"';
            $type = $c['type'] !== '' ? $c['type'] : 'TEXT';
            if (strcasecmp($c['name'], 'payment_method') === 0) {
                $parts[] = "$name $type NOT NULL CHECK($name IN ('cash', 'eft', 'mixed'))";
                continue;
            }
            $notnull = !empty($c['notnull']) ? ' NOT NULL' : '';
            $default = '';
            if ($c['dflt_value'] !== null && $c['dflt_value'] !== '') {
                $dv = $c['dflt_value'];
                if (is_numeric($dv) && stripos($type, 'INT') !== false) {
                    $default = ' DEFAULT ' . (int)$dv;
                } elseif (strtoupper((string)$dv) === 'CURRENT_TIMESTAMP') {
                    $default = ' DEFAULT CURRENT_TIMESTAMP';
                } else {
                    $default = ' DEFAULT ' . $db->quote((string)$dv);
                }
            }
            $pk = ((int)$c['pk'] === 1 && strcasecmp($c['name'], 'id') === 0) ? ' PRIMARY KEY AUTOINCREMENT' : '';
            $parts[] = trim("$name $type$notnull$default$pk");
        }

        $db->exec('PRAGMA foreign_keys = OFF');
        $db->beginTransaction();
        $db->exec('CREATE TABLE tab_payments__migrate (' . implode(', ', $parts) . ')');
        $names = implode(', ', array_map(static function ($c) {
            return '"' . str_replace('"', '""', $c['name']) . '"';
        }, $cols));
        $db->exec("INSERT INTO tab_payments__migrate ($names) SELECT $names FROM tab_payments");
        $db->exec('DROP TABLE tab_payments');
        $db->exec('ALTER TABLE tab_payments__migrate RENAME TO tab_payments');
        foreach ($indexes as $idxSql) {
            if ($idxSql) {
                $db->exec($idxSql);
            }
        }
        $db->commit();
        $db->exec('PRAGMA foreign_keys = ON');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $db->exec('PRAGMA foreign_keys = ON');
        error_log('ensureTabPaymentsAllowsMixedPaymentMethod: ' . $e->getMessage());
    }
}
