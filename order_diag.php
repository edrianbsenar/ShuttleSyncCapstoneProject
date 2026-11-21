<?php
// TEMPORARY DIAGNOSTIC — delete after use.
// Upload to the production root and visit /order_diag.php while logged in.
// It only reads data; it never writes or changes anything.
require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/csrf_helper.php';
require_once __DIR__ . '/includes/database_connect.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== LOGIN / SESSION ===\n";
echo "logged_in : " . var_export($_SESSION['logged_in'] ?? null, true) . "\n";
echo "user_id   : " . var_export($_SESSION['user_id'] ?? null, true) . "\n";
echo "full_name : " . var_export($_SESSION['full_name'] ?? null, true) . "\n";
echo "email     : " . var_export($_SESSION['email'] ?? null, true) . "\n";
echo "role      : " . var_export($_SESSION['role'] ?? null, true) . "\n";

echo "\n=== RECENT ORDER MEMORY ===\n";
echo "session recent_order_ids : " . json_encode($_SESSION['recent_order_ids'] ?? []) . "\n";
echo "cookie hp_my_orders raw  : " . var_export($_COOKIE['hp_my_orders'] ?? null, true) . "\n";
if (function_exists('hp_recent_order_ids')) {
    echo "resolved ids             : " . json_encode(hp_recent_order_ids()) . "\n";
}

echo "\n=== SCHEMA ===\n";
foreach (['Orders', 'payments', 'payment_items'] as $t) {
    try {
        $c = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "$t exists, rows=$c\n";
    } catch (Throwable $e) {
        echo "$t MISSING: " . $e->getMessage() . "\n";
    }
}
foreach ([['Orders','payment_id'], ['Orders','order_number'], ['payments','user_id']] as [$t,$col]) {
    try {
        $ok = $pdo->query("SHOW COLUMNS FROM `$t` LIKE '$col'")->fetch();
        echo "$t.$col : " . ($ok ? 'yes' : 'NO') . "\n";
    } catch (Throwable $e) {
        echo "$t.$col : error " . $e->getMessage() . "\n";
    }
}

$uid = (int)($_SESSION['user_id'] ?? 0);

echo "\n=== ORDERS MATCHING DASHBOARD QUERY (user_id=$uid) ===\n";
try {
    $q = $pdo->prepare("
        SELECT o.order_id, o.order_number, o.user_id, o.order_date, o.total_amount, o.status
        FROM Orders o
        LEFT JOIN payments p ON o.payment_id = p.payment_id
        WHERE o.user_id = ?
           OR p.user_id = ?
           OR EXISTS (
                SELECT 1 FROM payment_items pi
                JOIN payments pp ON pp.payment_id = pi.payment_id
                WHERE pi.order_id = o.order_id AND pp.user_id = ?
           )
        ORDER BY o.order_date DESC, o.order_id DESC
        LIMIT 30
    ");
    $q->execute([$uid, $uid, $uid]);
    $rows = $q->fetchAll();
    echo "count=" . count($rows) . "\n";
    foreach ($rows as $r) {
        echo sprintf("  #%s | user_id=%s | %s | %s | ₱%s | status=%s\n",
            $r['order_id'], $r['user_id'], $r['order_number'], $r['order_date'], $r['total_amount'], $r['status']);
    }
} catch (Throwable $e) {
    echo "PREFERRED QUERY FAILED: " . $e->getMessage() . "\n";
    try {
        $q = $pdo->prepare("SELECT order_id, user_id, order_date, total_amount, status FROM Orders WHERE user_id = ? ORDER BY order_date DESC, order_id DESC LIMIT 30");
        $q->execute([$uid]);
        foreach ($q->fetchAll() as $r) {
            echo "  LEGACY #{$r['order_id']} user_id={$r['user_id']} status={$r['status']}\n";
        }
    } catch (Throwable $e2) {
        echo "LEGACY QUERY FAILED: " . $e2->getMessage() . "\n";
    }
}

echo "\n=== 15 MOST RECENT ORDERS IN DATABASE (any user) ===\n";
try {
    $q = $pdo->query("
        SELECT o.order_id, o.order_number, o.user_id, o.order_date, o.total_amount, o.status,
               u.email AS user_email, u.full_name AS user_name
        FROM Orders o
        LEFT JOIN Users u ON u.user_id = o.user_id
        ORDER BY o.order_id DESC
        LIMIT 15
    ");
    foreach ($q->fetchAll() as $r) {
        echo sprintf("  #%s | %s | user_id=%s | %s | %s | ₱%s | status=%s\n",
            $r['order_id'], $r['order_number'], $r['user_id'], $r['user_email'], $r['user_name'], $r['total_amount'], $r['status']);
    }
} catch (Throwable $e) {
    echo "RECENT ORDERS QUERY FAILED: " . $e->getMessage() . "\n";
}

echo "\n=== DONE ===\n";
