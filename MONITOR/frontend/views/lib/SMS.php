<?php
/**
 * SMS — Semaphore SMS API wrapper
 * Endpoint : https://api.semaphore.co/api/v4/messages
 * Auth     : apikey in POST form-data
 * Body     : apikey, number, message, sendername (optional)
 */
class SMS {

    // ── Core send ─────────────────────────────────────────────

    public static function send(string $to, string $message): array {
        return self::dispatch($to, $message);
    }

    // Alias used by 2FA / OTP callers
    public static function sendRaw(string $to, string $message): array {
        return self::dispatch($to, $message);
    }

    private static function dispatch(string $to, string $message): array {
        if (!SMS_ENABLED) {
            return ['success' => false, 'error' => 'SMS is disabled in configuration'];
        }

        if (empty(SMS_API_KEY) || empty(SMS_API_URL)) {
            return ['success' => false, 'error' => 'SMS API not configured'];
        }

        // Normalise number — strip non-numeric except leading +
        $to = preg_replace('/[^\d+]/', '', $to);

        // Semaphore accepts PH format: 09xxxxxxxxx or +639xxxxxxxxx
        if (str_starts_with($to, '+63') && strlen($to) === 13) {
            $to = '0' . substr($to, 3);
        }
        if (str_starts_with($to, '63') && strlen($to) === 12) {
            $to = '0' . substr($to, 2);
        }
        if (preg_match('/^9\d{9}$/', $to)) {
            $to = '0' . $to;
        }

        $fields = [
            'apikey'  => SMS_API_KEY,
            'number'  => $to,
            'message' => $message,
        ];

        $senderId = defined('SMS_SENDER_ID') ? SMS_SENDER_ID : '';
        if (!empty($senderId)) {
            $fields['sendername'] = $senderId;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => SMS_API_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'cURL error: ' . $curlError];
        }

        $data = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            // Semaphore returns an array; first item has 'status'
            $first  = is_array($data) ? ($data[0] ?? $data) : [];
            $status = $first['status'] ?? '';
            if ($status === 'Failed') {
                return ['success' => false, 'error' => $first['message'] ?? 'Send failed', 'data' => $data];
            }
            return ['success' => true, 'data' => $data, 'response' => $response];
        }

        $errMsg = (is_array($data) && isset($data['message'])) ? $data['message'] : ('HTTP ' . $httpCode);
        return ['success' => false, 'error' => $errMsg, 'data' => $data];
    }

    // ── Message templates ─────────────────────────────────────

    public static function sendExpiryReminder(array $subscriber): array {
        $daysLeft = subscriptionDaysLeft($subscriber['subscription_end']);
        $message  = getSetting('company_name', APP_NAME) . ": Dear {$subscriber['firstname']}, your subscription "
                  . "({$subscriber['account_number']}) expires in {$daysLeft} day(s) on "
                  . formatDate($subscriber['subscription_end'], 'M d, Y')
                  . ". Please renew to avoid interruption.";
        return self::dispatch($subscriber['contact_number'] ?? '', $message);
    }

    public static function sendPaymentConfirmation(array $subscriber, array $payment): array {
        $amount  = number_format($payment['amount'], 2);
        $sym     = getSetting('currency_symbol', '₱');
        $message = getSetting('company_name', APP_NAME) . ": Payment of {$sym}{$amount} received "
                  . "for {$subscriber['account_number']}. "
                  . "Valid until " . formatDate($payment['period_end'], 'M d, Y') . ". Thank you!";
        return self::dispatch($subscriber['contact_number'] ?? '', $message);
    }

    public static function sendAccountCreated(array $subscriber): array {
        $message = getSetting('company_name', APP_NAME) . ": Your account {$subscriber['account_number']} has been created and is now active. Welcome!";
        return self::dispatch($subscriber['contact_number'] ?? '', $message);
    }

    public static function sendSuspension(array $subscriber, string $reason = ''): array {
        $message = getSetting('company_name', APP_NAME) . ": Account {$subscriber['account_number']} has been suspended. "
                  . ($reason ? "Reason: {$reason}. " : '')
                  . "Contact us to reactivate.";
        return self::dispatch($subscriber['contact_number'] ?? '', $message);
    }
}
