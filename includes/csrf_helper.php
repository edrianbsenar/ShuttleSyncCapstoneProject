<?php
// csrf_helper.php

// 1. Generate the token if it doesn't exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 2. Create a function you can easily drop into your HTML forms
function get_csrf_input() {
    return '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';
}

// 3. Create a function to check the token on POST requests
function verify_csrf_token() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            // Log the attempt, then kill the script
            die("Security Error: Invalid or missing CSRF token. Request blocked.");
        }
        // NOTE: Token is intentionally NOT rotated per-POST because multi-form admin pages
        // render several forms with the same token at page-load time, so rotating would
        // break every subsequent form submission on the same page load.
        // Token rotation happens only at privilege escalation (login) — see login.php.
    }
}

// ---------------------------------------------------------------------------
// Recently placed order tracking
// ---------------------------------------------------------------------------
// Lets the customer dashboard show an order immediately after checkout, even
// if the session is regenerated or the customer signs in with another account
// in the same browser. The cookie is HMAC-signed so its contents cannot be
// forged, and logout.php clears it.
if (!function_exists('hp_recent_order_ids')) {
    function hp_recent_order_ids(): array {
        $ids = [];
        if (!empty($_SESSION['recent_order_ids']) && is_array($_SESSION['recent_order_ids'])) {
            $ids = array_map('intval', $_SESSION['recent_order_ids']);
        }
        if (isset($_COOKIE['hp_my_orders'])) {
            $parts = explode('|', (string)$_COOKIE['hp_my_orders']);
            if (count($parts) === 2) {
                [$payload, $sig] = $parts;
                $secret = class_exists('Config') ? (string)Config::get('CSRF_SECRET', 'hp-orders') : 'hp-orders';
                if (hash_equals(hash_hmac('sha256', $payload, $secret), $sig)) {
                    foreach (explode(',', $payload) as $v) {
                        if ($v !== '') { $ids[] = (int)$v; }
                    }
                }
            }
        }
        $ids = array_values(array_unique(array_filter($ids, function ($v) { return $v > 0; })));
        return array_slice($ids, -20);
    }
}

if (!function_exists('hp_remember_order')) {
    function hp_remember_order(int $order_id): void {
        if ($order_id <= 0) { return; }
        $ids = hp_recent_order_ids();
        $ids[] = $order_id;
        $ids = array_slice(array_values(array_unique($ids)), -20);
        $_SESSION['recent_order_ids'] = $ids;

        $payload = implode(',', $ids);
        $secret  = class_exists('Config') ? (string)Config::get('CSRF_SECRET', 'hp-orders') : 'hp-orders';
        $sig     = hash_hmac('sha256', $payload, $secret);
        setcookie('hp_my_orders', $payload . '|' . $sig, [
            'expires'  => time() + 60 * 60 * 24 * 30,
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
?>