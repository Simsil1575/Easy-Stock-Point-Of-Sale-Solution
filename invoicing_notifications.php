<?php

declare(strict_types=1);

require_once __DIR__ . '/invoicing_lib.php';

/**
 * Aggregate counts for module notifications.
 *
 * @return array{quotes_expiring:int, overdue:int, due_today:int, outstanding:float, outstanding_count:int}
 */
function invNotificationCounts(PDO $db): array
{
    $today = date('Y-m-d');
    $soon = date('Y-m-d', strtotime('+7 days'));

    $qExp = $db->prepare("
        SELECT COUNT(*) FROM quotations
        WHERE expiry_date IS NOT NULL AND expiry_date BETWEEN ? AND ?
          AND status IN ('Draft','Sent')
    ");
    $qExp->execute([$today, $soon]);

    $overdue = $db->prepare("
        SELECT COUNT(*) FROM invoices
        WHERE due_date IS NOT NULL AND due_date < ? AND balance_due > 0.001
          AND status NOT IN ('Cancelled','Paid')
    ");
    $overdue->execute([$today]);

    $dueToday = $db->prepare("
        SELECT COUNT(*) FROM invoices
        WHERE due_date = ? AND balance_due > 0.001 AND status NOT IN ('Cancelled','Paid')
    ");
    $dueToday->execute([$today]);

    $out = $db->query("
        SELECT COALESCE(SUM(balance_due),0) AS bal, COUNT(*) AS cnt
        FROM invoices
        WHERE balance_due > 0.001 AND status NOT IN ('Cancelled','Draft')
    ")->fetch(PDO::FETCH_ASSOC) ?: ['bal' => 0, 'cnt' => 0];

    return [
        'quotes_expiring' => (int) $qExp->fetchColumn(),
        'overdue' => (int) $overdue->fetchColumn(),
        'due_today' => (int) $dueToday->fetchColumn(),
        'outstanding' => (float) $out['bal'],
        'outstanding_count' => (int) $out['cnt'],
    ];
}

/**
 * Notification cards for a list view (quotation or invoice scope).
 *
 * @return array<int,array{value:string,label:string,icon:string,bg:string}>
 */
function invNotificationCards(PDO $db, array $settings, string $scope = 'invoice'): array
{
    $c = invNotificationCounts($db);
    $currency = (string) ($settings['currency'] ?? 'N$');
    $cards = [];

    if ($scope === 'quotation') {
        $cards[] = ['value' => (string) $c['quotes_expiring'], 'label' => 'Quotations expiring soon', 'icon' => 'fa-hourglass-half', 'bg' => 'bg-orange-100 text-orange-600'];
    }
    $cards[] = ['value' => (string) $c['overdue'], 'label' => 'Overdue invoices', 'icon' => 'fa-triangle-exclamation', 'bg' => 'bg-rose-100 text-rose-600'];
    $cards[] = ['value' => (string) $c['due_today'], 'label' => 'Invoices due today', 'icon' => 'fa-calendar-day', 'bg' => 'bg-sky-100 text-sky-600'];
    $cards[] = ['value' => $currency . ' ' . number_format($c['outstanding'], 2), 'label' => 'Outstanding balance', 'icon' => 'fa-coins', 'bg' => 'bg-amber-100 text-amber-600'];

    return $cards;
}
