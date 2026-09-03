<?php

declare(strict_types=1);

/**
 * Send quotations and invoices to clients using the existing PHPMailer SMTP setup.
 */

require_once __DIR__ . '/resetpass/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/resetpass/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/resetpass/PHPMailer/src/Exception.php';
require_once __DIR__ . '/invoicing_pdf_builder.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Configure PHPMailer with the same SMTP settings used by receiving/stock emails.
 */
function invCreateMailer(): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'sourcecodedev6@gmail.com';
    $mail->Password = 'irfvlutirghpfbkl';
    $mail->SMTPSecure = 'tls';
    $mail->Port = 587;
    $mail->Timeout = 30;
    $mail->CharSet = 'UTF-8';
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ];
    return $mail;
}

/**
 * Email address of the currently logged-in user (for CC copies).
 */
function invCurrentUserEmail(): string
{
    $userId = $_SESSION['user_id'] ?? null;
    if ($userId === null || $userId === '') {
        return '';
    }
    try {
        $userDb = new PDO('sqlite:' . __DIR__ . '/user.db');
        $userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $userDb->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $email = strtolower(trim((string) ($stmt->fetchColumn() ?: '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
    } catch (Throwable $e) {
        // Sending to the client should still proceed if the sender has no email.
    }
    return '';
}

function invNormalizeEmailList(string $raw): array
{
    $parts = preg_split('/[,;]+/', $raw) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $email = strtolower(trim($part));
        if ($email === '') {
            continue;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid email address: ' . $email);
        }
        $out[$email] = $email;
    }
    return array_values($out);
}

/**
 * Send a quotation or invoice PDF to a client.
 *
 * @param array{to?:string,message?:string,cc?:string} $opts
 * @return array{recipient:string,number:string,type:string}
 */
function invSendDocumentEmail(PDO $db, string $type, int $id, array $opts = []): array
{
    $type = $type === 'quotation' ? 'quotation' : 'invoice';
    $settings = invGetDocumentSettings();
    $pdfPack = invDocumentPdfContent($db, $type, $id, $settings);
    $meta = $pdfPack['meta'];
    $doc = $meta['doc'];
    $customer = $meta['customer'] ?? [];
    $company = (string) ($settings['company_name'] ?? 'POS System');
    $currency = (string) ($meta['currency'] ?? 'N$');
    $number = (string) $meta['number'];
    $grand = (float) $meta['grand_total'];
    $isQuote = $type === 'quotation';
    $label = $isQuote ? 'Quotation' : 'Invoice';

    $toRaw = trim((string) ($opts['to'] ?? ''));
    if ($toRaw === '') {
        $toRaw = trim((string) ($customer['email'] ?? ''));
    }
    $recipients = invNormalizeEmailList($toRaw);
    if ($recipients === []) {
        throw new RuntimeException('A recipient email address is required.');
    }

    $ccList = [];
    if (!empty($opts['cc'])) {
        $ccList = invNormalizeEmailList((string) $opts['cc']);
    }
    $senderEmail = invCurrentUserEmail();
    if ($senderEmail !== '' && !in_array($senderEmail, $recipients, true) && !in_array($senderEmail, $ccList, true)) {
        $ccList[] = $senderEmail;
    }

    $customMessage = trim((string) ($opts['message'] ?? ''));
    $customerName = trim((string) ($customer['name'] ?? 'Valued Customer'));
    $primaryDate = (string) ($meta['primary_date'] ?? '');
    $secondaryDate = (string) ($meta['secondary_date'] ?? '');
    $secondaryLabel = (string) ($meta['secondary_date_label'] ?? ($isQuote ? 'Valid Until' : 'Due Date'));
    $balanceDue = $isQuote ? $grand : (float) ($doc['balance_due'] ?? $grand);
    $money = static fn($v) => $currency . ' ' . number_format((float) $v, 2);
    $h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $greeting = $customerName !== '' ? ('Dear ' . $h($customerName) . ',') : 'Dear Valued Customer,';
    $intro = $isQuote
        ? 'Please find attached quotation <strong>' . $h($number) . '</strong> for your review.'
        : 'Please find attached invoice <strong>' . $h($number) . '</strong> for your records.';
    $closing = $h(invGetDocumentClosingMessage($settings, $type));

    $customHtml = '';
    if ($customMessage !== '') {
        $customHtml = '<div style="margin:18px 0;padding:12px 14px;background:#f8fafc;border-left:3px solid #0d9488;color:#334155;font-size:14px;line-height:1.55;">'
            . nl2br($h($customMessage))
            . '</div>';
    }

    $rowsHtml = '';
    $addRow = static function (string $labelText, string $value, bool $alt = false, bool $emphasis = false) use (&$rowsHtml, $h): void {
        $bg = $alt ? '#f8fafc' : '#ffffff';
        $weight = $emphasis ? 'font-weight:700;color:#0f766e;' : '';
        $rowsHtml .= '<tr>'
            . '<td style="padding:10px 12px;background:' . $bg . ';color:#64748b;font-size:13px;">' . $h($labelText) . '</td>'
            . '<td style="padding:10px 12px;background:' . $bg . ';text-align:right;font-size:13px;color:#0f172a;' . $weight . '">' . $value . '</td>'
            . '</tr>';
    };
    $addRow($label . ' No', $h($number), true);
    $addRow($isQuote ? 'Quotation Date' : 'Invoice Date', $h($primaryDate !== '' ? $primaryDate : '-'));
    $addRow($secondaryLabel, $h($secondaryDate !== '' ? $secondaryDate : '-'), true);
    $addRow($isQuote ? 'Total' : 'Grand Total', $h($money($grand)), false, true);
    if (!$isQuote) {
        $addRow('Amount Paid', $h($money($doc['paid_amount'] ?? 0)), true);
        $addRow('Balance Due', $h($money($balanceDue)), false, true);
    }

    $phone = trim((string) ($settings['telephone'] ?? ''));
    $companyEmail = trim((string) ($settings['email'] ?? ''));
    $contactBits = array_filter([$phone !== '' ? 'Tel: ' . $h($phone) : '', $companyEmail !== '' ? 'Email: ' . $h($companyEmail) : '']);

    $html = '
        <div style="font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:0 auto;color:#0f172a;background:#ffffff;">
            <div style="background:#0d9488;color:#ffffff;padding:22px 24px;">
                <div style="font-size:13px;letter-spacing:1px;text-transform:uppercase;opacity:.9;">' . $h($company) . '</div>
                <h1 style="margin:6px 0 0;font-size:24px;font-weight:700;">' . $h($label) . ' ' . $h($number) . '</h1>
            </div>
            <div style="padding:24px;">
                <p style="margin:0 0 12px;font-size:15px;">' . $greeting . '</p>
                <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#334155;">' . $intro . '</p>
                ' . $customHtml . '
                <table style="width:100%;border-collapse:collapse;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
                    ' . $rowsHtml . '
                </table>
                <p style="margin:18px 0 0;font-size:14px;color:#334155;">The full document is attached as a PDF.</p>
                <p style="margin:18px 0 0;font-size:14px;color:#334155;">' . $closing . '</p>
                <p style="margin:22px 0 0;font-size:14px;color:#334155;">
                    Kind regards,<br>
                    <strong>' . $h($company) . '</strong>
                    ' . ($contactBits ? '<br><span style="color:#64748b;font-size:12px;">' . implode(' · ', $contactBits) . '</span>' : '') . '
                </p>
            </div>
            <div style="padding:14px 24px;background:#f8fafc;color:#94a3b8;font-size:11px;border-top:1px solid #e2e8f0;">
                This is an automated message from ' . $h($company) . '. Generated on ' . $h(date('Y-m-d H:i:s')) . '.
            </div>
        </div>
    ';

    $alt = $label . ' ' . $number . "\n\n"
        . ($customerName !== '' ? "Dear {$customerName},\n\n" : '')
        . strip_tags($intro) . "\n"
        . ($customMessage !== '' ? "\n{$customMessage}\n" : '')
        . "\nTotal: " . $money($grand)
        . (!$isQuote ? "\nBalance Due: " . $money($balanceDue) : '')
        . "\n\nPlease see the attached PDF.\n\n"
        . strip_tags($closing) . "\n\nKind regards,\n" . $company;

    $mail = invCreateMailer();
    try {
        $mail->setFrom('sourcecodedev6@gmail.com', $company !== '' ? $company : 'POS System');
        if ($companyEmail !== '' && filter_var($companyEmail, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($companyEmail, $company);
        }
        foreach ($recipients as $email) {
            $mail->addAddress($email, $customerName);
        }
        foreach ($ccList as $email) {
            $mail->addCC($email);
        }

        $mail->isHTML(true);
        $mail->Subject = $label . ' ' . $number . ' — ' . $company;
        $mail->Body = $html;
        $mail->AltBody = $alt;
        $mail->addStringAttachment($pdfPack['content'], $pdfPack['filename'], 'base64', 'application/pdf');
        $mail->send();
    } catch (PHPMailerException $e) {
        $info = trim((string) $mail->ErrorInfo);
        throw new RuntimeException('Email sending failed' . ($info !== '' ? ': ' . $info : '.'));
    }

    if ($isQuote && in_array((string) ($doc['status'] ?? ''), ['Draft', 'Sent'], true)) {
        $db->prepare("UPDATE quotations SET status='Sent', updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('Draft','Sent')")
            ->execute([$id]);
    }

    return [
        'recipient' => implode(', ', $recipients),
        'number' => $number,
        'type' => $type,
    ];
}
