<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireCanPrintSensitiveRecords();

$id     = (int)($_GET['id']     ?? 0);
$format = $_GET['format'] ?? 'pos80'; // pos80 | a4
if ($format === 'pos58') $format = 'pos80'; // 58mm removed
$pdf    = !empty($_GET['pdf']);

$selectedRouterId = selectedRouterId();
$scopeSql = '';
$scopeParams = [];
if (currentRole() !== ROLE_SUPERADMIN || $selectedRouterId) {
    if (!$selectedRouterId) {
        flashMessage('danger', 'Router not selected.');
        redirect(BASE_URL . '/modules/payments/');
    }
    $scopeSql = ' AND s.router_id = ?';
    $scopeParams[] = $selectedRouterId;
}

$stmt = db()->prepare("
    SELECT py.*, s.firstname, s.lastname, s.account_number, s.address, s.contact_number,
           s.barangay, s.municipality, s.province,
           s.connection_type, s.ppp_username,
           r.address AS router_address,
           r.region AS router_region,
           r.province AS router_province,
           r.municipality AS router_municipality,
           p.title AS plan_title, p.speed_mbps,
           u.firstname AS cashier_first, u.lastname AS cashier_last,
           pt.name AS payment_type_name
    FROM payments py
    JOIN subscribers s  ON s.subscriber_id = py.subscriber_id
    LEFT JOIN routers r ON r.router_id     = s.router_id
    LEFT JOIN plans p   ON p.plan_id       = s.plan_id
    LEFT JOIN users u   ON u.user_id       = py.user_id
    LEFT JOIN payment_types pt ON pt.type_id = py.payment_type_id
    WHERE py.payment_id = ?{$scopeSql}
");
$stmt->execute(array_merge([$id], $scopeParams));
$payment = $stmt->fetch();
if (!$payment) { flashMessage('danger', 'Receipt not found.'); redirect(BASE_URL . '/modules/payments/'); }

$orNum        = $payment['or_number'] ?? str_pad($id, 6, '0', STR_PAD_LEFT);
$companyName  = getSetting('company_name', APP_NAME);
$routerAddrParts = array_filter(array_map(
    'trim',
    [
        (string)($payment['router_address'] ?? ''),
        (string)($payment['router_municipality'] ?? ''),
        (string)($payment['router_province'] ?? ''),
        (string)($payment['router_region'] ?? ''),
    ]
));
$companyAddr  = $routerAddrParts ? implode(', ', $routerAddrParts) : getSetting('company_address', '');
$companyTIN   = getSetting('company_tin', '');
$companySEC   = getSetting('company_sec', '');
$companyBP    = getSetting('company_bp', '');
$companyTel   = getSetting('company_contact', '');
$companyEmail = getSetting('company_email', '');
$currency     = getSetting('currency_symbol', '₱');

// Tax — use stored values from the payment record (not re-derived from current settings)
$taxName         = getSetting('tax_name', 'VAT');
$taxRate         = (float)($payment['tax_rate']   ?? 0);
$taxAmount       = (float)($payment['tax_amount'] ?? 0);
$taxType         = $payment['tax_type'] ?? 'included';

if ($taxRate > 0 && $taxAmount > 0) {
    if ($taxType === 'excluded') {
        $baseAmount  = (float)$payment['amount'];
        $totalAmount = $baseAmount + $taxAmount;
    } else { // included
        $totalAmount = (float)$payment['amount'];
        $baseAmount  = $totalAmount - $taxAmount;
    }
} else {
    $taxRate     = 0;
    $taxAmount   = 0;
    $baseAmount  = (float)$payment['amount'];
    $totalAmount = $baseAmount;
}
$taxTypeLabel = $taxType === 'included' ? ' (incl.)' : '';

$logoData = getSetting('company_logo', '');
$logoHtml = $logoData
    ? '<img src="' . e($logoData) . '" alt="Logo" style="max-width:80px;max-height:60px;object-fit:contain;">'
    : '';
$amount   = number_format($baseAmount, 2);

// ── PDF export ────────────────────────────────────────────────
if ($pdf) {
    require_once BASE_PATH . '/vendor/autoload.php';
    $fontCacheDir = BASE_PATH . '/storage/dompdf';
    if (!is_dir($fontCacheDir)) mkdir($fontCacheDir, 0755, true);
    $options = new Dompdf\Options();
    $options->set('fontDir',             BASE_PATH . '/vendor/dompdf/dompdf/lib/fonts');
    $options->set('fontCache',           $fontCacheDir);
    $options->set('tempDir',             $fontCacheDir);
    $options->set('defaultFont',         'DejaVu Sans');
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled',      false);
    $dompdf = new Dompdf\Dompdf($options);
    if ($format === 'pos80') {
        $dompdf->loadHtml(getPos80Html(
            $payment, $orNum, $companyName, $companyAddr, $companyTIN, $companyTel, $currency, $logoHtml,
            $taxAmount, $taxRate, $taxName, $taxType, $baseAmount, $totalAmount,
            $companyEmail, $companySEC, $companyBP
        ));
        // 80mm = 226.77pt; use tall height so all content fits on one page
        $dompdf->setPaper([0, 0, 226.77, 1000], 'portrait');
    } else {
        $dompdf->loadHtml(getA4Html(
            $payment, $orNum, $companyName, $companyAddr, $companyTIN, $companyTel, $currency, $logoHtml,
            $taxAmount, $taxRate, $taxName, $taxType, $baseAmount, $totalAmount
        ));
        $dompdf->setPaper('A4', 'portrait');
    }
    $dompdf->render();
    $dompdf->stream("Receipt-{$orNum}.pdf", ['Attachment' => true]);
    exit;
}

// ── Helper: build A4 HTML ─────────────────────────────────────
function getA4Html($p, $orNum, $co, $addr, $tin, $tel, $sym, $logo,
                   float $taxAmt = 0, float $taxRt = 0, string $taxNm = 'VAT',
                   string $taxTyp = 'excluded', float $baseAmt = 0, float $totalAmt = 0): string {
    $baseAmt    = $baseAmt > 0 ? $baseAmt : (float)$p['amount'];
    $totalAmt   = $totalAmt > 0 ? $totalAmt : $baseAmt;
    $amount     = number_format($baseAmt, 2);
    $date       = date('F d, Y h:i A', strtotime($p['payment_date']));
    $subscriber = htmlspecialchars($p['firstname'] . ' ' . $p['lastname'], ENT_QUOTES);
    $acct       = htmlspecialchars($p['account_number'], ENT_QUOTES);
    $plan       = htmlspecialchars($p['plan_title'] ?? '', ENT_QUOTES);
    $typeLabel  = htmlspecialchars($p['payment_type_name'] ?? '', ENT_QUOTES) ?: 'Internet Service';
    $method     = ucfirst(htmlspecialchars($p['method'], ENT_QUOTES));
    $ref        = htmlspecialchars($p['reference_number'] ?? '', ENT_QUOTES);
    $cashier    = htmlspecialchars(trim(($p['cashier_first'] ?? '') . ' ' . ($p['cashier_last'] ?? '')), ENT_QUOTES);
    $periodFrom = $p['period_start'] ? date('M d, Y', strtotime($p['period_start'])) : '—';
    $periodTo   = $p['period_end']   ? date('M d, Y', strtotime($p['period_end']))   : '—';
    $addrStr    = implode(', ', array_filter([$p['barangay'] ?? '', $p['municipality'] ?? '', $p['province'] ?? '']));
    $coHtml     = htmlspecialchars($co, ENT_QUOTES);
    $addrHtml   = htmlspecialchars($addr, ENT_QUOTES);
    $tinHtml    = htmlspecialchars($tin,  ENT_QUOTES);
    $telHtml    = htmlspecialchars($tel,  ENT_QUOTES);

    return '<!DOCTYPE html><html><head><meta charset="UTF-8">
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 10pt; color: #333; margin: 0; padding: 0; }
        .header-band { background: #fff; color: #000; padding: 20px 28px; display:flex; justify-content:space-between; align-items:flex-start; border-bottom: 2px solid #000; }
        .header-band h2 { margin:0 0 4px; font-size:15pt; }
        .header-band .co-sub { font-size:8.5pt; color:#555; margin:1px 0; }
        .header-band .or-box { text-align:right; }
        .header-band .or-box h3 { margin:0 0 4px; font-size:13pt; }
        .header-band .or-box p  { margin:1px 0; font-size:8.5pt; color:#555; }
        .body-pad { padding: 20px 28px; }
        .info-row { display:table; width:100%; border-collapse:separate; border-spacing:10px 0; margin-bottom:16px; }
        .info-cell { display:table-cell; width:33.33%; background:#f8f9fa; border-radius:4px; padding:10px 12px; vertical-align:top; }
        .info-cell .lbl { font-size:7.5pt; text-transform:uppercase; color:#888; letter-spacing:.04em; margin-bottom:5px; }
        .info-cell .row-item { font-size:9pt; margin:2px 0; }
        .info-cell .row-item span:first-child { color:#888; font-size:8pt; }
        table.items { width:100%; border-collapse:collapse; margin-bottom:16px; font-size:9.5pt; }
        table.items th { background:#e9e9e9; color:#000; padding:8px 10px; text-align:left; }
        table.items td { padding:8px 10px; border:1px solid #dee2e6; vertical-align:top; }
        table.items .text-right { text-align:right; }
        .total-row { background:#f0f0f0; font-weight:bold; font-size:11pt; }
        .sig-row { display:table; width:100%; margin-top:50px; }
        .sig-cell { display:table-cell; width:45%; text-align:center; padding-top:8px; border-top:1px solid #333; font-size:8.5pt; color:#555; }
        .sig-cell.mid { width:10%; border-top:none; }
        .footer-note { border-top:1px solid #ddd; margin-top:20px; padding-top:10px; text-align:center; font-size:8pt; color:#888; }
    </style></head>
    <body>
        <div class="header-band">
            <div>' . $logo . '<h2>' . $coHtml . '</h2>
                <p class="co-sub">' . $addrHtml . '</p>
                ' . ($tinHtml ? '<p class="co-sub">TIN: ' . $tinHtml . '</p>' : '') . '
                ' . ($telHtml ? '<p class="co-sub">Tel: ' . $telHtml . '</p>' : '') . '
            </div>
            <div class="or-box">
                <h3>OFFICIAL RECEIPT</h3>
                <p>OR #: <strong>' . htmlspecialchars($orNum, ENT_QUOTES) . '</strong></p>
                <p>Date: ' . $date . '</p>
                <p>Status: <strong>' . strtoupper($p['status']) . '</strong></p>
            </div>
        </div>
        <div class="body-pad">
            <div class="info-row">
                <div class="info-cell">
                    <div class="lbl">Bill To</div>
                    <div style="font-weight:bold;font-size:10pt;">' . $subscriber . '</div>
                    <div class="row-item"><span>Account #:</span> ' . $acct . '</div>
                    ' . ($addrStr ? '<div class="row-item" style="font-size:8.5pt;color:#666;">' . htmlspecialchars($addrStr, ENT_QUOTES) . '</div>' : '') . '
                </div>
                <div class="info-cell">
                    <div class="lbl">Connection Info</div>
                    ' . ($plan ? '<div class="row-item"><span>Plan:</span> ' . $plan . '</div>' : '') . '
                    ' . (($p['speed_mbps'] ?? '') ? '<div class="row-item"><span>Speed:</span> ' . $p['speed_mbps'] . ' Mbps</div>' : '') . '
                    ' . (($p['connection_type'] ?? '') ? '<div class="row-item"><span>Type:</span> ' . strtoupper(htmlspecialchars($p['connection_type'], ENT_QUOTES)) . '</div>' : '') . '
                    ' . (($p['ppp_username'] ?? '') ? '<div class="row-item"><span>Username:</span> ' . htmlspecialchars($p['ppp_username'], ENT_QUOTES) . '</div>' : '') . '
                </div>
                <div class="info-cell">
                    <div class="lbl">Payment Info</div>
                    <div class="row-item"><span>Method:</span> ' . $method . '</div>
                    ' . ($cashier ? '<div class="row-item"><span>Received by:</span> ' . $cashier . '</div>' : '') . '
                    <div class="row-item"><span>Period:</span> ' . $periodFrom . ' – ' . $periodTo . '</div>
                </div>
            </div>
            <table class="items">
                <thead><tr>
                    <th style="width:35%">Description</th>
                    <th style="width:25%">Plan / Speed</th>
                    <th style="width:25%">Service Period</th>
                    <th class="text-right" style="width:15%">Amount</th>
                </tr></thead>
                <tbody>
                    <tr>
                        <td>' . $typeLabel . ($plan ? '<br><span style="color:#888;font-size:8.5pt;">(' . $plan . ')</span>' : '') . '</td>
                        <td>' . ($p['speed_mbps'] ? $p['speed_mbps'] . ' Mbps' : '—') . '</td>
                        <td>' . $periodFrom . '<br><span style="color:#888;font-size:8.5pt;">to ' . $periodTo . '</span></td>
                        <td class="text-right">' . $sym . $amount . '</td>
                    </tr>
                    ' . ($taxAmt > 0 ? '<tr>
                        <td colspan="3" style="text-align:right;color:#888;">
                            ' . htmlspecialchars($taxNm, ENT_QUOTES) . ' ' . $taxRt . '%' . ($taxTyp === 'included' ? ' (incl.)' : '') . '
                        </td>
                        <td class="text-right" style="color:#888;">' . $sym . number_format($taxAmt, 2) . '</td>
                    </tr>' : '') . '
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td colspan="3" class="text-right">TOTAL AMOUNT DUE</td>
                        <td class="text-right">' . $sym . number_format($totalAmt, 2) . '</td>
                    </tr>
                </tfoot>
            </table>
            <div class="footer-note">
                <strong>THIS IS YOUR OFFICIAL RECEIPT — PLEASE KEEP FOR YOUR RECORDS</strong><br>
                ' . $coHtml . ($addrHtml ? ' · ' . $addrHtml : '') . ($telHtml ? ' · Tel: ' . $telHtml : '') . ' &copy; ' . date('Y') . '
            </div>
        </div>
    </body></html>';
}

// ── Helper: build 80mm POS HTML for PDF (table-based; Dompdf has no flexbox) ──
function getPos80Html($p, $orNum, $co, $addr, $tin, $tel, $sym, $logo,
                      float $taxAmt = 0, float $taxRt = 0, string $taxNm = 'VAT',
                      string $taxTyp = 'excluded', float $baseAmt = 0, float $totalAmt = 0,
                      string $email = '', string $sec = '', string $bp = ''): string {
    $baseAmt  = $baseAmt  > 0 ? $baseAmt  : (float)$p['amount'];
    $totalAmt = $totalAmt > 0 ? $totalAmt : $baseAmt;
    $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES);

    $subscriber = $h($p['firstname'] . ' ' . $p['lastname']);
    $acct       = $h($p['account_number']);
    $plan       = $h($p['plan_title'] ?? '');
    $typeLabel  = $h($p['payment_type_name'] ?? '') ?: 'Internet Service';
    $method     = ucfirst($h($p['method']));
    $ref        = $h($p['reference_number'] ?? '');
    $note       = $h($p['notes'] ?? '');
    $contact    = $h($p['contact_number'] ?? '');
    $coH        = $h($co);
    $addrH      = $h($addr);
    $tinH       = $h($tin);
    $telH       = $h($tel);
    $emailH     = $h($email);
    $secH       = $h($sec);
    $bpH        = $h($bp);
    $date       = date('M j, Y', strtotime($p['payment_date']));
    $time       = date('g:i A',  strtotime($p['payment_date']));
    $addrParts  = array_filter([$p['barangay'] ?? '', $p['municipality'] ?? '', $p['province'] ?? '']);
    $addrStr    = $h(mb_strimwidth(implode(', ', $addrParts), 0, 55, '…'));
    $periodFrom = $p['period_start'] ? date('M d', strtotime($p['period_start'])) : '';
    $periodTo   = $p['period_end']   ? date('M d, Y', strtotime($p['period_end'])) : '';

    // Two-column table row helper
    $row = fn(string $l, string $v) =>
        '<table width="100%" cellpadding="0" cellspacing="0" style="margin:1px 0;font-size:9pt;">'
        . '<tr><td>' . $l . '</td><td align="right">' . $v . '</td></tr></table>';
    $divider       = '<hr style="border:none;border-top:1px dashed #000;margin:4px 0;">';
    $doubleDivider = '<hr style="border:none;border-top:2px solid #000;margin:4px 0;">';

    $html  = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    $html .= '<style>';
    $html .= '* { margin:0; padding:0; box-sizing:border-box; }';
    $html .= 'body { font-family: "DejaVu Sans", Arial, sans-serif; font-size:9pt; color:#000; width:226pt; }';
    $html .= '.center { text-align:center; } .bold { font-weight:bold; }';
    $html .= '.company { font-size:12pt; font-weight:bold; text-transform:uppercase; text-align:center; letter-spacing:.03em; }';
    $html .= '.sep     { text-align:center; font-size:7.5pt; margin:2pt 0; line-height:1.1; }';
    $html .= '.title   { font-size:10pt; font-weight:bold; text-transform:uppercase; text-align:center; margin:3pt 0; letter-spacing:.08em; }';
    $html .= '.total   { font-size:11pt; font-weight:bold; }';
    $html .= '.footer  { font-size:8pt;  text-align:center; margin-top:6pt; line-height:1.5; }';
    $html .= '.sig     { border-top:1px solid #000; padding-top:4pt; text-align:center; font-size:8.5pt; margin-top:30pt; }';
    $html .= '</style></head><body>';

    // Formal header bar
    $html .= '<div style="background:#000;height:4pt;margin-bottom:5pt;"></div>';

    if ($logo) {
        $logoClean = preg_replace('/object-fit:[^;";]+;?/', '', $logo);
        $html .= '<div class="center" style="margin-bottom:4pt;">' . $logoClean . '</div>';
    }

    $html .= '<div class="company">' . $coH . '</div>';
    if ($addrH)  $html .= '<div class="center" style="font-size:8.5pt;">' . $addrH . '</div>';
    if ($tinH || $secH) {
        $parts = array_filter([($tinH ? 'TIN: ' . $tinH : ''), ($secH ? 'SEC: ' . $secH : '')]);
        $html .= '<div class="center" style="font-size:8.5pt;">' . implode(' | ', $parts) . '</div>';
    }
    if ($bpH)    $html .= '<div class="center" style="font-size:8.5pt;">BP No: ' . $bpH . '</div>';
    if ($telH)   $html .= '<div class="center" style="font-size:8.5pt;">Tel: ' . $telH . '</div>';
    if ($emailH) $html .= '<div class="center" style="font-size:8.5pt;">' . $emailH . '</div>';

    $html .= '<div class="sep">================================</div>';
    $html .= '<div class="title">*&nbsp;&nbsp;OFFICIAL RECEIPT&nbsp;&nbsp;*</div>';
    $html .= '<div class="sep">================================</div>';
    $html .= $row('Receipt #:', '<b>' . $h($orNum) . '</b>');
    $html .= $row('Date:', $date);
    $html .= $row('Time:', $time);
    $html .= $divider;

    $html .= '<div class="bold">Bill To:</div>';
    $html .= '<div style="font-size:9pt;">' . $subscriber . '</div>';
    $html .= $row('Account #:', $acct);
    if ($contact) $html .= $row('Contact:', $contact);
    if ($addrStr) $html .= '<div style="font-size:8pt;">Address: ' . $addrStr . '</div>';
    $html .= $divider;

    $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin:1px 0;font-size:9pt;">'
           . '<tr><td class="bold">DESCRIPTION</td><td align="right" class="bold">AMOUNT</td></tr></table>';

    $desc = $typeLabel . ($plan ? "\n(" . $plan . ")" : '');
    if ($periodFrom && $periodTo) $desc .= "\n" . $periodFrom . ' – ' . $periodTo;
    $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin:2px 0;font-size:9pt;">'
           . '<tr><td style="white-space:pre-line;">' . $desc . '</td>'
           . '<td align="right" valign="top">' . $sym . number_format($baseAmt, 2) . '</td></tr></table>';

    if ($taxAmt > 0) {
        $taxLabel = $h($taxNm) . ' (' . $taxRt . '%' . ($taxTyp === 'included' ? ' incl.' : '') . '):';
        $html .= $row($taxLabel, $sym . number_format($taxAmt, 2));
    }

    $html .= $doubleDivider;
    $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin:2px 0;font-size:11pt;font-weight:bold;">'
           . '<tr><td>TOTAL AMOUNT:</td><td align="right">' . $sym . number_format($totalAmt, 2) . '</td></tr></table>';
    $html .= $divider;

    $html .= $row('Payment Method:', '<b>' . $method . '</b>');
    $html .= $divider;



    $html .= '<div class="footer">'
           . '<div class="sep">================================</div>'
           . '<b>THANK YOU FOR YOUR PAYMENT!</b><br>'
           . 'Keep this receipt for your records.<br>'
           . 'For inquiries, please contact us.<br>'
           . '<b>' . $coH . '</b>'
           . ($telH ? '<br>Tel: ' . $telH : '')
           . ($emailH ? '<br>' . $emailH : '')
           . '<div class="sep">================================</div>'
           . '</div>';

    $html .= '</body></html>';
    return $html;
}

$pageTitle   = 'Receipt ' . $orNum;
$breadcrumbs = [
    ['label' => 'Payments', 'url' => BASE_URL . '/modules/payments/'],
    ['label' => 'Receipt ' . $orNum],
];

$extraHead = <<<CSS
<style>
    /* ── POS 80mm ── */
    @page { size: 80mm auto; margin: 0; }
    .pos-80 {
        font-family: 'Courier New', monospace;
        font-size: 11px;
        color: #000;
        width: 100%;
    }
    .pos-80 .text-center { text-align: center; }
    .pos-80 .bold { font-weight: bold; }
    .pos-80 .divider { border-top: 1px dashed #000; margin: 3px 0; }
    .pos-80 .double-divider { border-top: 2px solid #000; margin: 4px 0; }
    .pos-80 .pos-row { display: flex; justify-content: space-between; margin: 1px 0; }
    .pos-80 .company-name { font-size: 15px; font-weight: bold; text-transform: uppercase; letter-spacing: .04em; }
    .pos-80 .receipt-title { font-size: 12px; font-weight: bold; text-transform: uppercase; text-align: center; margin: 3px 0; letter-spacing: .08em; }
    .pos-80 .separator { text-align: center; font-size: 9px; margin: 2px 0; overflow: hidden; white-space: nowrap; line-height: 1.2; }
    .pos-80 .header-bar { background: #000; height: 4px; margin-bottom: 5px; border-radius: 1px; }
    .pos-80 .item-row { display: flex; justify-content: space-between; margin: 1px 0; }
    .pos-80 .item-desc { flex: 2; text-align: left; }
    .pos-80 .item-amt { flex: 1; text-align: right; }
    .pos-80 .total-row { font-weight: bold; font-size: 12px; margin: 3px 0; }
    .pos-80 .pos-footer { font-size: 9px; margin-top: 5px; line-height: 1.5; }
    .pos-80 .logo-wrap { text-align: center; margin-bottom: 4px; }
    .pos-80 .logo-wrap img { max-width: 55px; height: auto; }
    /* ── A4 ── */
    .a4 { width: 100%; font-family: Arial, sans-serif; }
    .a4 .header-band { background: #0d6efd; color: #fff; padding: 24px 32px; }
    .a4 .body-pad { padding: 24px 32px; }
    .a4 .label { color: #888; font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; }
    .a4 .val   { font-weight: 600; }
    .a4 .info-box { background: #f8f9fa; border-radius: 6px; padding: 12px 14px; height: 100%; }
    .a4 .sig-line { border-top: 1px solid #333; padding-top: 6px; text-align: center; margin-top: 60px; }
    /* ── Print: hide app chrome, show only receipt ── */
    @media print {
        @page { size: A4 portrait; margin: 12mm 15mm; }
        .sidebar, .bg-topbar, .no-print, .toast-container { display: none !important; }
        .main-content { margin-left: 0 !important; padding: 0 !important; }
        .wrapper { display: block !important; }
        .container-fluid { padding: 0 !important; }
        body { background: #fff !important; }
        .row.justify-content-center > [class*="col-"] {
            width: 100% !important; max-width: 100% !important;
            flex: 0 0 100% !important; padding: 0 !important;
        }
        .card { box-shadow: none !important; border: none !important; }
        .card-body { padding: 0 !important; }
        /* ── A4 light-only print overrides ── */
        .a4 .header-band {
            background: #fff !important;
            color: #000 !important;
            border-bottom: 2px solid #000;
        }
        .a4 .header-band h2,
        .a4 .header-band h3,
        .a4 .header-band h4,
        .a4 .header-band p,
        .a4 .header-band div,
        .a4 .header-band .co-sub,
        .a4 .header-band .opacity-75,
        .a4 .header-band .opacity-90,
        .a4 .header-band .small {
            color: #000 !important;
            opacity: 1 !important;
        }
        .a4 .header-band .badge { display: none !important; }
        .a4 table.items th {
            background: #e9e9e9 !important;
            color: #000 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .a4 .total-row {
            background: #f0f0f0 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .a4 .info-box { background: #f8f9fa !important; }
    }
</style>
CSS;

include BASE_PATH . '/includes/header.php';
?>

<!-- Toolbar -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4 no-print">
    <div>
        <h4 class="fw-bold mb-0">Receipt</h4>
        <p class="text-muted small mb-0">OR # <?= e($orNum) ?> &mdash; <?= e($payment['firstname'] . ' ' . $payment['lastname']) ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <div class="btn-group btn-group-sm" role="group">
            <a href="?format=pos80" class="btn <?= $format === 'pos80' ? 'btn-primary' : 'btn-outline-primary' ?>">
                <i class="bi bi-receipt me-1"></i>80mm POS
            </a>
            <a href="?format=a4" class="btn <?= $format === 'a4' ? 'btn-primary' : 'btn-outline-primary' ?>">
                <i class="bi bi-file-text me-1"></i>A4 Invoice
            </a>
        </div>
        <button onclick="window.print()" class="btn btn-sm btn-success">
            <i class="bi bi-printer me-1"></i>Print
        </button>
        <a href="?format=<?= e($format) ?>&pdf=1" class="btn btn-sm btn-danger">
            <i class="bi bi-file-earmark-pdf me-1"></i>Download PDF
        </a>
        <a href="<?= BASE_URL ?>/modules/payments/" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<!-- Receipt content -->
<?php if ($format === 'pos80'): ?>
<div class="d-flex justify-content-center">
    <div style="width:80mm; background:#fff; box-shadow:0 2px 12px rgba(0,0,0,.15); border-radius:4px; padding:4mm;">
<?php else: ?>
<div class="row justify-content-center">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
<?php endif; ?>

<?php if ($format === 'pos80'): ?>
<!-- ── POS 80mm Receipt ──────────────────────────────────────── -->
<div class="pos-80">

    <!-- Formal Header Bar -->
    <div class="header-bar"></div>

    <?php if ($logoHtml): ?>
    <div class="logo-wrap"><?= $logoHtml ?></div>
    <?php endif; ?>

    <!-- Company Header -->
    <div class="text-center" style="line-height:1.4;">
        <div class="company-name"><?= e($companyName) ?></div>
        <?php if ($companyAddr): ?>
        <div><?= e($companyAddr) ?></div>
        <?php endif; ?>
        <?php if ($companyTIN || $companySEC): ?>
        <div>
            <?= $companyTIN ? 'TIN: ' . e($companyTIN) : '' ?>
            <?= ($companyTIN && $companySEC) ? ' | ' : '' ?>
            <?= $companySEC ? 'SEC: ' . e($companySEC) : '' ?>
        </div>
        <?php endif; ?>
        <?php if ($companyBP): ?>
        <div>BP No: <?= e($companyBP) ?></div>
        <?php endif; ?>
        <?php if ($companyTel): ?>
        <div>Tel: <?= e($companyTel) ?></div>
        <?php endif; ?>
        <?php if ($companyEmail): ?>
        <div><?= e($companyEmail) ?></div>
        <?php endif; ?>
    </div>

    <div class="separator">================================</div>

    <!-- Receipt Title -->
    <div class="receipt-title">*&nbsp;&nbsp;OFFICIAL RECEIPT&nbsp;&nbsp;*</div>
    <div class="separator">================================</div>

    <!-- Receipt Details -->
    <div class="pos-row"><span>Receipt #:</span><span class="bold"><?= e($orNum) ?></span></div>
    <div class="pos-row"><span>Date:</span><span><?= formatDate($payment['payment_date'], 'M j, Y') ?></span></div>
    <div class="pos-row"><span>Time:</span><span><?= formatDate($payment['payment_date'], 'g:i A') ?></span></div>

    <div class="divider"></div>

    <!-- Bill To -->
    <div class="bold">Bill To:</div>
    <div><?= e($payment['firstname'] . ' ' . $payment['lastname']) ?></div>
    <div class="pos-row"><span>Account #:</span><span><?= e($payment['account_number']) ?></span></div>
    <?php if ($payment['contact_number']): ?>
    <div class="pos-row"><span>Contact:</span><span><?= e($payment['contact_number']) ?></span></div>
    <?php endif; ?>
    <?php
        $addrParts = array_filter([$payment['barangay'] ?? '', $payment['municipality'] ?? '', $payment['province'] ?? '']);
        $addrStr   = implode(', ', $addrParts);
    ?>
    <?php if ($addrStr): ?>
    <div style="font-size:10px;">Address: <?= e(mb_strimwidth($addrStr, 0, 50, '…')) ?></div>
    <?php endif; ?>

    <div class="divider"></div>

    <!-- Items -->
    <div class="item-row bold"><span>DESCRIPTION</span><span>AMOUNT</span></div>

    <div class="item-row">
        <div class="item-desc">
            <?= e($payment['payment_type_name'] ?: 'Internet Service') ?>
            <?php if ($payment['plan_title']): ?><br><small>(<?= e($payment['plan_title']) ?>)</small><?php endif; ?>
            <?php if ($payment['period_start'] && $payment['period_end']): ?>
            <br><small><?= formatDate($payment['period_start'], 'M d') ?> – <?= formatDate($payment['period_end'], 'M d, Y') ?></small>
            <?php endif; ?>
        </div>
        <div class="item-amt"><?= e($currency) ?><?= $amount ?></div>
    </div>

    <?php if ($taxAmount > 0): ?>
    <div class="item-row">
        <div class="item-desc"><?= e($taxName) ?> (<?= $taxRate ?>%<?= $taxTypeLabel ?>)</div>
        <div class="item-amt"><?= e($currency) ?><?= number_format($taxAmount, 2) ?></div>
    </div>
    <?php endif; ?>

    <div class="double-divider"></div>

    <!-- Total -->
    <div class="pos-row total-row">
        <span>TOTAL AMOUNT:</span>
        <span><?= e($currency) ?><?= number_format($totalAmount, 2) ?></span>
    </div>

    <div class="divider"></div>

    <!-- Payment Method -->
    <div class="pos-row">
        <span>Payment Method:</span>
        <span class="bold"><?= ucfirst(e($payment['method'])) ?></span>
    </div>
    <div class="divider"></div>


    <!-- Footer -->
    <div class="pos-footer text-center">
        <div class="separator">================================</div>
        <div class="bold">THANK YOU FOR YOUR PAYMENT!</div>
        <div style="margin-top:2px;">Keep this receipt for your records.</div>
        <div>For inquiries, please contact us.</div>
        <div class="bold" style="margin-top:3px;"><?= e($companyName) ?></div>
        <?php if ($companyTel): ?><div>Tel: <?= e($companyTel) ?></div><?php endif; ?>
        <?php if ($companyEmail): ?><div><?= e($companyEmail) ?></div><?php endif; ?>
        <div class="separator">================================</div>
    </div>

</div>

<?php else: ?>
<!-- ── A4 Invoice ───────────────────────────────────────────── -->
<div class="a4">

    <!-- Header band -->
    <div class="header-band d-flex justify-content-between align-items-start">
        <div>
            <?php if ($logoHtml): echo $logoHtml; ?><br><?php endif; ?>
            <h4 class="fw-bold mb-0 mt-1"><?= e($companyName) ?></h4>
            <?php if ($companyAddr):  ?><div class="small opacity-75"><?= e($companyAddr) ?></div><?php endif; ?>
            <?php if ($companyTIN):   ?><div class="small opacity-75">TIN: <?= e($companyTIN) ?></div><?php endif; ?>
            <?php if ($companySEC):   ?><div class="small opacity-75">SEC: <?= e($companySEC) ?></div><?php endif; ?>
            <?php if ($companyBP):    ?><div class="small opacity-75">BP: <?= e($companyBP) ?></div><?php endif; ?>
            <?php if ($companyTel):   ?><div class="small opacity-75">Tel: <?= e($companyTel) ?></div><?php endif; ?>
            <?php if ($companyEmail): ?><div class="small opacity-75">Email: <?= e($companyEmail) ?></div><?php endif; ?>
        </div>
        <div class="text-end">
            <h4 class="fw-bold mb-1">OFFICIAL RECEIPT</h4>
            <div class="small opacity-90">OR #: <strong><?= e($orNum) ?></strong></div>
            <div class="small opacity-90">Date: <?= formatDate($payment['payment_date'], 'F d, Y') ?></div>
            <div class="small opacity-90">Time: <?= formatDate($payment['payment_date'], 'h:i A') ?></div>
            <div class="mt-2">
                <span class="badge <?= $payment['status'] === 'paid' ? 'bg-success' : 'bg-warning text-dark' ?> fs-6 px-3 py-2">
                    <?= strtoupper($payment['status']) ?>
                </span>
            </div>
        </div>
    </div>

    <div class="body-pad">

        <!-- Three-column info row -->
        <?php
        $addrParts = array_filter([$payment['barangay'] ?? '', $payment['municipality'] ?? '', $payment['province'] ?? '']);
        ?>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="info-box">
                    <div class="label mb-2">Bill To</div>
                    <div class="fw-bold"><?= e($payment['firstname'] . ' ' . $payment['lastname']) ?></div>
                    <div class="small text-muted">Account #: <strong><?= e($payment['account_number']) ?></strong></div>
                    <?php if ($addrParts): ?>
                    <div class="small text-muted"><?= e(implode(', ', $addrParts)) ?></div>
                    <?php endif; ?>
                    <?php if ($payment['contact_number']): ?>
                    <div class="small text-muted">Tel: <?= e($payment['contact_number']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-box">
                    <div class="label mb-2">Connection Info</div>
                    <?php if ($payment['connection_type']): ?>
                    <div class="small"><span class="label">Type:</span> <span class="val"><?= strtoupper(e($payment['connection_type'])) ?></span></div>
                    <?php endif; ?>
                    <?php if ($payment['ppp_username']): ?>
                    <div class="small"><span class="label">Username:</span> <span class="val font-monospace"><?= e($payment['ppp_username']) ?></span></div>
                    <?php endif; ?>
                    <?php if ($payment['plan_title']): ?>
                    <div class="small"><span class="label">Plan:</span> <span class="val"><?= e($payment['plan_title']) ?></span></div>
                    <?php endif; ?>
                    <?php if ($payment['speed_mbps']): ?>
                    <div class="small"><span class="label">Speed:</span> <span class="val"><?= e($payment['speed_mbps']) ?> Mbps</span></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-box">
                    <div class="label mb-2">Payment Info</div>
                    <div class="small"><span class="label">Method:</span> <span class="val"><?= ucfirst(e($payment['method'])) ?></span></div>
                    <?php if ($payment['period_start'] && $payment['period_end']): ?>
                    <div class="small"><span class="label">Period:</span> <span class="val"><?= formatDate($payment['period_start'], 'M d, Y') ?> – <?= formatDate($payment['period_end'], 'M d, Y') ?></span></div>
                    <?php endif; ?>
                    <?php if ($payment['cashier_first']): ?>
                    <div class="small"><span class="label">Received by:</span> <span class="val"><?= e($payment['cashier_first'] . ' ' . $payment['cashier_last']) ?></span></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Full-width items table -->
        <table class="table table-bordered mb-3" style="font-size:.93rem;">
            <thead class="table-primary">
                <tr>
                    <th style="width:35%">Description</th>
                    <th style="width:25%">Plan / Speed</th>
                    <th style="width:25%">Service Period</th>
                    <th class="text-end" style="width:15%">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <?= e($payment['payment_type_name'] ?: 'Internet Service') ?>
                        <?php if ($payment['plan_title']): ?>
                        <br><small class="text-muted">(<?= e($payment['plan_title']) ?>)</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= $payment['speed_mbps'] ? e($payment['speed_mbps']) . ' Mbps' : '—' ?>
                    </td>
                    <td>
                        <?php if ($payment['period_start'] && $payment['period_end']): ?>
                        <?= formatDate($payment['period_start'], 'M d, Y') ?><br>
                        <small class="text-muted">to <?= formatDate($payment['period_end'], 'M d, Y') ?></small>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="text-end fw-semibold"><?= e($currency) ?><?= $amount ?></td>
                </tr>
                <?php if ($taxAmount > 0): ?>
                <tr>
                    <td colspan="3" class="text-end text-muted"><?= e($taxName) ?> (<?= $taxRate ?>%<?= $taxTypeLabel ?>)</td>
                    <td class="text-end text-muted"><?= e($currency) ?><?= number_format($taxAmount, 2) ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="table-success">
                    <td colspan="3" class="text-end fw-bold fs-6">TOTAL AMOUNT DUE</td>
                    <td class="text-end fw-bold fs-5 text-success"><?= e($currency) ?><?= number_format($totalAmount, 2) ?>
                        <?php if ($taxAmount > 0 && $taxType === 'included'): ?>
                        <div class="text-muted fw-normal" style="font-size:.72rem;"><?= e($taxName) ?> incl.</div>
                        <?php endif; ?>
                    </td>
                </tr>
            </tfoot>
        </table>


        <!-- Signature row -->

        <!-- Footer -->
        <div class="text-center mt-4 pt-3 border-top">
            <div class="fw-semibold small">THIS IS YOUR OFFICIAL RECEIPT — PLEASE KEEP FOR YOUR RECORDS</div>
            <div class="text-muted small mt-1">
                <?= e($companyName) ?>
                <?= $companyAddr ? ' · ' . e($companyAddr) : '' ?>
                <?= $companyTel  ? ' · Tel: ' . e($companyTel) : '' ?>
                <?= $companyEmail ? ' · ' . e($companyEmail) : '' ?>
                &copy; <?= date('Y') ?>
            </div>
        </div>

    </div>
</div>
<?php endif; ?>

<?php if ($format === 'pos80'): ?>
    </div><!-- /80mm paper -->
</div><!-- /flex center -->
<?php else: ?>
            </div><!-- /card-body -->
        </div><!-- /card -->
    </div>
</div>
<?php endif; ?>

<?php include BASE_PATH . '/includes/footer.php'; ?>
