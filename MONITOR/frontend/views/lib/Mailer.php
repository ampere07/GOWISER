<?php
/**
 * Mailer — thin wrapper around PHPMailer.
 * Requires: composer require phpmailer/phpmailer
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

class Mailer {
    private static function make(): PHPMailer {
        if (!class_exists(PHPMailer::class)) {
            throw new RuntimeException('PHPMailer not found. Run: composer install');
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $_ENV['MAIL_HOST']       ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['MAIL_USERNAME']   ?? '';
        $mail->Password   = $_ENV['MAIL_PASSWORD']   ?? '';
        $mail->Port       = (int)($_ENV['MAIL_PORT'] ?? 587);
        $mail->CharSet    = 'UTF-8';

        $enc  = strtolower($_ENV['MAIL_ENCRYPTION'] ?? 'tls');
        $port = (int)($_ENV['MAIL_PORT'] ?? 587);
        if ($enc === 'ssl' || $port === 465) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom(
            $_ENV['MAIL_FROM_ADDRESS'] ?? $_ENV['MAIL_USERNAME'] ?? '',
            $_ENV['MAIL_FROM_NAME']    ?? getSetting('company_name', APP_NAME)
        );
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];

        return $mail;
    }

    public static function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $altBody = ''
    ): array {
        if (!defined('MAIL_ENABLED') || !MAIL_ENABLED) {
            return ['success' => false, 'error' => 'Email is disabled in configuration'];
        }

        try {
            $mail = self::make();
            $mail->addAddress($toEmail, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = self::wrapHtml($subject, $htmlBody);
            $mail->AltBody = $altBody ?: strip_tags($htmlBody);
            $mail->send();
            return ['success' => true];
        } catch (MailException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        } catch (RuntimeException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Preset email templates ────────────────────────────────

    public static function sendSubscriptionExpiry(array $subscriber, int $daysLeft): array {
        $name    = $subscriber['firstname'] . ' ' . $subscriber['lastname'];
        $company = getSetting('company_name', APP_NAME);
        $subject = $company . ' — Subscription Expiry Notice';
        $body    = "
            <p>Dear <strong>{$name}</strong>,</p>
            <p>This is a reminder that your internet subscription will expire in
               <strong style='color:#dc3545;'>{$daysLeft} day(s)</strong>.</p>
            <table style='border-collapse:collapse;width:100%;font-size:14px;margin:0 0 20px;'>
                <tr style='background:#f8f9fa;'>
                    <td style='padding:10px 12px;border:1px solid #e9ecef;color:#6c757d;width:40%;'>Account Number</td>
                    <td style='padding:10px 12px;border:1px solid #e9ecef;font-weight:600;'>{$subscriber['account_number']}</td>
                </tr>
                <tr>
                    <td style='padding:10px 12px;border:1px solid #e9ecef;color:#6c757d;'>Expiry Date</td>
                    <td style='padding:10px 12px;border:1px solid #e9ecef;font-weight:600;color:#dc3545;'>" . formatDate($subscriber['subscription_end'], 'F d, Y') . "</td>
                </tr>
            </table>
            <p>Please contact our office to renew your subscription and avoid service interruption.</p>
            " . self::signOff($company) . "
        ";
        return self::send($subscriber['email'] ?? '', $name, $subject, $body);
    }

    public static function sendPaymentConfirmation(array $subscriber, array $payment): array {
        $name    = $subscriber['firstname'] . ' ' . $subscriber['lastname'];
        $company = getSetting('company_name', APP_NAME);
        $sym     = getSetting('currency_symbol', '₱');
        $subject = $company . ' — Payment Confirmation #' . $payment['payment_id'];
        $body    = "
            <p>Dear <strong>{$name}</strong>,</p>
            <p>We have received your payment. Here are the details of your transaction:</p>
            <table style='border-collapse:collapse;width:100%;font-size:14px;margin:0 0 20px;'>
                <tr style='background:#f8f9fa;'>
                    <td style='padding:10px 12px;border:1px solid #e9ecef;color:#6c757d;width:40%;'>Receipt #</td>
                    <td style='padding:10px 12px;border:1px solid #e9ecef;font-weight:600;'>PAY-{$payment['payment_id']}</td>
                </tr>
                <tr>
                    <td style='padding:10px 12px;border:1px solid #e9ecef;color:#6c757d;'>Amount</td>
                    <td style='padding:10px 12px;border:1px solid #e9ecef;font-weight:600;color:#198754;'>{$sym}" . number_format($payment['amount'], 2) . "</td>
                </tr>
                <tr style='background:#f8f9fa;'>
                    <td style='padding:10px 12px;border:1px solid #e9ecef;color:#6c757d;'>Payment Method</td>
                    <td style='padding:10px 12px;border:1px solid #e9ecef;'>" . ucfirst($payment['method']) . "</td>
                </tr>
                <tr>
                    <td style='padding:10px 12px;border:1px solid #e9ecef;color:#6c757d;'>Subscription Period</td>
                    <td style='padding:10px 12px;border:1px solid #e9ecef;'>" . formatDate($payment['period_start']) . " &mdash; " . formatDate($payment['period_end']) . "</td>
                </tr>
            </table>
            <p>Thank you for your payment! Your account is now up to date.</p>
            " . self::signOff($company) . "
        ";
        return self::send($subscriber['email'] ?? '', $name, $subject, $body);
    }

    public static function sendWelcome(array $subscriber, string $plainPassword): array {
        $name    = $subscriber['firstname'] . ' ' . $subscriber['lastname'];
        $company = getSetting('company_name', APP_NAME);
        $subject = 'Welcome to ' . $company . ' — Your Account Details';
        $connInfo = '';
        if (!empty($subscriber['ppp_username'])) {
            $connInfo = "
            <table style='border-collapse:collapse;width:100%;font-size:14px;margin:0 0 20px;'>
                <tr style='background:#f8f9fa;'>
                    <td style='padding:10px 12px;border:1px solid #e9ecef;color:#6c757d;width:40%;'>PPP Username</td>
                    <td style='padding:10px 12px;border:1px solid #e9ecef;font-family:monospace;font-weight:600;'>{$subscriber['ppp_username']}</td>
                </tr>
                <tr>
                    <td style='padding:10px 12px;border:1px solid #e9ecef;color:#6c757d;'>PPP Password</td>
                    <td style='padding:10px 12px;border:1px solid #e9ecef;font-family:monospace;font-weight:600;'>{$plainPassword}</td>
                </tr>
            </table>
            <p style='color:#6c757d;font-size:13px;'>Keep your credentials safe and do not share them with anyone.</p>";
        }
        $body = "
            <p>Dear <strong>{$name}</strong>,</p>
            <p>Welcome to <strong>{$company}</strong>! Your internet subscription account has been created and your service is now active.</p>
            <table style='border-collapse:collapse;width:100%;font-size:14px;margin:0 0 20px;'>
                <tr style='background:#f8f9fa;'>
                    <td style='padding:10px 12px;border:1px solid #e9ecef;color:#6c757d;width:40%;'>Account Number</td>
                    <td style='padding:10px 12px;border:1px solid #e9ecef;font-weight:600;'>{$subscriber['account_number']}</td>
                </tr>
            </table>
            {$connInfo}
            " . self::signOff($company) . "
        ";
        return self::send($subscriber['email'] ?? '', $name, $subject, $body);
    }

    public static function sendPasswordReset(array $user, string $resetUrl): array {
        $name    = ($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '');
        $company = getSetting('company_name', APP_NAME);
        $subject = $company . ' — Password Reset Request';
        $body    = "
            <p>Dear <strong>" . htmlspecialchars(trim($name)) . "</strong>,</p>
            <p>We received a request to reset your password. Click the button below to set a new one:</p>
            <p style='text-align:center;margin:28px 0;'>
                <a href='{$resetUrl}'
                   style='background:#0d6efd;color:#fff;padding:13px 32px;border-radius:6px;text-decoration:none;font-weight:600;font-size:15px;display:inline-block;letter-spacing:.01em;'>
                    Reset Password
                </a>
            </p>
            <p style='color:#6c757d;font-size:13px;'>Or copy this link into your browser:<br>
                <a href='{$resetUrl}' style='color:#0d6efd;word-break:break-all;'>{$resetUrl}</a>
            </p>
            <p style='color:#6c757d;font-size:13px;'>This link will expire in <strong>30 minutes</strong>.
               If you did not request a password reset, you can safely ignore this email.</p>
            " . self::signOff($company) . "
        ";
        return self::send($user['email'] ?? '', trim($name), $subject, $body);
    }

    // ── Shared sign-off block ─────────────────────────────────
    private static function signOff(string $company): string {
        $contact = getSetting('company_contact', '');
        $email   = getSetting('company_email', '');
        $contactLine = '';
        if ($contact || $email) {
            $parts = array_filter([$contact, $email]);
            $contactLine = "<p style='color:#6c757d;font-size:13px;margin-top:4px;'>Contact us: " . implode(' &middot; ', array_map('htmlspecialchars', $parts)) . "</p>";
        }
        return "
            <p style='margin-top:24px;'>Best regards,<br><strong>{$company}</strong></p>
            {$contactLine}
        ";
    }

    // ── HTML wrapper ──────────────────────────────────────────
    private static function wrapHtml(string $title, string $body): string {
        $appName = getSetting('company_name', APP_NAME);
        $year    = date('Y');
        $contact = getSetting('company_contact', '');
        $email   = getSetting('company_email', '');
        $address = getSetting('company_address', '');
        $footerExtra = '';
        if ($contact || $email) {
            $parts = array_filter([$contact, $email]);
            $footerExtra .= '<br>' . implode(' &middot; ', array_map('htmlspecialchars', $parts));
        }
        if ($address) {
            $footerExtra .= '<br>' . htmlspecialchars($address);
        }
        return "<!DOCTYPE html>
<html lang='en'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'>
<title>" . htmlspecialchars($title) . "</title>
<style>
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;background:#f0f2f5;margin:0;padding:0;}
  .ew{max-width:600px;margin:32px auto;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.10);}
  .eh{background:#0d6efd;padding:28px 36px;}
  .eh h1{color:#ffffff;margin:0;font-size:22px;font-weight:700;letter-spacing:-.01em;}
  .eh p{color:rgba(255,255,255,.8);margin:4px 0 0;font-size:13px;}
  .eb{padding:36px;}
  .eb p{color:#343a40;line-height:1.75;margin:0 0 16px;font-size:15px;}
  .eb table{margin:0 0 20px;}
  .ef{background:#f8f9fa;border-top:1px solid #e9ecef;padding:20px 36px;text-align:center;color:#9ca3af;font-size:12px;line-height:1.6;}
</style>
</head>
<body>
<div class='ew'>
  <div class='eh'>
    <h1>{$appName}</h1>
    <p>" . htmlspecialchars($title) . "</p>
  </div>
  <div class='eb'>{$body}</div>
  <div class='ef'>
    &copy; {$year} {$appName}. All rights reserved.{$footerExtra}
  </div>
</div>
</body></html>";
    }
}
