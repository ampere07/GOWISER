<?php
// ── Role definitions ─────────────────────────────────────────
const ROLE_SUPERADMIN = 'superadmin';
const ROLE_ADMIN      = 'admin';
const ROLE_USER       = 'user';
const ROLE_CASHIER    = 'cashier';
const ROLE_LINEMAN    = 'lineman';

const ROLES = [
    ROLE_SUPERADMIN => 'Super Administrator',
    ROLE_ADMIN      => 'Administrator',
    ROLE_USER       => 'User',
    ROLE_CASHIER    => 'Cashier',
    ROLE_LINEMAN    => 'Lineman',
];

// ── Role hierarchy (admin escalation only) ────────────────────
// Cashier, user, and lineman are separate business roles. Use
// requireRole()/hasRole() when granting access to those roles.
const ROLE_LEVELS = [
    ROLE_LINEMAN    => 1,
    ROLE_CASHIER    => 1,
    ROLE_USER       => 1,
    ROLE_ADMIN      => 4,
    ROLE_SUPERADMIN => 5,
];

// ── Subscriber status ────────────────────────────────────────
const SUB_STATUS_ACTIVE    = 'active';
const SUB_STATUS_SUSPENDED = 'suspended';
const SUB_STATUS_EXPIRED   = 'expired';
const SUB_STATUS_PENDING   = 'pending';
const SUB_STATUS_ARCHIVED  = 'archived';

// ── Connection types ─────────────────────────────────────────
const CONN_PPP     = 'ppp';
const CONN_HOTSPOT = 'hotspot';

// ── Payment methods (fallback if DB table unavailable) ────────
const PAY_METHODS = [
    'cash'          => 'Cash',
    'gcash'         => 'GCash',
    'maya'          => 'Maya',
    'bank_transfer' => 'Bank Transfer',
    'online'        => 'Online',
    'other'         => 'Other',
];

// ── Currency options ─────────────────────────────────────────
const CURRENCY_OPTIONS = [
    '₱'  => 'Philippine Peso (₱)',
    '$'  => 'US Dollar ($)',
    'Rp' => 'Indonesian Rupiah (Rp)',
    'RM' => 'Malaysian Ringgit (RM)',
    '฿'  => 'Thai Baht (฿)',
    '₫'  => 'Vietnamese Dong (₫)',
    '₭'  => 'Lao Kip (₭)',
    'K'  => 'Myanmar Kyat (K)',
    'KHR'=> 'Cambodian Riel (KHR)',
    '৳'  => 'Bangladeshi Taka (৳)',
    '₹'  => 'Indian Rupee (₹)',
    'Rs' => 'Sri Lanka Rupee (Rs)',
    '€'  => 'Euro (€)',
    '£'  => 'British Pound (£)',
    'A$' => 'Australian Dollar (A$)',
    '¥'  => 'Japanese Yen / Chinese Yuan (¥)',
    'S$' => 'Singapore Dollar (S$)',
    'R$' => 'Brazilian Real (R$)',
];

// ── Payment status ───────────────────────────────────────────
const PAY_STATUS_PAID      = 'paid';
const PAY_STATUS_PENDING   = 'pending';
const PAY_STATUS_REFUNDED  = 'refunded';
const PAY_STATUS_CANCELLED = 'cancelled';

// ── Plan types ───────────────────────────────────────────────
const PLAN_PPP     = 'ppp';
const PLAN_HOTSPOT = 'hotspot';
const PLAN_BOTH    = 'both';

// ── Billing cycles ───────────────────────────────────────────
const BILLING_MONTHLY   = 'monthly';
const BILLING_QUARTERLY = 'quarterly';
const BILLING_ANNUAL    = 'annual';

// ── Router brands ────────────────────────────────────────────
const ROUTER_BRANDS = [
    'mikrotik'  => 'MikroTik',
    'cisco'     => 'Cisco',
    'ubiquiti'  => 'Ubiquiti',
    'other'     => 'Other',
];

// ── Router status ────────────────────────────────────────────
const ROUTER_ONLINE      = 'online';
const ROUTER_OFFLINE     = 'offline';
const ROUTER_MAINTENANCE = 'maintenance';

// ── Pagination ───────────────────────────────────────────────
const DEFAULT_PER_PAGE = 25;

// ── Date formats ─────────────────────────────────────────────
const DATE_FORMAT     = 'Y-m-d';
const DATETIME_FORMAT = 'Y-m-d H:i:s';
const DISPLAY_DATE    = 'M d, Y';
const DISPLAY_DATETIME= 'M d, Y h:i A';

// ── Module access control ────────────────────────────────────
const MODULE_ACCESS = [
    'dashboard'   => [ROLE_LINEMAN, ROLE_CASHIER, ROLE_USER, ROLE_ADMIN, ROLE_SUPERADMIN],
    'dashboard2'  => [ROLE_ADMIN, ROLE_SUPERADMIN],
    'subscribers' => [ROLE_LINEMAN, ROLE_CASHIER, ROLE_USER, ROLE_ADMIN, ROLE_SUPERADMIN],
    'map'         => [ROLE_LINEMAN, ROLE_CASHIER, ROLE_USER, ROLE_ADMIN, ROLE_SUPERADMIN],
    'plans'       => [ROLE_ADMIN, ROLE_SUPERADMIN],
    'routers'     => [ROLE_SUPERADMIN],
    'payments'    => [ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN],
    'users'       => [ROLE_SUPERADMIN],
    'logs'        => [ROLE_ADMIN, ROLE_SUPERADMIN],
    'reports'     => [ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN],
    'bandwidth'     => [ROLE_LINEMAN, ROLE_CASHIER, ROLE_USER, ROLE_ADMIN, ROLE_SUPERADMIN],
    'settings'      => [ROLE_SUPERADMIN],
    'notifications' => [ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN],
    'expenses'      => [ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN],
    'cronjobs'      => [ROLE_ADMIN, ROLE_SUPERADMIN],
];
