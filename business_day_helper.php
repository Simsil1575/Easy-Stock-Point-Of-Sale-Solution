<?php
/**
 * Business-day SQL helpers.
 *
 * Overnight closing (e.g. 02:00): business day runs from closing time on day D
 * through closing time on D+1 (early-morning sales belong to previous day).
 *
 * Evening/day closing (e.g. 22:00): business day is the calendar date.
 */
declare(strict_types=1);

function bdIsOvernightClosing(string $closingTime): bool
{
    return (int) substr($closingTime, 0, 2) < 12;
}

function bdBusinessDateCaseSql(string $dateField, string $closingTime, bool $isOvernightClosing): string
{
    if (!$isOvernightClosing) {
        return "date($dateField)";
    }

    return "CASE
        WHEN strftime('%H:%M', $dateField) BETWEEN '00:00' AND '$closingTime'
        THEN date(datetime($dateField, '-1 day'))
        ELSE date($dateField)
    END";
}

function bdSingleDayWhereSql(
    string $dateField,
    string $selectedDateParam,
    string $nextDayParam,
    string $closingTime,
    bool $isOvernightClosing
): string {
    if (!$isOvernightClosing) {
        return "DATE($dateField) = $selectedDateParam";
    }

    return "(
        (DATE($dateField) = $selectedDateParam AND strftime('%H:%M', $dateField) >= '$closingTime') OR
        (DATE($dateField) = $nextDayParam AND strftime('%H:%M', $dateField) < '$closingTime')
    )";
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

    if (!$isOvernightClosing) {
        return $today;
    }

    if ($currentTime >= '00:00' && $currentTime < $closingTime) {
        return $yesterday;
    }

    return ($currentTime < $closingTime) ? $yesterday : $today;
}

function bdLoadBusinessHoursContext(): array
{
    $openingTime = '08:00';
    $closingTime = '22:00';
    try {
        $businessInfoDb = new PDO('sqlite:' . __DIR__ . '/info.db');
        $row = $businessInfoDb->query('SELECT opening_time, closing_time FROM business_info LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!empty($row['opening_time'])) {
            $openingTime = (string) $row['opening_time'];
        }
        if (!empty($row['closing_time'])) {
            $closingTime = (string) $row['closing_time'];
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

function bdLoadClosingContext(): array
{
    $ctx = bdLoadBusinessHoursContext();

    return [
        'opening_time' => $ctx['opening_time'],
        'closing_time' => $ctx['closing_time'],
        'is_overnight_closing' => $ctx['is_overnight_closing'],
        'is_after_midnight' => $ctx['is_after_midnight'],
    ];
}
