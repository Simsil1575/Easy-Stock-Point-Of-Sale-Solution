<?php
declare(strict_types=1);

require_once __DIR__ . '/../business_day_helper.php';
$dateRangeBusinessHours = bdLoadBusinessHoursContext();
?>
<script>
window.BUSINESS_HOURS = <?= json_encode([
    'opening' => $dateRangeBusinessHours['opening_time'],
    'closing' => $dateRangeBusinessHours['closing_time'],
], JSON_UNESCAPED_UNICODE) ?>;

function drFormatDate(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function drNormalizeTime(value, fallback) {
    const raw = (value || fallback || '00:00').trim();
    if (/^\d{2}:\d{2}$/.test(raw)) {
        return raw;
    }
    if (/^\d{2}:\d{2}:\d{2}$/.test(raw)) {
        return raw.substring(0, 5);
    }
    return fallback || '00:00';
}

function drApplyBusinessTimes(startTimeId, endTimeId) {
    const startEl = document.getElementById(startTimeId);
    const endEl = document.getElementById(endTimeId);
    if (startEl) {
        startEl.value = drNormalizeTime(window.BUSINESS_HOURS.opening, '08:00');
    }
    if (endEl) {
        endEl.value = drNormalizeTime(window.BUSINESS_HOURS.closing, '22:00');
    }
}

function drSetDateRangeDefaults(startDateId, endDateId, startTimeId, endTimeId, startDate, endDate) {
    const startDateEl = document.getElementById(startDateId);
    const endDateEl = document.getElementById(endDateId);
    if (startDateEl) {
        startDateEl.value = startDate;
    }
    if (endDateEl) {
        endDateEl.value = endDate;
    }
    drApplyBusinessTimes(startTimeId, endTimeId);
}

function drSetQuickPeriod(period, startDateId, endDateId, startTimeId, endTimeId) {
    const today = new Date();
    let startDate;
    let endDate;

    switch (period) {
        case 'today':
            startDate = endDate = today;
            break;
        case 'yesterday':
            startDate = endDate = new Date(today);
            startDate.setDate(startDate.getDate() - 1);
            break;
        case 'week':
            startDate = new Date(today);
            startDate.setDate(today.getDate() - today.getDay());
            endDate = today;
            break;
        case 'month':
            startDate = new Date(today.getFullYear(), today.getMonth(), 1);
            endDate = today;
            break;
        case 'year':
            startDate = new Date(today.getFullYear(), 0, 1);
            endDate = today;
            break;
        default:
            startDate = endDate = today;
    }

    drSetDateRangeDefaults(
        startDateId,
        endDateId,
        startTimeId,
        endTimeId,
        drFormatDate(startDate),
        drFormatDate(endDate)
    );

    document.querySelectorAll('.period-btn').forEach(function (btn) {
        btn.classList.remove('active');
    });
    if (typeof event !== 'undefined' && event && event.target) {
        event.target.classList.add('active');
    }
}

function drCombineDateTime(dateValue, timeValue, isEnd) {
    const date = (dateValue || '').trim();
    const time = drNormalizeTime(timeValue, isEnd ? window.BUSINESS_HOURS.closing : window.BUSINESS_HOURS.opening);
    if (!date) {
        return '';
    }
    return date + 'T' + time;
}

function drReadCombinedRange(startDateId, endDateId, startTimeId, endTimeId) {
    return {
        start: drCombineDateTime(
            document.getElementById(startDateId)?.value,
            document.getElementById(startTimeId)?.value,
            false
        ),
        end: drCombineDateTime(
            document.getElementById(endDateId)?.value,
            document.getElementById(endTimeId)?.value,
            true
        )
    };
}
</script>
