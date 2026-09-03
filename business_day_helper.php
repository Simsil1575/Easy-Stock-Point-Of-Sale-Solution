<?php
/**
 * Business-day SQL helpers.
 *
 * Overnight closing (e.g. 02:00): business day runs from closing time on day D
 * through closing time on D+1 (early-morning sales belong to previous day).
 *
 * Evening/day closing (e.g. 22:00): business day is the calendar date.
 *
 * Opening/closing times are read from business_info via bdLoadBusinessHoursContext().
 */
declare(strict_types=1);

function bdEscapeSqlTime(string $time): string
{
    if (!preg_match('/^\d{1,2}:\d{2}$/', $time)) {
        return '00:00';
    }

    [$hour, $minute] = array_map('intval', explode(':', $time, 2));

    return sprintf('%02d:%02d', max(0, min(23, $hour)), max(0, min(59, $minute)));
}

function bdIsOvernightClosing(string $closingTime): bool
{
    return (int) substr(bdEscapeSqlTime($closingTime), 0, 2) < 12;
}

function bdBusinessDateCaseSql(string $dateField, string $closingTime, bool $isOvernightClosing): string
{
    $closingTime = bdEscapeSqlTime($closingTime);

    if (!$isOvernightClosing) {
        return "date($dateField)";
    }

    return "CASE
        WHEN strftime('%H:%M', $dateField) BETWEEN '00:00' AND '$closingTime'
        THEN date(datetime($dateField, '-1 day'))
        ELSE date($dateField)
    END";
}

/**
 * Single-day filter for prepared statements. Always references :selectedDate and :nextDay
 * so callers can bind both safely with SQLite PDO.
 */
function bdSingleDayWhereSql(
    string $dateField,
    string $selectedDateParam,
    string $nextDayParam,
    string $closingTime,
    bool $isOvernightClosing
): string {
    $closingTime = bdEscapeSqlTime($closingTime);

    if (!$isOvernightClosing) {
        return "(DATE($dateField) = $selectedDateParam AND ($nextDayParam = $nextDayParam OR 1=1))";
    }

    return "(
        (DATE($dateField) = $selectedDateParam AND strftime('%H:%M', $dateField) >= '$closingTime') OR
        (DATE($dateField) = $nextDayParam AND strftime('%H:%M', $dateField) < '$closingTime')
    )";
}

function bdBindSingleDayParams(PDOStatement $stmt, string $selectedDate, string $nextDay): void
{
    $stmt->bindValue(':selectedDate', $selectedDate);
    $stmt->bindValue(':nextDay', $nextDay);
}

function bdBindSelectedDate(PDOStatement $stmt, string $selectedDate): void
{
    $stmt->bindValue(':selectedDate', $selectedDate);
}

function bdDateRangeWhereSql(
    string $dateField,
    string $startDate,
    string $endDate,
    string $closingTime,
    bool $isOvernightClosing
): string {
    if ($startDate === $endDate) {
        return bdSingleDayWhereSql($dateField, "'$startDate'", "'$startDate'", $closingTime, $isOvernightClosing);
    }

    if (!$isOvernightClosing) {
        return "DATE($dateField) BETWEEN '$startDate' AND '$endDate'";
    }

    $whereClauses = [];
    $currentDate = new DateTime($startDate);
    $endDateTime = new DateTime($endDate);

    while ($currentDate <= $endDateTime) {
        $dateStr = $currentDate->format('Y-m-d');
        $nextDayStr = (clone $currentDate)->modify('+1 day')->format('Y-m-d');
        $whereClauses[] = bdSingleDayWhereSql($dateField, "'$dateStr'", "'$nextDayStr'", $closingTime, true);
        $currentDate->modify('+1 day');
    }

    return '(' . implode(') OR (', $whereClauses) . ')';
}

function bdDefaultSelectedDate(string $closingTime, bool $isOvernightClosing): string
{
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $currentTime = date('H:i');
    $closingTime = bdEscapeSqlTime($closingTime);

    if (!$isOvernightClosing) {
        return $today;
    }

    if ($currentTime >= '00:00' && $currentTime < $closingTime) {
        return $yesterday;
    }

    return ($currentTime < $closingTime) ? $yesterday : $today;
}

function bdLoadBusinessHoursContext(?string $infoDbPath = null): array
{
    $openingTime = '08:00';
    $closingTime = '22:00';

    if ($infoDbPath === null) {
        $infoDbPath = __DIR__ . '/info.db';
    }

    try {
        $businessInfoDb = new PDO('sqlite:' . $infoDbPath);
        $row = $businessInfoDb->query('SELECT opening_time, closing_time FROM business_info LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!empty($row['opening_time'])) {
            $openingTime = bdEscapeSqlTime((string) $row['opening_time']);
        }
        if (!empty($row['closing_time'])) {
            $closingTime = bdEscapeSqlTime((string) $row['closing_time']);
        }
    } catch (PDOException $e) {
        // keep defaults
    }

    $isOvernightClosing = bdIsOvernightClosing($closingTime);

    return [
        'opening_time' => $openingTime,
        'closing_time' => $closingTime,
        'is_overnight_closing' => $isOvernightClosing,
        'is_after_midnight' => $isOvernightClosing,
    ];
}

function bdLoadClosingContext(?string $infoDbPath = null): array
{
    return bdLoadBusinessHoursContext($infoDbPath);
}

/**
 * Common SQL fragments for report pages using :selectedDate / :nextDay binds.
 *
 * @return array<string, string>
 */
function bdReportSqlFragments(string $closingTime, bool $isOvernightClosing): array
{
    return [
        'case_created' => bdBusinessDateCaseSql('created_at', $closingTime, $isOvernightClosing),
        'case_payment' => bdBusinessDateCaseSql('payment_date', $closingTime, $isOvernightClosing),
        'case_o_created' => bdBusinessDateCaseSql('o.created_at', $closingTime, $isOvernightClosing),
        'where_created' => bdSingleDayWhereSql('created_at', ':selectedDate', ':nextDay', $closingTime, $isOvernightClosing),
        'where_payment' => bdSingleDayWhereSql('payment_date', ':selectedDate', ':nextDay', $closingTime, $isOvernightClosing),
        'where_o_created' => bdSingleDayWhereSql('o.created_at', ':selectedDate', ':nextDay', $closingTime, $isOvernightClosing),
        'where_p_payment' => bdSingleDayWhereSql('p.payment_date', ':selectedDate', ':nextDay', $closingTime, $isOvernightClosing),
        'where_e_payment' => bdSingleDayWhereSql('e.payment_date', ':selectedDate', ':nextDay', $closingTime, $isOvernightClosing),
        'where_cs_created' => bdSingleDayWhereSql('cs.created_at', ':selectedDate', ':nextDay', $closingTime, $isOvernightClosing),
        'where_t_created' => bdSingleDayWhereSql('t.created_at', ':selectedDate', ':nextDay', $closingTime, $isOvernightClosing),
        'where_orders_created' => bdSingleDayWhereSql('orders.created_at', ':selectedDate', ':nextDay', $closingTime, $isOvernightClosing),
        'where_credit_sales_created' => bdSingleDayWhereSql('credit_sales.created_at', ':selectedDate', ':nextDay', $closingTime, $isOvernightClosing),
    ];
}
