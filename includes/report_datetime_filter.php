<?php
declare(strict_types=1);

function parseReportDateTime($input, $isEnd = false)
{
    $input = trim(str_replace('T', ' ', urldecode((string) $input)));
    if ($input === '') {
        return date('Y-m-d') . ($isEnd ? ' 23:59:59' : ' 00:00:00');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
        return $input . ($isEnd ? ' 23:59:59' : ' 00:00:00');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $input)) {
        return $input . ($isEnd ? ':59' : ':00');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $input)) {
        return $input;
    }

    return date('Y-m-d') . ($isEnd ? ' 23:59:59' : ' 00:00:00');
}

function getDateTimeWhereClause($dateField, $startDateTime, $endDateTime)
{
    if (empty($startDateTime) || empty($endDateTime)) {
        return '1=1';
    }

    return "$dateField >= '$startDateTime' AND $dateField <= '$endDateTime'";
}

function formatReportDateRange($startDateTime, $endDateTime)
{
    $startTs = strtotime($startDateTime);
    $endTs = strtotime($endDateTime);
    $startDay = date('Y-m-d', $startTs);
    $endDay = date('Y-m-d', $endTs);

    if ($startDateTime === $endDateTime) {
        return date('F j, Y g:i A', $startTs);
    }
    if ($startDay === $endDay) {
        return date('F j, Y', $startTs) . ' ' . date('g:i A', $startTs) . ' - ' . date('g:i A', $endTs);
    }

    return date('M j, Y g:i A', $startTs) . ' - ' . date('M j, Y g:i A', $endTs);
}
