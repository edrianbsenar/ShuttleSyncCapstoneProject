<?php
require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/csrf_helper.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: /authentication/login");
    exit;
}

// The dashboard lists live booking/order data. Never let the browser reuse a
// cached copy (including the back-forward cache) after a checkout, otherwise a
// freshly placed order can look "missing" until the next hard refresh.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/includes/database_connect.php';
require_once __DIR__ . '/includes/inventory_helper.php';

inventory_ensure_schema($pdo);

/**
 * A refund may only be requested once the payment has been accepted.
 * Bank transfers are accepted when an admin verifies the payment record.
 * Cash / PayPal / PayMongo payments have no payments row, so a confirmed or
 * completed item is treated as paid (matching the admin "Paid" display).
 */
if (!function_exists('refund_payment_accepted')) {
    function refund_payment_accepted(?string $payment_status, string $item_status): bool {
        if ($payment_status !== null) {
            return $payment_status === 'Verified';
        }
        return in_array($item_status, ['Confirmed', 'Completed', 'Paid', 'Delivered'], true);
    }
}
if (!function_exists('refund_status_label')) {
    // The stored value 'Refunded' is already the customer-facing label.
    function refund_status_label(string $status): string {
        return $status;
    }
}
if (!function_exists('booking_cancel_eligible')) {
    /**
     * Court bookings may only be cancelled at least 24 hours before the
     * scheduled start. Within 24 hours of the slot the action is locked.
     */
    function booking_cancel_eligible(string $status, string $booking_date, string $start_time): bool {
        if (!in_array($status, ['Pending', 'Pending Payment', 'Confirmed', 'Paid'], true)) {
            return false;
        }
        $start = strtotime(trim($booking_date . ' ' . $start_time));
        if ($start === false) return false;
        return ($start - time()) >= 86400;
    }
}

if (!function_exists('booking_refund_window_open')) {
    /**
     * A refund may only be requested for a booking the customer cancelled,
     * and only within 24 hours of that cancellation.
     */
    function booking_refund_window_open(string $status, ?string $cancelled_at): bool {
        if ($status !== 'Cancelled' || empty($cancelled_at)) return false;
        $cancelled = strtotime($cancelled_at);
        if ($cancelled === false) return false;
        $elapsed = time() - $cancelled;
        return $elapsed >= 0 && $elapsed <= 86400;
    }
}

if (!function_exists('booking_refund_deadline')) {
    /** Timestamp when the 24-hour refund request window closes. */
    function booking_refund_deadline(?string $cancelled_at): ?int {
        if (empty($cancelled_at)) return null;
        $ts = strtotime($cancelled_at);
        return $ts === false ? null : $ts + 86400;
    }
}

if (!function_exists('order_cancellable')) {
    /**
     * Shop orders may only be cancelled while the status is still Pending,
     * i.e. before the administrator confirms the order.
     */
    function order_cancellable(string $status): bool {
        return $status === 'Pending';
    }
}

if (!function_exists('booking_refund_requestable')) {
    /** Approved court bookings can be refunded directly from the booking row. */
    function booking_refund_requestable(string $status): bool {
        return in_array($status, ['Confirmed', 'Paid', 'Completed'], true);
    }
}

if (!function_exists('order_refund_requestable')) {
    /** Approved/confirmed shop orders can be refunded from the order row. */
    function order_refund_requestable(string $status): bool {
        return in_array($status, ['Confirmed', 'Processing', 'Completed', 'Delivered', 'Paid'], true);
    }
}

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$role = $_SESSION['role'];
$name_parts = explode(' ', trim($full_name));
$first_name = $name_parts[0];

// Normalize order status into a badge class + label (keeps customer view
// consistent with the admin order workflow).
if (!function_exists('customer_order_badge')) {
    function customer_order_badge(string $status): array {
        switch ($status) {
            case 'Completed':
            case 'Delivered':       return ['bg-green-50 text-green-700 border border-green-200', 'bg-green-500', 'Completed'];
            case 'Processing':
            case 'Confirmed':       return ['bg-blue-50 text-blue-700 border border-blue-200', 'bg-blue-500', 'Processing'];
            case 'Pending':
            case 'Pending Payment': return ['bg-amber-50 text-amber-700 border border-amber-200', 'bg-amber-500', $status === 'Pending Payment' ? 'Awaiting Payment' : 'Pending'];
            case 'Cancelled':       return ['bg-red-50 text-red-700 border border-red-200', 'bg-red-500', 'Cancelled'];
            default:                return ['bg-amber-50 text-amber-700 border border-amber-200', 'bg-amber-500', ucfirst($status)];
        }
    }
}

// AJAX: single order details for the customer "View Order" modal.
// Ownership is checked directly, through the linked payment, or through the
// payment line items so legacy orders still resolve to their buyer.
if (isset($_GET['ajax']) && $_GET['ajax'] === 'order_details') {
    header('Content-Type: application/json; charset=utf-8');
    $oid = (int)($_GET['order_id'] ?? 0);
    if ($oid <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid order.']);
        exit;
    }

    $order = null;
    // Only orders created by this session are additionally allowed through.
    $recent_ajax = array_values(array_unique(array_filter(array_map('intval', $_SESSION['recent_order_ids'] ?? []))));
    $recent_ajax = array_slice($recent_ajax, -20);
    $recent_ajax_sql = '';
    if (!empty($recent_ajax)) {
        $recent_ajax_sql = ' OR o.order_id IN (' . implode(',', array_fill(0, count($recent_ajax), '?')) . ')';
    }
    // Preferred query (uses payment_id when the migration has run).
    try {
        $q = $pdo->prepare("
            SELECT o.order_id, o.order_number, o.order_date, o.total_amount, o.status,
                   o.payment_method, o.payment_reference, p.status AS payment_status
            FROM Orders o
            LEFT JOIN payments p ON o.payment_id = p.payment_id
            WHERE o.order_id = ? AND (
                o.user_id = ?
                OR p.user_id = ?
                OR EXISTS (
                    SELECT 1 FROM payment_items pi
                    JOIN payments pp ON pp.payment_id = pi.payment_id
                    WHERE pi.order_id = o.order_id AND pp.user_id = ?
                )
                $recent_ajax_sql
            )
            LIMIT 1
        ");
        $q->execute(array_merge([$oid, $user_id, $user_id, $user_id], $recent_ajax));
        $order = $q->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Order details query failed: ' . $e->getMessage());
        // Legacy schema without Orders.payment_id / payment_items.
        try {
            $recent_ajax_legacy = '';
            if (!empty($recent_ajax)) {
                $recent_ajax_legacy = ' OR order_id IN (' . implode(',', array_fill(0, count($recent_ajax), '?')) . ')';
            }
            $q = $pdo->prepare("SELECT order_id, order_number, order_date, total_amount, status FROM Orders WHERE order_id = ? AND (user_id = ?$recent_ajax_legacy) LIMIT 1");
            $q->execute(array_merge([$oid, $user_id], $recent_ajax));
            $order = $q->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) {
            $order = null;
        }
    }

    if (!$order) {
        echo json_encode(['ok' => false, 'error' => 'Order not found.']);
        exit;
    }

    $items = [];
    try {
        $it = $pdo->prepare("SELECT description, quantity, amount FROM payment_items WHERE order_id = ? AND item_type = 'shop' ORDER BY payment_item_id ASC");
        $it->execute([$oid]);
        $items = $it->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Fall back to the legacy Order_Items table when present.
        try {
            $it = $pdo->prepare("SELECT product_name AS description, quantity, (price * quantity) AS amount FROM Order_Items WHERE order_id = ? ORDER BY order_item_id ASC");
            $it->execute([$oid]);
            $items = $it->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) {
            $items = [];
        }
    }

    $item_rows = [];
    foreach ($items as $it) {
        $qty = max(1, (int)($it['quantity'] ?? 1));
        $amount = (float)($it['amount'] ?? 0);
        $desc = (string)($it['description'] ?? 'Item');

        // Checkout stores "| Image: <url>" inside the description; split it out
        // so the long URL does not break the table layout.
        $image = null;
        if (preg_match('/\s*\|\s*Image:\s*(https?:\/\/[^\s)]+)/i', $desc, $m)) {
            $image = $m[1];
            $desc = trim(preg_replace('/\s*\|\s*Image:\s*https?:\/\/[^\s)]+/i', '', $desc));
        }

        $item_rows[] = [
            'description' => $desc,
            'image'       => $image,
            'quantity'    => $qty,
            'unit_price'  => round($amount / $qty, 2),
            'amount'      => $amount,
        ];
    }

    $status = (string)($order['status'] ?? 'Pending');
    [$badge_class, $dot_class, $status_label] = customer_order_badge($status);
    echo json_encode([
        'ok'    => true,
        'order' => [
            'id'              => (int)$order['order_id'],
            'number'          => 'ORD-' . str_pad((string)$order['order_id'], 5, '0', STR_PAD_LEFT),
            'reference'       => $order['order_number'] ?? null,
            'date'            => !empty($order['order_date']) ? date('M d, Y', strtotime($order['order_date'])) : '—',
            'status'          => $status,
            'status_label'    => $status_label,
            'badge_class'     => $badge_class,
            'dot_class'       => $dot_class,
            'payment_method'  => $order['payment_method'] ?? null,
            'payment_ref'     => $order['payment_reference'] ?? null,
            'payment_status'  => $order['payment_status'] ?? null,
            'total'           => (float)($order['total_amount'] ?? 0),
        ],
        'items' => $item_rows,
    ]);
    exit;
}

// Handle AJAX skill level update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_skill') {
    verify_csrf_token();
    header('Content-Type: application/json');
    $new_skill = $_POST['skill_level'] ?? '';
    if (in_array($new_skill, ['Beginner', 'Intermediate', 'Advanced', 'Pro'])) {
        $stmt = $pdo->prepare("UPDATE Users SET skill_level = ? WHERE user_id = ?");
        $stmt->execute([$new_skill, $user_id]);
        echo json_encode(['ok' => true, 'skill' => $new_skill]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Invalid skill level']);
    }
    exit;
}

// Handle AJAX cancel booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_booking') {
    verify_csrf_token();
    header('Content-Type: application/json');
    $bid = (int)($_POST['booking_id'] ?? 0);
    if ($bid > 0) {
        // Fetch the booking to enforce ownership and cancellation policy.
        $q = $pdo->prepare("
            SELECT b.booking_id, b.user_id, b.status, b.booking_date, b.start_time, b.end_time, p.status AS payment_status
            FROM Bookings b
            LEFT JOIN payments p ON p.payment_id = b.payment_id
            WHERE b.booking_id = ? AND b.user_id = ?
        ");
        $q->execute([$bid, $user_id]);
        $b = $q->fetch();
        if (!$b) {
            echo json_encode(['ok' => false, 'error' => 'Booking not found.']);
            exit;
        }
        // Enforce ownership and the 24-hour cancellation rule.
        if (!in_array($b['status'], ['Pending', 'Pending Payment', 'Confirmed', 'Paid'], true)) {
            echo json_encode(['ok' => false, 'error' => 'This booking can no longer be cancelled.']);
            exit;
        }
        if (!booking_cancel_eligible($b['status'], $b['booking_date'], $b['start_time'])) {
            echo json_encode(['ok' => false, 'error' => 'Bookings may only be cancelled at least 24 hours before the scheduled start time. This slot is now locked for cancellation.']);
            exit;
        }

        // Migration-safe: make sure the cancellation timestamp exists before
        // recording it.
        foreach (['cancelled_at' => 'DATETIME NULL', 'cancel_reason' => 'VARCHAR(255) NULL'] as $col => $def) {
            try {
                if (!$pdo->query("SHOW COLUMNS FROM Bookings LIKE '$col'")->fetch()) {
                    $pdo->exec("ALTER TABLE Bookings ADD COLUMN `$col` $def");
                }
            } catch (PDOException $e) { /* ignore */ }
        }

        $old_status = $b['status'];
        $stmt = $pdo->prepare("UPDATE Bookings SET status = 'Cancelled', cancelled_at = NOW(), cancel_reason = 'Cancelled by customer' WHERE booking_id = ? AND user_id = ? AND status IN ('Pending','Pending Payment','Confirmed','Paid')");
        $stmt->execute([$bid, $user_id]);
        
        if ($stmt->rowCount() > 0) {
            // Notify player (user cancelled their own booking)
            try {
                require_once __DIR__ . '/includes/notification_helper.php';
                notify_booking_status_change($pdo, $bid, $old_status, 'Cancelled', 'Cancelled by customer');
            } catch (Exception $e) {
                error_log("Notification failed: " . $e->getMessage());
            }
        }
        
        echo json_encode(['ok' => $stmt->rowCount() > 0]);
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

// Handle AJAX cancel order (only while the payment is not yet accepted by the admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    verify_csrf_token();
    header('Content-Type: application/json');
    $oid = (int)($_POST['order_id'] ?? 0);
    if ($oid <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid order.']);
        exit;
    }
    try {
        // Same ownership rules as the "My Orders" list.
        $q = $pdo->prepare("
            SELECT o.order_id, o.status, p.status AS payment_status
            FROM Orders o
            LEFT JOIN payments p ON o.payment_id = p.payment_id
            WHERE o.order_id = ? AND (
                o.user_id = ?
                OR p.user_id = ?
                OR EXISTS (
                    SELECT 1 FROM payment_items pi
                    JOIN payments pp ON pp.payment_id = pi.payment_id
                    WHERE pi.order_id = o.order_id AND pp.user_id = ?
                )
            )
            LIMIT 1
        ");
        $q->execute([$oid, $user_id, $user_id, $user_id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['ok' => false, 'error' => 'Order not found.']);
            exit;
        }
        if (!order_cancellable($row['status'])) {
            echo json_encode(['ok' => false, 'error' => 'This order can no longer be cancelled. Orders may only be cancelled while their status is Pending, before the administrator confirms them.']);
            exit;
        }
        $u = $pdo->prepare("UPDATE Orders SET status = 'Cancelled' WHERE order_id = ? AND status = 'Pending'");
        $u->execute([$oid]);
        $cancelled = $u->rowCount() > 0;

        // Stock was reserved when the order was placed — give it back on cancel.
        if ($cancelled) {
            restore_stock_for_order($pdo, $oid);
        }

        echo json_encode(['ok' => $cancelled, 'error' => $cancelled ? null : 'Could not cancel this order.']);
    } catch (PDOException $e) {
        error_log('Cancel order failed: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Could not cancel this order.']);
    }
    exit;
}

// Handle AJAX refund request (customer side)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_refund') {
    verify_csrf_token();
    header('Content-Type: application/json');
    $order_id   = (int)($_POST['order_id'] ?? 0);
    $booking_id = (int)($_POST['booking_id'] ?? 0);
    $reason     = trim($_POST['reason'] ?? '');
    try {
        if ($reason === '') throw new Exception('Please describe why you are requesting a refund.');
        // The refunds.reason column is VARCHAR(255); trim safely so long
        // "additional details" cannot make the INSERT fail.
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($reason) > 255) { $reason = mb_substr($reason, 0, 252) . '...'; }
        } elseif (strlen($reason) > 255) {
            $reason = substr($reason, 0, 252) . '...';
        }

        // Ensure the proof_path column exists (migration-safe).
        try {
            if (!$pdo->query("SHOW COLUMNS FROM refunds LIKE 'proof_path'")->fetch()) {
                $pdo->exec("ALTER TABLE refunds ADD COLUMN proof_path VARCHAR(255) NULL AFTER reason");
            }
        } catch (PDOException $e) { /* ignore */ }

        if ($order_id > 0 && $booking_id > 0) {
            throw new Exception('Please choose one approved booking or order.');
        }
        if ($order_id <= 0 && $booking_id <= 0) {
            throw new Exception('Please select the approved booking or order you want refunded.');
        }

        $amount = 0.0;
        $payment_id = null;

        if ($order_id > 0) {
            $q = $pdo->prepare("
                SELECT o.order_id, o.total_amount, o.payment_id, o.status, p.status AS payment_status
                FROM Orders o
                LEFT JOIN payments p ON o.payment_id = p.payment_id
                WHERE o.order_id = ? AND (
                    o.user_id = ?
                    OR p.user_id = ?
                    OR EXISTS (
                        SELECT 1 FROM payment_items pi
                        JOIN payments pp ON pp.payment_id = pi.payment_id
                        WHERE pi.order_id = o.order_id AND pp.user_id = ?
                    )
                )
                LIMIT 1
            ");
            $q->execute([$order_id, $user_id, $user_id, $user_id]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Order not found.');
            if (!order_refund_requestable((string)$row['status'])) {
                throw new Exception('Refund requests are available only for approved orders.');
            }
            $amount = (float)$row['total_amount'];
            $payment_id = $row['payment_id'] ?? null;
        } else {
            $q = $pdo->prepare("
                SELECT b.total_price, b.payment_id, b.status, p.status AS payment_status
                FROM Bookings b
                LEFT JOIN payments p ON p.payment_id = b.payment_id
                WHERE b.booking_id = ? AND b.user_id = ?
                LIMIT 1
            ");
            $q->execute([$booking_id, $user_id]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Booking not found.');
            if (!booking_refund_requestable((string)$row['status'])) {
                throw new Exception('Refund requests are available only for approved bookings.');
            }
            $amount = (float)$row['total_price'];
            $payment_id = $row['payment_id'] ?? null;
        }

        if ($amount <= 0) throw new Exception('There is no amount to process.');

        // Proof is required so the admin has something to verify.
        if (!isset($_FILES['proof']) || $_FILES['proof']['error'] === UPLOAD_ERR_NO_FILE) {
            throw new Exception('Please attach proof for your refund request.');
        }
        if ($_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Failed to upload proof file.');
        }
        $size = (int)$_FILES['proof']['size'];
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            throw new Exception('Proof file must be under 5 MB.');
        }
        $ext = null;
        $img = @getimagesize($_FILES['proof']['tmp_name']);
        if ($img) {
            if ($img['mime'] === 'image/jpeg') $ext = 'jpg';
            elseif ($img['mime'] === 'image/png') $ext = 'png';
            elseif ($img['mime'] === 'image/gif') $ext = 'gif';
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $_FILES['proof']['tmp_name']) : '';
            if ($finfo) finfo_close($finfo);
            if ($mime === 'application/pdf') $ext = 'pdf';
        }
        if (!$ext) throw new Exception('Proof must be JPG, PNG, GIF, or PDF.');
        $dir = __DIR__ . '/uploads/refunds';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $filename = 'refund_' . $user_id . '_' . time() . '.' . $ext;
        $dest = $dir . '/' . $filename;
        if (!move_uploaded_file($_FILES['proof']['tmp_name'], $dest)) {
            throw new Exception('Could not save proof file.');
        }
        $proof_path = '/uploads/refunds/' . $filename;

        // Prevent duplicate open requests for the same item.
        if ($order_id > 0) {
            $dup = $pdo->prepare("SELECT COUNT(*) FROM refunds WHERE user_id = ? AND order_id = ? AND status IN ('Pending','Approved','Refunded')");
            $dup->execute([$user_id, $order_id]);
            $label = 'order';
        } else {
            $dup = $pdo->prepare("SELECT COUNT(*) FROM refunds WHERE user_id = ? AND booking_id = ? AND status IN ('Pending','Approved','Refunded')");
            $dup->execute([$user_id, $booking_id]);
            $label = 'booking';
        }
        if ((int)$dup->fetchColumn() > 0) {
            throw new Exception('A refund request for this ' . $label . ' is already in progress.');
        }

        $pdo->prepare("INSERT INTO refunds (user_id, payment_id, order_id, booking_id, amount, reason, proof_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending')")
            ->execute([
                $user_id,
                $payment_id,
                $order_id > 0 ? $order_id : null,
                $booking_id > 0 ? $booking_id : null,
                $amount,
                $reason,
                $proof_path,
            ]);

        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Fetch user data
$stmt = $pdo->prepare("SELECT skill_level, email FROM Users WHERE user_id = :id");
$stmt->execute(['id' => $user_id]);
$user_data = $stmt->fetch();
$skill_level = $user_data['skill_level'] ?? 'Beginner';
$email = $user_data['email'] ?? '';

// Fetch bookings (with created_at for timer display)
$booking_stmt = $pdo->prepare("
    SELECT b.booking_id, b.booking_reference, b.booking_date, b.start_time, b.end_time, b.status, b.total_price, b.player_count, b.created_at, b.cancelled_at, b.payment_id, c.name as court_name,
           p.status as payment_status
    FROM Bookings b
    JOIN Courts c ON b.court_id = c.court_id
    LEFT JOIN payments p ON b.payment_id = p.payment_id
    WHERE b.user_id = :id AND b.status != 'In Cart'
    ORDER BY b.booking_date DESC, b.start_time DESC
");
$booking_stmt->execute(['id' => $user_id]);
$all_bookings = $booking_stmt->fetchAll();

// Fetch orders.
// Ownership is matched directly (Orders.user_id) and also through the payment
// record or its line items, so orders created before user_id linkage was fixed
// still appear for the correct buyer.
// Orders created by this session are always included, so a shop order is
// visible in "My Orders" the moment checkout succeeds.
$all_orders = [];
$recent_order_ids = array_values(array_unique(array_filter(array_map('intval', $_SESSION['recent_order_ids'] ?? []))));
$recent_order_ids = array_slice($recent_order_ids, -20);
$recent_sql = '';
$recent_params = [];
if (!empty($recent_order_ids)) {
    $recent_sql = ' OR o.order_id IN (' . implode(',', array_fill(0, count($recent_order_ids), '?')) . ')';
    $recent_params = $recent_order_ids;
}
try {
    $order_stmt = $pdo->prepare("
        SELECT o.order_id, o.order_date, o.total_amount, o.status, o.payment_method,
               p.status AS payment_status
        FROM Orders o
        LEFT JOIN payments p ON o.payment_id = p.payment_id
        WHERE o.user_id = ?
           OR p.user_id = ?
           OR EXISTS (
                SELECT 1 FROM payment_items pi
                JOIN payments pp ON pp.payment_id = pi.payment_id
                WHERE pi.order_id = o.order_id AND pp.user_id = ?
           )
           $recent_sql
        ORDER BY o.order_date DESC, o.order_id DESC
    ");
    $order_stmt->execute(array_merge([$user_id, $user_id, $user_id], $recent_params));
    $fetched_orders = $order_stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Orders query failed: ' . $e->getMessage());
    // Legacy schema without Orders.payment_id / payment_items.
    $recent_sql_legacy = '';
    if (!empty($recent_order_ids)) {
        $recent_sql_legacy = ' OR order_id IN (' . implode(',', array_fill(0, count($recent_order_ids), '?')) . ')';
    }
    $order_stmt = $pdo->prepare("
        SELECT order_id, order_date, total_amount, status
        FROM Orders
        WHERE user_id = ?$recent_sql_legacy
        ORDER BY order_date DESC, order_id DESC
    ");
    $order_stmt->execute(array_merge([$user_id], $recent_params));
    $fetched_orders = $order_stmt->fetchAll();
}
// De-duplicate (the OR conditions can match the same order more than once).
$seen_orders = [];
foreach ($fetched_orders as $o) {
    $oid = (int)$o['order_id'];
    if (!isset($seen_orders[$oid])) {
        $seen_orders[$oid] = $o;
    }
}
$all_orders = array_values($seen_orders);

// Fetch this customer's refund requests (table added by database_admin_upgrade.sql)
$user_refunds = ['order' => [], 'booking' => []];
try {
    $rs = $pdo->prepare("SELECT order_id, booking_id, status, amount FROM refunds WHERE user_id = ? ORDER BY refund_id DESC");
    $rs->execute([$user_id]);
    foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!empty($r['order_id']))   $user_refunds['order'][(int)$r['order_id']]     = $r;
        if (!empty($r['booking_id'])) $user_refunds['booking'][(int)$r['booking_id']] = $r;
    }
} catch (PDOException $e) { $user_refunds = ['order' => [], 'booking' => []]; }

// Cancellation reasons for this customer's bookings (column added by database_admin_upgrade.sql)
$booking_cancel_reasons = [];
try {
    $cr = $pdo->prepare("SELECT booking_id, cancel_reason FROM Bookings WHERE user_id = ? AND cancel_reason IS NOT NULL AND cancel_reason <> ''");
    $cr->execute([$user_id]);
    foreach ($cr->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $booking_cancel_reasons[(int)$row['booking_id']] = $row['cancel_reason'];
    }
} catch (PDOException $e) { $booking_cancel_reasons = []; }

// Stats
$confirmed_count = 0;
$pending_count = 0;
$total_hours = 0;
$total_spent = 0;
foreach ($all_bookings as $b) {
    if ($b['status'] === 'Confirmed') {
        $confirmed_count++;
        $sh = (int)date('H', strtotime($b['start_time']));
        $eh = (int)date('H', strtotime($b['end_time']));
        $total_hours += max(0, $eh - $sh);
        $total_spent += (float)$b['total_price'];
    } elseif ($b['status'] === 'Pending Payment') $pending_count++;
}

// Fetch AI analysis history (gracefully handle missing table)
$ai_analyses = [];
try {
    $ai_stmt = $pdo->prepare("SELECT log_id, stroke_type, accuracy_score, feedback, analyzed_at FROM AI_Analysis_Logs WHERE user_id = ? ORDER BY analyzed_at DESC LIMIT 20");
    $ai_stmt->execute([$user_id]);
    $ai_analyses = $ai_stmt->fetchAll();
} catch (Exception $e) {}
$ai_count = count($ai_analyses);
$ai_avg = 0;
if ($ai_count > 0) {
    $sum = 0;
    foreach ($ai_analyses as $a) $sum += (int)$a['accuracy_score'];
    $ai_avg = round($sum / $ai_count);
}

// Court availability right now (for widget)
$today_date = date('Y-m-d');
$current_hour_int = (int)date('H');
$availability = [];
try {
    $court_list = $pdo->query("SELECT court_id, name, status FROM Courts ORDER BY court_id ASC")->fetchAll();
    foreach ($court_list as $c) {
        $cstatus = $c['status'] ?? 'Available';
        if ($cstatus === 'Maintenance') { $availability[] = ['name'=>$c['name'],'status'=>'Maintenance','icon'=>'<span class="material-symbols-outlined text-gray-400 text-lg">construction</span>']; continue; }
        $cs = $pdo->prepare("SELECT COUNT(*) FROM Bookings WHERE court_id = ? AND booking_date = ? AND status IN ('Confirmed','Pending Payment') AND start_time <= ? AND end_time > ?");
        $time_now = date('H:i:s');
        $cs->execute([$c['court_id'], $today_date, $time_now, $time_now]);
        $is_booked = $cs->fetchColumn() > 0;
        $availability[] = ['name'=>$c['name'], 'status'=>$is_booked ? 'Occupied' : 'Available', 'icon'=>$is_booked ? '<span class="material-symbols-outlined text-accent text-lg">lock</span>' : '<span class="material-symbols-outlined text-green-500 text-lg">check_circle</span>'];
    }
} catch (Exception $e) {}

// Refundable items for the request page selector (real data).
// Both approved court bookings and approved shop orders can be refunded.
$refundable_items = [];
foreach ($all_bookings as $b) {
    $b_id = (int)$b['booking_id'];
    if (!booking_refund_requestable((string)$b['status'])) continue;
    if (isset($user_refunds['booking'][$b_id])) continue;

    $amount_display = '₱' . number_format((float)$b['total_price'], 2);
    $refundable_items[] = [
        'type' => 'booking',
        'id' => $b_id,
        'label' => 'Booking #' . $b_id . ' — ' . htmlspecialchars($b['court_name']) . ' (' . date('M d, Y', strtotime($b['booking_date'])) . ')',
        'court' => htmlspecialchars($b['court_name']),
        'date' => date('M d, Y', strtotime($b['booking_date'])),
        'amount' => $amount_display,
    ];
}
foreach ($all_orders as $o) {
    $o_id = (int)$o['order_id'];
    if (!order_refund_requestable((string)$o['status'])) continue;
    if (isset($user_refunds['order'][$o_id])) continue;

    $amount_display = '₱' . number_format((float)$o['total_amount'], 2);
    $refundable_items[] = [
        'type' => 'order',
        'id' => $o_id,
        'label' => 'Order #ORD-' . str_pad((string)$o_id, 5, '0', STR_PAD_LEFT) . ' — ' . htmlspecialchars(ucfirst((string)$o['status'])),
        'court' => 'Shop order',
        'date' => !empty($o['order_date']) ? date('M d, Y', strtotime($o['order_date'])) : '—',
        'amount' => $amount_display,
    ];
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>My Dashboard | HI-POWER</title>
    <?php include __DIR__ . '/includes/tailwind_config.php'; ?>
    <style>
        @keyframes fadeSlideUp{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);}}
        .anim-in{animation:fadeSlideUp .5s cubic-bezier(.22,1,.36,1) forwards;}
        .anim-d1{animation-delay:.05s;opacity:0;}
        .anim-d2{animation-delay:.1s;opacity:0;}
        .anim-d3{animation-delay:.15s;opacity:0;}
        .sidebar-btn{transition:all .2s ease;border-left:3px solid transparent;}
        .sidebar-btn.active{background:rgba(255,87,34,0.08);color:#ff5722;border-left-color:#ff5722;font-weight:700;}
        .sidebar-btn.active .material-symbols-outlined{font-variation-settings:'FILL' 1;}
        .tab-card{background:#fff;border-radius:20px;border:1px solid rgba(0,0,0,0.06);overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.04);}
        .booking-row{transition:all .15s ease;border-bottom:1px solid #f0f1f5;}
        .booking-row:last-child{border-bottom:none;}
        .booking-row:hover{background:#f8f9fc;}
        .booking-row:hover .booking-actions{opacity:1;}
        .booking-actions{opacity:0;transition:opacity .2s;}
        @keyframes modalIn{from{opacity:0;transform:scale(0.95) translateY(10px);}to{opacity:1;transform:scale(1) translateY(0);}}
        .modal-animate{animation:modalIn .3s cubic-bezier(.22,1,.36,1) forwards;}
        .skill-chip{padding:10px 16px;border-radius:14px;border:2px solid #e8eaf0;cursor:pointer;transition:all .2s;text-align:center;font-weight:600;font-size:13px;}
        .skill-chip:hover{border-color:#ff5722;background:rgba(255,87,34,0.04);}
        .skill-chip.selected{border-color:#ff5722;background:rgba(255,87,34,0.08);color:#ff5722;}
        /* FIX: Orders desktop list vs mobile cards. These do NOT rely on the
           "hidden" class, because ".ssd .hidden { display:none !important }"
           below would otherwise override Tailwind's md:block on desktop. */
        .orders-desktop { display: none; }
        .orders-mobile  { display: block; }
        @media (min-width: 768px) {
            .orders-desktop { display: block; }
            .orders-mobile  { display: none; }
        }
        /* Scoped mockup styles */
        .ssd { --navy:#0b1521; --navy-2:#111e2c; --orange:#ff4b17; --orange-dark:#d9360c; --ink:#142542; --muted:#70819b; --bg:#f3f7fb; --line:#e2e9f2; --green:#15975b; }
        .ssd * { box-sizing: border-box; }
        .ssd .layout { display: grid; grid-template-columns: 334px minmax(0,1fr); min-height: calc(100vh - 74px); }
        .ssd .sidebar { background: linear-gradient(160deg, #0d1926, #101e2d); color: #d8e4f5; padding: 24px 22px; display: flex; flex-direction: column; gap: 17px; }
        .ssd .profile { display: flex; align-items: center; gap: 16px; border: 1px solid #29384a; border-radius: 17px; padding: 13px; }
        .ssd .avatar { width: 68px; height: 68px; flex-shrink: 0; border-radius: 21px; display: grid; place-items: center; color: white; font-size: 34px; font-weight: 750; background: var(--orange); box-shadow: 0 8px 20px #ff4b172b; }
        .ssd .profile strong { display: block; color: white; font-size: 16px; }
        .ssd .profile small { display: block; color: #9db8d9; font-size: 12px; margin-top: 6px; letter-spacing: .6px; }
        .ssd .stats { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; background: transparent; border: 0; }
.ssd .stat::after { display: none; }
        .ssd .stat { text-align: center; border: 1px solid #29384a; background: #111e2b; border-radius: 15px; padding: 14px 8px 12px; }
        .ssd .stat .symbol { display: block; color: var(--orange); font-size: 23px; font-weight: 750; margin-bottom: 5px; }
        .ssd .stat strong { display: block; color: white; font-size: 19px; }
        .ssd .stat small { display: block; color: #a9bfdc; font-size: 10px; letter-spacing: .4px; margin-top: 4px; }
        .ssd .side-menu { border-top: 1px solid #253448; padding-top: 15px; margin-top: 7px; display: grid; gap: 7px; }
        .ssd .side-menu a, .ssd .logout { display: flex; align-items: center; gap: 15px; padding: 13px 16px; border-radius: 13px; color: #b8cce7; text-decoration: none; font-size: 15px; transition: .2s; }
        .ssd .side-menu a span, .ssd .logout span { font-size: 21px; width: 22px; text-align: center; }
        .ssd .side-menu a:hover { background: #1b2a3c; color: white; }
        .ssd .side-menu a.active { color: white; font-weight: 700; background: linear-gradient(100deg, var(--orange), #c83210); box-shadow: 0 8px 18px #ff4b1726; }
        .ssd .logout { margin-top: auto; border-top: 1px solid #253448; border-radius: 0; padding-top: 23px; color: var(--orange); }
        .ssd main { min-width: 0; padding: 28px clamp(20px, 3vw, 40px) 36px; background: radial-gradient(ellipse at 80% 0%, #e4eff7 0, transparent 33%), var(--bg); }
        .ssd .page-heading { display: flex; justify-content: space-between; align-items: center; gap: 20px; margin-bottom: 24px; }
        .ssd .eyebrow { color: var(--orange); letter-spacing: 3px; font-size: 11px; font-weight: 800; margin-bottom: 8px; }
        .ssd h1 { margin: 0; font-size: clamp(28px, 3vw, 42px); letter-spacing: -1.2px; color: var(--ink); }
        .ssd .primary-btn { border: 0; border-radius: 13px; background: linear-gradient(100deg, var(--orange), #df3b0d); color: white; padding: 15px 23px; font-weight: 750; box-shadow: 0 8px 17px #ff4b172b; transition: transform .2s, box-shadow .2s; cursor: pointer; }
        .ssd .primary-btn:hover { transform: translateY(-2px); box-shadow: 0 12px 22px #ff4b173a; }
        .ssd .panel { background: #fff; border: 1px solid #edf1f6; border-radius: 22px; padding: 24px 27px; margin-bottom: 25px; box-shadow: 0 8px 28px #1a34520a; }
        .ssd .panel-title { display: flex; align-items: flex-start; gap: 14px; margin-bottom: 26px; }
        .ssd .panel-icon { color: var(--orange); font-size: 31px; }
        .ssd .panel-title h2 { font-size: 17px; margin: 2px 0 5px; color: var(--ink); }
        .ssd .panel-title p { margin: 0; color: var(--muted); font-size: 14px; }
        .ssd .text-link { margin-left: auto; color: var(--orange); font-weight: 750; text-decoration: none; font-size: 12px; white-space: nowrap; }
        .ssd .court-list { display: flex; flex-wrap: wrap; gap: 14px; }
        .ssd .court { border: 1px solid #8de8b8; color: #087546; background: #edfcf4; padding: 14px 16px; border-radius: 13px; font-weight: 650; font-size: 14px; }
        .ssd .court:before { content: "✓"; display: inline-grid; place-items: center; width: 21px; height: 21px; border-radius: 50%; background: #35b77b; color: white; margin-right: 10px; font-weight: 900; }
        .ssd .court.unavailable { border-color: #dce4ed; color: #8190a5; background: #eff3f7; }
        .ssd .court.unavailable:before { content: "🔧"; background: transparent; }
        .ssd .empty-state { min-height: 275px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; position: relative; overflow: hidden; background: linear-gradient(180deg, #fff 55%, #fff8f4); }
        .ssd .empty-state:before, .ssd .empty-state:after { content: ""; position: absolute; bottom: -65px; width: 230px; height: 150px; background: #ffede5; opacity: .55; transform: rotate(28deg); border-radius: 50%; }
        .ssd .empty-state:before { left: -80px; }
        .ssd .empty-state:after { right: -90px; }
        .ssd .empty-icon { font-size: 53px; color: #9aaec7; margin-bottom: 13px; z-index: 1; }
        .ssd .empty-state h2 { margin: 0 0 8px; font-size: 21px; z-index: 1; color: var(--ink); }
        .ssd .empty-state p { margin: 0 0 24px; color: var(--muted); z-index: 1; }
        .ssd .empty-state .primary-btn { z-index: 1; }
        .ssd .quick-links { display: grid; grid-template-columns: 1fr 1.1fr; gap: 17px; }
        .ssd .quick-card { border: 1px solid var(--line); background: white; border-radius: 17px; padding: 17px 20px; display: flex; align-items: center; gap: 16px; text-align: left; width: 100%; color: inherit; box-shadow: 0 5px 20px #1a34520a; cursor: pointer; }
        .ssd .quick-card.refund { border-color: #ffd3bf; background: linear-gradient(110deg, #fff9f6, #fff); }
        .ssd .quick-icon { display: grid; place-items: center; width: 67px; height: 67px; flex-shrink: 0; border-radius: 50%; background: #eaf1ff; color: #3178f6; font-size: 29px; }
        .ssd .refund .quick-icon { color: var(--orange); background: #ffede4; }
        .ssd .quick-copy { flex: 1; }
        .ssd .quick-copy strong { display: block; margin-bottom: 5px; font-size: 15px; color: var(--ink); }
        .ssd .quick-copy small { color: var(--muted); line-height: 1.5; font-size: 13px; }
        .ssd .chevron { color: #8194ad; font-size: 26px; }
        .ssd .refund .chevron { color: var(--orange); }
        .ssd .back-btn { border: 0; background: transparent; color: var(--muted); padding: 0 0 18px; font-weight: 650; cursor: pointer; }
        .ssd .refund-layout { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(250px, .8fr); gap: 22px; align-items: start; }
        .ssd .form-panel h2, .ssd .history-panel h2 { margin: 0 0 7px; font-size: 21px; color: var(--ink); }
        .ssd .subtext { color: var(--muted); margin: 0 0 24px; font-size: 14px; line-height: 1.5; }
        .ssd .field { display: grid; gap: 8px; margin-bottom: 18px; }
        .ssd .field label { font-size: 13px; font-weight: 750; color: var(--ink); }
        .ssd .field input, .ssd .field select, .ssd .field textarea { width: 100%; border: 1px solid #dce4ee; border-radius: 11px; background: #fbfcfe; color: var(--ink); padding: 13px 14px; outline: none; }
        .ssd .field input:focus, .ssd .field select:focus, .ssd .field textarea:focus { border-color: var(--orange); box-shadow: 0 0 0 3px #ff4b1717; }
        .ssd .field textarea { min-height: 95px; resize: vertical; }
        .ssd .booking-summary { border: 1px solid var(--line); border-radius: 14px; padding: 17px; margin-bottom: 21px; background: #fbfcfe; }
        .ssd .summary-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 17px; }
        .ssd .summary-head strong { font-size: 15px; color: var(--ink); }
        .ssd .status { display: inline-flex; align-items: center; border-radius: 100px; background: #fff2d9; color: #9b6500; padding: 6px 10px; font-size: 11px; font-weight: 800; white-space: nowrap; }
        .ssd .status.pending { background: #fff2d9; color: #9b6500; }
        .ssd .summary-row { display: flex; justify-content: space-between; gap: 16px; padding: 9px 0; font-size: 13px; }
        .ssd .summary-row span:first-child { color: var(--muted); }
        .ssd .summary-row strong { text-align: right; color: var(--ink); }
        .ssd .policy { display: flex; gap: 11px; align-items: flex-start; background: #fff7e9; border: 1px solid #ffe6b5; border-radius: 12px; padding: 14px; margin: 6px 0 19px; color: #835a13; font-size: 13px; line-height: 1.5; }
        .ssd .policy input { margin-top: 3px; accent-color: var(--orange); }
        .ssd .policy strong { display: block; margin-bottom: 3px; }
        .ssd .form-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; }
        .ssd .secondary-btn { border: 1px solid #dce4ee; color: #43546b; background: white; border-radius: 12px; padding: 14px 19px; font-weight: 700; cursor: pointer; }
        .ssd .notice-box { border-radius: 13px; padding: 15px; background: #f5f8fc; color: #60728a; font-size: 13px; line-height: 1.6; margin-top: 18px; }
        .ssd .history-item { border: 1px solid var(--line); border-radius: 14px; padding: 16px; margin-top: 14px; }
        .ssd .history-item p { margin: 7px 0 13px; color: var(--muted); font-size: 13px; }
        .ssd .history-item .row { display: flex; justify-content: space-between; gap: 10px; align-items: center; }
        .ssd .empty-history { border: 1px dashed #d7e0eb; border-radius: 14px; padding: 22px 15px; color: var(--muted); text-align: center; font-size: 13px; margin-top: 15px; }
        .ssd .success-banner { display: none; border: 1px solid #a6e8c2; background: #effcf4; color: #116c40; border-radius: 12px; padding: 13px 15px; margin-bottom: 18px; font-size: 14px; line-height: 1.5; }
        .ssd .success-banner.show { display: block; }
        .ssd .hidden { display: none !important; }
        @media (max-width: 1000px) {
            .ssd .layout { grid-template-columns: 270px minmax(0,1fr); }
            .ssd .quick-links, .ssd .refund-layout { grid-template-columns: 1fr; }
        }
        @media (max-width: 720px) {
            .ssd .layout { display: block; }
            .ssd .stats { grid-template-columns: repeat(4,1fr); gap: 6px; background: transparent; border: 0; }
            .ssd .stat { padding: 10px 4px; }
            .ssd .stat small { font-size: 8px; }
            .ssd .side-menu { grid-template-columns: repeat(3,1fr); gap: 5px; }
            .ssd .side-menu a { justify-content: center; flex-direction: column; gap: 4px; padding: 9px 4px; font-size: 11px; text-align: center; }
            .ssd .logout { display: none; }
            .ssd main { padding: 20px 13px; }
            .ssd .page-heading { align-items: flex-start; }
            .ssd .page-heading .primary-btn { padding: 12px; font-size: 12px; }
            .ssd .panel { padding: 18px 15px; border-radius: 17px; }
            .ssd .court-list { gap: 8px; }
            .ssd .court { font-size: 12px; padding: 11px 10px; }
            .ssd .court:before { margin-right: 6px; }
            .ssd .quick-card { padding: 13px; }
            .ssd .quick-icon { width: 52px; height: 52px; }
        }
    </style>
</head>
<body class="bg-surface min-h-screen antialiased">

<?php include __DIR__ . '/includes/nav_bar.php'; ?>

<div class="ssd">
    <div class="layout">
        <!-- Desktop Sidebar (mockup style) -->
        <aside class="sidebar">
            <div class="profile">
                <div class="avatar"><?php echo strtoupper(substr($first_name, 0, 1)); ?></div>
                <div>
                    <strong><?php echo htmlspecialchars($full_name); ?></strong>
                    <small id="sidebarSkill"><?php echo htmlspecialchars(strtoupper($skill_level)); ?></small>
                </div>
            </div>
            <div class="stats">
                <div class="stat"><span class="symbol material-symbols-outlined">schedule</span><strong><?php echo $total_hours; ?>h</strong><small>HOURS PLAYED</small></div>
                <div class="stat"><span class="symbol material-symbols-outlined">payments</span><strong>₱<?php echo number_format($total_spent, 0); ?></strong><small>TOTAL SPENT</small></div>
                <div class="stat"><span class="symbol material-symbols-outlined">event</span><strong><?php echo $confirmed_count; ?></strong><small>BOOKINGS</small></div>
                <div class="stat"><span class="symbol material-symbols-outlined">analytics</span><strong><?php echo $ai_count > 0 ? $ai_avg . '%' : '—'; ?></strong><small>AI AVG SCORE</small></div>
            </div>
            <nav class="side-menu" aria-label="Account navigation">
                <a href="#" class="active" id="bookingsNav" onclick="showDashboard(); switchTab('bookings'); return false;"><span class="material-symbols-outlined">calendar_month</span>My Bookings</a>
                <a href="#" id="ordersNav" onclick="showDashboard(); switchTab('orders'); return false;"><span class="material-symbols-outlined">receipt_long</span>My Orders</a>
                <a href="#" id="aiNav" onclick="showDashboard(); switchTab('ai'); return false;"><span class="material-symbols-outlined">smart_toy</span>AI Coach</a>
                <a href="#" id="tipsNav" onclick="showDashboard(); switchTab('tips'); return false;"><span class="material-symbols-outlined">menu_book</span>Tips &amp; Guide</a>
                <a href="#" id="supportNav" onclick="showDashboard(); switchTab('support'); return false;"><span class="material-symbols-outlined">support_agent</span>Support</a>
                <a href="#" id="settingsNav" onclick="showDashboard(); switchTab('settings'); return false;"><span class="material-symbols-outlined">settings</span>Settings</a>
            </nav>
            <a class="logout" href="logout"><span class="material-symbols-outlined">logout</span>Log Out</a>
        </aside>

        <main>
            <!-- DASHBOARD PAGE -->
            <section id="dashboardPage">
                <!-- BOOKINGS TAB -->
                <div id="panel-bookings" class="db-panel block">
                    <div class="flex justify-between items-end mb-6 anim-in anim-d1">
                        <div>
                            <span class="text-accent text-[11px] font-bold tracking-[0.25em] uppercase mb-1 block">Reservations</span>
                            <h1 class="text-2xl md:text-3xl font-bold">My Bookings</h1>
                        </div>
                        <a href="courtbooking" class="bg-gradient-to-r from-accent to-accent-dark text-white px-5 py-2.5 rounded-xl font-bold text-sm flex items-center gap-2 shadow-lg shadow-accent/20 hover:shadow-xl transition active:scale-95">
                            <span class="material-symbols-outlined text-[18px]">add</span> Book Court
                        </a>
                    </div>

                    <?php if (!empty($availability)): ?>
                    <div class="bg-white rounded-2xl border border-outline-variant/20 p-5 mb-6 shadow-sm anim-in anim-d1">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="font-bold text-sm flex items-center gap-2">
                                <span class="material-symbols-outlined text-primary text-lg">stadium</span> Court Availability — Now
                            </h3>
                            <a href="courtbooking" class="text-primary text-[11px] font-bold uppercase tracking-wider hover:underline">Full Schedule →</a>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($availability as $av):
                                $av_cls = $av['status'] === 'Available' ? 'bg-green-50 text-green-700 border-green-200' : ($av['status'] === 'Maintenance' ? 'bg-gray-100 text-gray-500 border-gray-200' : 'bg-red-50 text-accent border-red-200');
                            ?>
                            <div class="flex items-center gap-2 px-3 py-2 rounded-xl border text-xs font-bold <?php echo $av_cls; ?>">
                                <?php echo $av['icon']; ?> <?php echo htmlspecialchars($av['name']); ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>


                    <?php if (count($all_bookings) > 0): ?>
                    <div class="tab-card anim-in anim-d2">
                        <?php foreach ($all_bookings as $i => $booking):
                            $bookDate = date("M d, Y", strtotime($booking['booking_date']));
                            $timeStr = date("h:i A", strtotime($booking['start_time'])) . ' – ' . date("h:i A", strtotime($booking['end_time']));
                            $isPending = $booking['status'] === 'Pending Payment';
                            $hasReceipt = !in_array($booking['status'], ['Cancelled', 'Expired'], true)
                                && (!empty($booking['payment_id']) || !empty($booking['payment_status']) || in_array($booking['status'], ['Pending Payment', 'Pending', 'Confirmed', 'Paid', 'Completed'], true));
                            $isConfirmed = $booking['status'] === 'Confirmed';
                            $isCancelled = $booking['status'] === 'Cancelled' || $booking['status'] === 'Expired';
                            if ($isConfirmed) { $sb = 'bg-green-50 text-green-700 border border-green-200'; $dot = 'bg-green-500'; }
                            elseif ($isPending) { $sb = 'bg-amber-50 text-amber-700 border border-amber-200'; $dot = 'bg-amber-500'; }
                            elseif ($isCancelled) { $sb = 'bg-gray-100 text-gray-500 border border-gray-200'; $dot = 'bg-gray-400'; }
                            else { $sb = 'bg-blue-50 text-blue-700 border border-blue-200'; $dot = 'bg-blue-500'; }
                            $canCancel = booking_cancel_eligible($booking['status'], $booking['booking_date'], $booking['start_time']);
                            $bookingStart = strtotime(trim($booking['booking_date'] . ' ' . $booking['start_time']));
                            $secondsToStart = $bookingStart === false ? null : $bookingStart - time();
                            $activeBooking = in_array($booking['status'], ['Pending', 'Pending Payment', 'Confirmed', 'Paid'], true);
                            $cancelLocked = $activeBooking && !$canCancel && $secondsToStart !== null && $secondsToStart > 0 && $secondsToStart < 86400;
                            $cancelledTs = !empty($booking['cancelled_at']) ? strtotime($booking['cancelled_at']) : false;
                        ?>
                        <div class="booking-row flex items-center gap-4 px-6 py-4">
                            <div class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-primary text-lg">stadium</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <p class="font-bold text-sm text-on-surface truncate"><?php echo htmlspecialchars($booking['court_name']); ?></p>
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider <?php echo $sb; ?>">
                                        <?php echo htmlspecialchars($booking['status']); ?>
                                    </span>
                                </div>
                                <p class="text-xs text-on-surface-variant mt-0.5"><?php echo $bookDate; ?> • <?php echo $timeStr; ?> • ₱<?php echo number_format($booking['total_price'], 2); ?></p>
                                <?php if ($isCancelled && !empty($booking_cancel_reasons[(int)$booking['booking_id']])): ?>
                                    <p class="text-[11px] text-accent mt-0.5"><span class="material-symbols-outlined text-[13px] align-middle mr-0.5">info</span>Cancelled — <?php echo htmlspecialchars($booking_cancel_reasons[(int)$booking['booking_id']]); ?></p>
                                <?php endif; ?>
                                <?php if ($isCancelled && $cancelledTs !== false): ?>
                                    <p class="text-[11px] text-on-surface-variant mt-0.5">Cancelled <?php echo date('M d, Y g:i A', $cancelledTs); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="booking-actions flex items-center gap-2">
                                <?php if ($hasReceipt): ?>
                                    <a href="/receipt.php?booking_id=<?php echo (int)$booking['booking_id']; ?>" class="px-3 py-1.5 inline-flex items-center gap-1 text-[11px] font-bold text-primary hover:underline" title="View your booking receipt">
                                        <span class="material-symbols-outlined text-[14px]">receipt_long</span> View Receipt
                                    </a>
                                <?php endif; ?>
                                <?php if ($canCancel): ?>
                                    <button onclick="cancelBooking(<?php echo $booking['booking_id']; ?>)" class="px-3 py-1.5 text-accent hover:bg-red-50 rounded-lg text-[11px] font-bold uppercase tracking-wider transition">Cancel</button>
                                <?php elseif ($cancelLocked): ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-gray-100 text-gray-500 border border-gray-200" title="Cancellation is locked within 24 hours of the scheduled start, even while payment is pending review">Cancel locked</span>
                                <?php endif; ?>
                                <?php
                                    $bid = (int)$booking['booking_id'];
                                    $brefund = $user_refunds['booking'][$bid] ?? null;
                                ?>
                                <?php if ($brefund): ?>
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-purple-50 text-purple-700 border border-purple-200">Refund: <?php echo htmlspecialchars(refund_status_label($brefund['status'])); ?></span>
                                <?php elseif (booking_refund_requestable((string)$booking['status'])): ?>
                                    <button onclick='openRefundModal({booking_id: <?php echo $bid; ?>})' class="px-3 py-1.5 text-primary hover:bg-primary/5 rounded-lg text-[11px] font-bold uppercase tracking-wider transition" title="Request a refund with proof">Request Refund</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="md:hidden space-y-3 anim-in anim-d2">
                        <?php foreach ($all_bookings as $booking):
                            $bookDate = date("D, M d, Y", strtotime($booking['booking_date']));
                            $timeStr = date("h:i A", strtotime($booking['start_time'])) . ' – ' . date("h:i A", strtotime($booking['end_time']));
                            $isPending = $booking['status'] === 'Pending Payment';
                            $hasReceipt = !in_array($booking['status'], ['Cancelled', 'Expired'], true)
                                && (!empty($booking['payment_id']) || !empty($booking['payment_status']) || in_array($booking['status'], ['Pending Payment', 'Pending', 'Confirmed', 'Paid', 'Completed'], true));
                            $isConfirmed = $booking['status'] === 'Confirmed';
                            $isCancelled = $booking['status'] === 'Cancelled' || $booking['status'] === 'Expired';
                            if ($isConfirmed) { $sb = 'bg-green-50 text-green-700 border border-green-200'; }
                            elseif ($isPending) { $sb = 'bg-amber-50 text-amber-700 border border-amber-200'; }
                            elseif ($isCancelled) { $sb = 'bg-gray-100 text-gray-500 border border-gray-200'; }
                            else { $sb = 'bg-blue-50 text-blue-700 border border-blue-200'; }
                            $canCancel = booking_cancel_eligible($booking['status'], $booking['booking_date'], $booking['start_time']);
                            $bookingStart = strtotime(trim($booking['booking_date'] . ' ' . $booking['start_time']));
                            $secondsToStart = $bookingStart === false ? null : $bookingStart - time();
                            $activeBooking = in_array($booking['status'], ['Pending', 'Pending Payment', 'Confirmed', 'Paid'], true);
                            $cancelLocked = $activeBooking && !$canCancel && $secondsToStart !== null && $secondsToStart > 0 && $secondsToStart < 86400;
                            $cancelledTs = !empty($booking['cancelled_at']) ? strtotime($booking['cancelled_at']) : false;
                        ?>
                        <div class="bg-white rounded-2xl border border-outline-variant/20 p-4 shadow-sm">
                            <div class="flex items-start justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center">
                                        <span class="material-symbols-outlined text-primary text-lg">stadium</span>
                                    </div>
                                    <div>
                                        <p class="font-bold text-sm"><?php echo htmlspecialchars($booking['court_name']); ?></p>
                                        <p class="text-[11px] text-on-surface-variant"><?php echo $bookDate; ?></p>
                                    </div>
                                </div>
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?php echo $sb; ?>"><?php echo htmlspecialchars($booking['status']); ?></span>
                            </div>
                            <?php if ($isCancelled && $cancelledTs !== false): ?>
                                <p class="text-[10px] text-on-surface-variant mt-2">Cancelled <?php echo date('M d, Y g:i A', $cancelledTs); ?></p>
                            <?php endif; ?>
                            <div class="flex items-center justify-between mt-3 pt-3 border-t border-outline-variant/10">
                                <div class="text-xs text-on-surface-variant">
                                    <span class="font-semibold"><?php echo $timeStr; ?></span> • ₱<?php echo number_format($booking['total_price'], 2); ?>
                                </div>
                                <div class="flex gap-2">
                                    <?php if ($hasReceipt): ?>
                                        <a href="/receipt.php?booking_id=<?php echo (int)$booking['booking_id']; ?>" class="px-3 py-1 inline-flex items-center gap-1 text-[11px] font-bold text-primary" title="View your booking receipt">
                                            <span class="material-symbols-outlined text-[14px]">receipt_long</span> View Receipt
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($canCancel): ?>
                                        <button onclick="cancelBooking(<?php echo $booking['booking_id']; ?>)" class="px-3 py-1 text-accent hover:bg-red-50 rounded-lg text-[11px] font-bold">Cancel</button>
                                    <?php elseif ($cancelLocked): ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-gray-100 text-gray-500 border border-gray-200" title="Cancellation is locked within 24 hours of the scheduled start, even while payment is pending review">Cancel locked</span>
                                    <?php endif; ?>
                                    <?php
                                        $bid = (int)$booking['booking_id'];
                                        $brefund = $user_refunds['booking'][$bid] ?? null;
                                    ?>
                                    <?php if ($brefund): ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-purple-50 text-purple-700 border border-purple-200">Refund: <?php echo htmlspecialchars(refund_status_label($brefund['status'])); ?></span>
                                    <?php elseif (booking_refund_requestable((string)$booking['status'])): ?>
                                        <button onclick='openRefundModal({booking_id: <?php echo $bid; ?>})' class="px-3 py-1 text-primary rounded-lg text-[11px] font-bold" title="Request a refund with proof">Refund</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="tab-card p-12 text-center anim-in anim-d2">
                        <span class="material-symbols-outlined text-outline-variant text-5xl mb-3 block">event_busy</span>
                        <p class="text-on-surface-variant mb-4 font-medium">No bookings yet</p>
                        <a href="courtbooking" class="inline-flex items-center gap-2 px-6 py-3 bg-primary text-white rounded-xl font-bold text-sm shadow-lg shadow-primary/20 hover:shadow-xl transition">
                            <span class="material-symbols-outlined text-[18px]">add</span> Book Your First Court
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- Quick Links -->
                    <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-3 anim-in anim-d3">
                        <button class="bg-white rounded-2xl border border-outline-variant/20 p-5 shadow-sm hover:shadow-md transition-all group text-center" onclick="switchTab('tips')">
                            <span class="material-symbols-outlined text-primary text-3xl mb-2 block group-hover:scale-110 transition-transform">school</span>
                            <p class="font-bold text-sm">Tips &amp; Guide</p>
                            <p class="text-[10px] text-on-surface-variant mt-1">Learn how to book, play and improve</p>
                        </button>
                        <button class="bg-white rounded-2xl border border-outline-variant/20 p-5 shadow-sm hover:shadow-md transition-all group text-center" onclick="switchTab('support')">
                            <span class="material-symbols-outlined text-primary text-3xl mb-2 block group-hover:scale-110 transition-transform">support_agent</span>
                            <p class="font-bold text-sm">Contact Support</p>
                            <p class="text-[10px] text-on-surface-variant mt-1">Need help? Message our support team.</p>
                        </button>
                    </div>
                </div>

                <!-- ORDERS TAB -->
                <div id="panel-orders" class="db-panel hidden">
                    <div class="flex justify-between items-end mb-6 anim-in anim-d1">
                        <div>
                            <span class="text-accent text-[11px] font-bold tracking-[0.25em] uppercase mb-1 block">Pro Shop</span>
                            <h1 class="text-2xl md:text-3xl font-bold">My Orders</h1>
                        </div>
                        <a href="ecommerce" class="bg-gradient-to-r from-primary to-primary-dark text-white px-5 py-2.5 rounded-xl font-bold text-sm flex items-center gap-2 shadow-lg shadow-primary/20 hover:shadow-xl transition active:scale-95">
                            <span class="material-symbols-outlined text-[18px]">shopping_bag</span> Shop Gear
                        </a>
                    </div>

                    <?php if (count($all_orders) > 0): ?>
                    <!-- FIX: was "tab-card hidden md:block" (blocked by .ssd .hidden !important) -->
                    <div class="tab-card orders-desktop anim-in anim-d2">
                        <?php foreach ($all_orders as $order):
                            $orderDate = date("M d, Y", strtotime($order['order_date']));
                            [$sb, $dot, $orderLabel] = customer_order_badge($order['status']);
                            $oid = (int)$order['order_id'];
                            $orefund = $user_refunds['order'][$oid] ?? null;
                        ?>
                        <div class="booking-row flex items-center gap-4 px-6 py-4">
                            <div class="w-10 h-10 rounded-xl bg-surface flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-on-surface-variant text-lg">receipt_long</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-bold text-sm text-on-surface">#ORD-<?php echo str_pad($order['order_id'], 5, '0', STR_PAD_LEFT); ?></p>
                                <p class="text-xs text-on-surface-variant mt-0.5"><?php echo $orderDate; ?></p>
                                <?php if (order_cancellable($order['status'])): ?>
                                    <p class="text-[10px] text-amber-700 mt-0.5">Cancellation available until the admin confirms this order.</p>
                                <?php elseif (in_array($order['status'], ['Processing', 'Confirmed', 'Completed', 'Delivered'], true)): ?>
                                    <p class="text-[10px] text-outline mt-0.5">Order confirmed — cancellation is no longer available.</p>
                                <?php endif; ?>
                                <div class="mt-1 flex flex-wrap items-center gap-3">
                                    <button onclick="viewOrder(<?php echo $oid; ?>)" class="inline-flex items-center gap-1 text-[11px] font-bold text-primary hover:underline">
                                        <span class="material-symbols-outlined text-[14px]">visibility</span> View Order
                                    </button>
                                    <?php if (($order['payment_method'] ?? '') === 'pay_at_counter'): ?>
                                    <a href="/receipt.php?order_id=<?php echo $oid; ?>&amp;download=1" class="inline-flex items-center gap-1 text-[11px] font-bold text-primary hover:underline">
                                        <span class="material-symbols-outlined text-[14px]">download</span> Receipt
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="shrink-0 flex items-center gap-2">
                                <?php if (order_cancellable($order['status'])): ?>
                                    <button onclick="cancelOrder(<?php echo $oid; ?>)" class="px-3 py-1.5 text-accent hover:bg-red-50 rounded-lg text-[11px] font-bold uppercase tracking-wider transition" title="Orders can only be cancelled while Pending">Cancel</button>
                                <?php endif; ?>
                                <?php if ($orefund): ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-purple-50 text-purple-700 border border-purple-200">Refund: <?php echo htmlspecialchars(refund_status_label($orefund['status'])); ?></span>
                                <?php elseif (order_refund_requestable((string)$order['status'])): ?>
                                    <button onclick='openRefundModal({order_id: <?php echo $oid; ?>})' class="px-3 py-1.5 text-primary hover:bg-primary/5 rounded-lg text-[11px] font-bold uppercase tracking-wider transition" title="Request a refund with proof">Request Refund</button>
                                <?php endif; ?>
                            </div>
                            <div class="text-right shrink-0">
                                <p class="font-bold text-sm">₱<?php echo number_format($order['total_amount'], 2); ?></p>
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider <?php echo $sb; ?>">
                                    <span class="w-1.5 h-1.5 rounded-full <?php echo $dot; ?>"></span>
                                    <?php echo htmlspecialchars($orderLabel); ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- FIX: was "md:hidden space-y-3" -->
                    <div class="orders-mobile space-y-3 anim-in anim-d2">
                        <?php foreach ($all_orders as $order):
                            $orderDate = date("M d, Y", strtotime($order['order_date']));
                            [$sb, $dot, $orderLabel] = customer_order_badge($order['status']);
                            $oid = (int)$order['order_id'];
                            $orefund = $user_refunds['order'][$oid] ?? null;
                        ?>
                        <div class="bg-white rounded-2xl border border-outline-variant/20 p-4 shadow-sm flex items-center gap-4">
                            <div class="w-10 h-10 rounded-xl bg-surface flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-on-surface-variant">receipt_long</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-bold text-sm">#ORD-<?php echo str_pad($order['order_id'], 5, '0', STR_PAD_LEFT); ?></p>
                                <p class="text-[11px] text-on-surface-variant"><?php echo $orderDate; ?></p>
                                <?php if (order_cancellable($order['status'])): ?>
                                    <p class="text-[10px] text-amber-700 mt-0.5">Cancellation available until the admin confirms this order.</p>
                                <?php elseif (in_array($order['status'], ['Processing', 'Confirmed', 'Completed', 'Delivered'], true)): ?>
                                    <p class="text-[10px] text-outline mt-0.5">Order confirmed — cancellation is no longer available.</p>
                                <?php endif; ?>
                                <div class="mt-1 flex flex-wrap items-center gap-3">
                                    <button onclick="viewOrder(<?php echo $oid; ?>)" class="inline-flex items-center gap-1 text-[11px] font-bold text-primary">
                                        <span class="material-symbols-outlined text-[14px]">visibility</span> View Order
                                    </button>
                                    <?php if (($order['payment_method'] ?? '') === 'pay_at_counter'): ?>
                                    <a href="/receipt.php?order_id=<?php echo $oid; ?>&amp;download=1" class="inline-flex items-center gap-1 text-[11px] font-bold text-primary">
                                        <span class="material-symbols-outlined text-[14px]">download</span> Receipt
                                    </a>
                                    <?php endif; ?>
                                </div>
                                <?php if (order_cancellable($order['status'])): ?>
                                    <button onclick="cancelOrder(<?php echo $oid; ?>)" class="block mt-1 text-[11px] font-bold text-accent" title="Orders can only be cancelled while Pending">Cancel Order</button>
                                <?php endif; ?>
                                <?php if ($orefund): ?>
                                    <span class="inline-block mt-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-purple-50 text-purple-700 border border-purple-200">Refund: <?php echo htmlspecialchars(refund_status_label($orefund['status'])); ?></span>
                                <?php elseif (order_refund_requestable((string)$order['status'])): ?>
                                    <button onclick='openRefundModal({order_id: <?php echo $oid; ?>})' class="block mt-1 text-[11px] font-bold text-primary" title="Request a refund with proof">Request Refund</button>
                                <?php endif; ?>
                            </div>
                            <div class="text-right">
                                <p class="font-bold text-sm">₱<?php echo number_format($order['total_amount'], 2); ?></p>
                                <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold uppercase <?php echo $sb; ?>"><?php echo htmlspecialchars($orderLabel); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="tab-card p-12 text-center anim-in anim-d2">
                        <span class="material-symbols-outlined text-outline-variant text-5xl mb-3 block">inventory_2</span>
                        <p class="text-on-surface-variant mb-4 font-medium">No orders yet</p>
                        <a href="ecommerce" class="inline-flex items-center gap-2 px-6 py-3 bg-primary text-white rounded-xl font-bold text-sm shadow-lg shadow-primary/20 hover:shadow-xl transition">
                            <span class="material-symbols-outlined text-[18px]">shopping_bag</span> Browse the Shop
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- AI COACH TAB -->
                <div id="panel-ai" class="db-panel hidden">
                    <div class="flex justify-between items-end mb-6 anim-in anim-d1">
                        <div>
                            <span class="text-accent text-[11px] font-bold tracking-[0.25em] uppercase mb-1 block">Biomechanics</span>
                            <h1 class="text-2xl md:text-3xl font-bold">AI Coach</h1>
                        </div>
                        <a href="aimovementanalysis" class="bg-gradient-to-r from-primary to-primary-dark text-white px-5 py-2.5 rounded-xl font-bold text-sm flex items-center gap-2 shadow-lg shadow-primary/20 hover:shadow-xl transition active:scale-95">
                            <span class="material-symbols-outlined text-[18px]">add_a_photo</span> New Analysis
                        </a>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6 anim-in anim-d1">
                        <div class="bg-white rounded-2xl border border-outline-variant/20 p-4 text-center shadow-sm">
                            <p class="text-2xl font-bold text-primary"><?php echo $ai_count; ?></p>
                            <p class="text-[10px] font-bold text-on-surface-variant uppercase tracking-wider mt-1">Total Scans</p>
                        </div>
                        <div class="bg-white rounded-2xl border border-outline-variant/20 p-4 text-center shadow-sm">
                            <p class="text-2xl font-bold <?php echo $ai_avg >= 70 ? 'text-green-600' : ($ai_avg >= 50 ? 'text-amber-600' : 'text-accent'); ?>"><?php echo $ai_count > 0 ? $ai_avg.'%' : '—'; ?></p>
                            <p class="text-[10px] font-bold text-on-surface-variant uppercase tracking-wider mt-1">Avg Accuracy</p>
                        </div>
                        <?php
                        $best_score = 0; $best_stroke = '—';
                        foreach ($ai_analyses as $a) { if ((int)$a['accuracy_score'] > $best_score) { $best_score = (int)$a['accuracy_score']; $best_stroke = $a['stroke_type']; } }
                        ?>
                        <div class="bg-white rounded-2xl border border-outline-variant/20 p-4 text-center shadow-sm">
                            <p class="text-2xl font-bold text-primary"><?php echo $best_score > 0 ? $best_score.'%' : '—'; ?></p>
                            <p class="text-[10px] font-bold text-on-surface-variant uppercase tracking-wider mt-1">Best Score</p>
                        </div>
                        <div class="bg-white rounded-2xl border border-outline-variant/20 p-4 text-center shadow-sm">
                            <p class="text-lg font-bold text-on-surface"><?php echo $best_stroke; ?></p>
                            <p class="text-[10px] font-bold text-on-surface-variant uppercase tracking-wider mt-1">Top Stroke</p>
                        </div>
                    </div>

                    <?php if ($ai_count > 0): ?>
                    <div class="tab-card anim-in anim-d2">
                        <div class="px-6 py-4 border-b border-outline-variant/10 flex items-center justify-between">
                            <h3 class="font-bold text-sm">Recent Analyses</h3>
                            <a href="aimovementanalysis" class="text-primary text-[11px] font-bold uppercase tracking-wider hover:underline">Analyze New →</a>
                        </div>
                        <?php foreach ($ai_analyses as $i => $a):
                            $acc = (int)$a['accuracy_score'];
                            $color = $acc >= 75 ? 'text-green-600 bg-green-50 border-green-200' : ($acc >= 50 ? 'text-amber-600 bg-amber-50 border-amber-200' : 'text-accent bg-red-50 border-red-200');
                            $date = $a['analyzed_at'] ? date('M d, Y h:i A', strtotime($a['analyzed_at'])) : '—';
                        ?>
                        <div class="booking-row flex items-center gap-4 px-6 py-4">
                            <div class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-primary text-lg">smart_toy</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="font-bold text-sm text-on-surface"><?php echo htmlspecialchars($a['stroke_type']); ?></p>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold border <?php echo $color; ?>"><?php echo $acc; ?>%</span>
                                </div>
                                <p class="text-xs text-on-surface-variant mt-0.5 truncate"><?php echo htmlspecialchars(mb_strimwidth($a['feedback'] ?? '', 0, 80, '...')); ?></p>
                            </div>
                            <span class="text-[11px] text-on-surface-variant/60 font-medium shrink-0 hidden sm:block"><?php echo $date; ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="tab-card p-12 text-center anim-in anim-d2">
                        <span class="material-symbols-outlined text-outline-variant text-5xl mb-3 block">smart_toy</span>
                        <p class="text-on-surface-variant mb-2 font-medium">No AI analyses yet</p>
                        <p class="text-xs text-on-surface-variant/60 mb-4">Upload a badminton photo to get personalized biomechanical feedback</p>
                        <a href="aimovementanalysis" class="inline-flex items-center gap-2 px-6 py-3 bg-primary text-white rounded-xl font-bold text-sm shadow-lg shadow-primary/20 hover:shadow-xl transition">
                            <span class="material-symbols-outlined text-[18px]">add_a_photo</span> Start Analyzing
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- TIPS & GUIDE TAB -->
                <div id="panel-tips" class="db-panel hidden">
                    <div class="mb-6 anim-in anim-d1">
                        <span class="text-accent text-[11px] font-bold tracking-[0.25em] uppercase mb-1 block">Learn & Improve</span>
                        <h1 class="text-2xl md:text-3xl font-bold">Tips & Guide</h1>
                    </div>

                    <div class="bg-gradient-to-br from-primary to-primary-dark rounded-2xl p-6 text-white mb-6 anim-in anim-d1 relative overflow-hidden">
                        <div class="absolute top-0 right-0 w-32 h-32 bg-white/10 rounded-full -translate-y-1/2 translate-x-1/2"></div>
                        <div class="absolute bottom-0 left-0 w-20 h-20 bg-white/5 rounded-full translate-y-1/2 -translate-x-1/2"></div>
                        <div class="relative z-10">
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-white/20 rounded-full text-[10px] font-bold uppercase tracking-widest mb-3">
                                <span class="material-symbols-outlined text-[14px]">sports_tennis</span> <?php echo htmlspecialchars($skill_level); ?> Level
                            </span>
                            <h2 class="text-xl font-bold mb-2" id="tipTitle">Welcome to Your Training Hub</h2>
                            <p class="text-white/80 text-sm leading-relaxed" id="tipDesc">Personalized tips and techniques based on your skill level to help you improve your game.</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 anim-in anim-d2" id="tipsGrid">
                        <?php
                        $tips = [
                            'Beginner' => [
                                ['icon' => 'sports', 'title' => 'Grip Fundamentals', 'desc' => 'Master the forehand and backhand grip. Hold the racket like you\'re shaking hands — relaxed but firm. The V between thumb and index finger should align with the racket frame.', 'color' => 'bg-blue-50 text-blue-700'],
                                ['icon' => 'directions_run', 'title' => 'Basic Footwork', 'desc' => 'Start with the split step: a small hop as your opponent hits the shuttle. Always return to center court after each shot. Move with small, quick steps — never cross your feet.', 'color' => 'bg-green-50 text-green-700'],
                                ['icon' => 'sports_tennis', 'title' => 'The Clear Shot', 'desc' => 'The most important beginner stroke. Hit the shuttle high and deep to the back of your opponent\'s court. This gives you time to recover position. Focus on a full arm swing with wrist snap.', 'color' => 'bg-purple-50 text-purple-700'],
                                ['icon' => 'fitness_center', 'title' => 'Build Your Base', 'desc' => 'Badminton demands explosive legs and quick direction changes. Start with lunges, calf raises, and shadow footwork drills. 15 minutes daily builds the foundation for advanced play.', 'color' => 'bg-amber-50 text-amber-700'],
                            ],
                            'Intermediate' => [
                                ['icon' => 'sports_martial_arts', 'title' => 'Smash Technique', 'desc' => 'Generate power from your core, not just your arm. Rotate your hips and shoulders, snap your wrist at contact point, and follow through downward. Practice with a high shuttle feed.', 'color' => 'bg-red-50 text-red-700'],
                                ['icon' => 'swap_horiz', 'title' => 'Deception Shots', 'desc' => 'Use identical preparation for different shots. Show a clear, play a drop. Show a drop, play a net lift. The key is maintaining the same arm speed and body position until the last moment.', 'color' => 'bg-indigo-50 text-indigo-700'],
                                ['icon' => 'speed', 'title' => 'Speed & Agility', 'desc' => 'Add interval sprint training: 30 seconds full speed, 30 seconds rest, repeat 10x. Ladder drills improve foot speed. Shadow badminton (without shuttle) builds muscle memory.', 'color' => 'bg-cyan-50 text-cyan-700'],
                                ['icon' => 'psychology', 'title' => 'Match Strategy', 'desc' => 'Attack your opponent\'s backhand. Use clears to push them deep, then drop shots to bring them forward. Change pace often — don\'t let them settle into a rhythm.', 'color' => 'bg-teal-50 text-teal-700'],
                            ],
                            'Advanced' => [
                                ['icon' => 'precision_manufacturing', 'title' => 'Net Kill Precision', 'desc' => 'Train your net kills with a shuttle on the tape. Practice hitting downward at sharp angles. The key is a firm wrist with minimal backswing — snap and recover instantly.', 'color' => 'bg-orange-50 text-orange-700'],
                                ['icon' => 'groups', 'title' => 'Doubles Tactics', 'desc' => 'In doubles, maintain front-back attack formation when smashing, side-by-side when defending. Communicate with your partner. The server should follow the serve to the net immediately.', 'color' => 'bg-violet-50 text-violet-700'],
                                ['icon' => 'monitor_heart', 'title' => 'Recovery & Endurance', 'desc' => 'At this level, matches are won on endurance. Incorporate court sprints (6 corners), resistance band training, and yoga for flexibility. Sleep 7-8 hours minimum on training days.', 'color' => 'bg-rose-50 text-rose-700'],
                                ['icon' => 'psychology_alt', 'title' => 'Mental Game', 'desc' => 'Develop a between-point routine: breathe, bounce the shuttle, plan the next rally. Stay present — don\'t dwell on errors. Visualize your shots before playing them in pressure situations.', 'color' => 'bg-emerald-50 text-emerald-700'],
                            ],
                            'Pro' => [
                                ['icon' => 'emoji_events', 'title' => 'Game Reading', 'desc' => 'At pro level, anticipate before your opponent strikes. Study their body language, racket angle, and weight distribution. Train by watching matches and predicting shot selection.', 'color' => 'bg-yellow-50 text-yellow-700'],
                                ['icon' => 'tune', 'title' => 'Equipment Optimization', 'desc' => 'Racket string tension, grip size, and shuttle speed all matter at this level. Experiment with higher tensions (28-32 lbs) for control, lower for power. Use BG65 or BG66 strings.', 'color' => 'bg-pink-50 text-pink-700'],
                                ['icon' => 'science', 'title' => 'Periodized Training', 'desc' => 'Structure your training in cycles: off-season (volume), pre-season (intensity), competition (maintenance). Each phase has different load, recovery, and nutrition requirements.', 'color' => 'bg-sky-50 text-sky-700'],
                                ['icon' => 'biotech', 'title' => 'Injury Prevention', 'desc' => 'At pro intensity, injuries are the biggest threat. Warm up 15+ minutes, use ankle braces if needed, ice after sessions, and get regular sports massage. Don\'t play through pain.', 'color' => 'bg-lime-50 text-lime-700'],
                            ],
                        ];
                        $level_tips = $tips[$skill_level] ?? $tips['Beginner'];
                        foreach ($level_tips as $tip):
                        ?>
                        <div class="bg-white rounded-2xl border border-outline-variant/20 p-5 shadow-sm hover:shadow-md transition-shadow">
                            <div class="flex items-start gap-3 mb-3">
                                <div class="w-10 h-10 rounded-xl <?php echo $tip['color']; ?> flex items-center justify-center shrink-0">
                                    <span class="material-symbols-outlined text-lg"><?php echo $tip['icon']; ?></span>
                                </div>
                                <div>
                                    <h3 class="font-bold text-sm text-on-surface"><?php echo $tip['title']; ?></h3>
                                </div>
                            </div>
                            <p class="text-xs text-on-surface-variant leading-relaxed"><?php echo $tip['desc']; ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-6 grid grid-cols-1 sm:grid-cols-3 gap-3 anim-in anim-d3">
                        <a href="aimovementanalysis" class="bg-white rounded-2xl border border-outline-variant/20 p-5 shadow-sm hover:shadow-md transition-all group text-center">
                            <span class="material-symbols-outlined text-primary text-3xl mb-2 block group-hover:scale-110 transition-transform">smart_toy</span>
                            <p class="font-bold text-sm">AI Swing Analysis</p>
                            <p class="text-[10px] text-on-surface-variant mt-1">Get real-time biomechanical feedback</p>
                        </a>
                        <a href="courtbooking" class="bg-white rounded-2xl border border-outline-variant/20 p-5 shadow-sm hover:shadow-md transition-all group text-center">
                            <span class="material-symbols-outlined text-accent text-3xl mb-2 block group-hover:scale-110 transition-transform">stadium</span>
                            <p class="font-bold text-sm">Book a Court</p>
                            <p class="text-[10px] text-on-surface-variant mt-1">Reserve your training slot</p>
                        </a>
                        <a href="ecommerce" class="bg-white rounded-2xl border border-outline-variant/20 p-5 shadow-sm hover:shadow-md transition-all group text-center">
                            <span class="material-symbols-outlined text-green-600 text-3xl mb-2 block group-hover:scale-110 transition-transform">shopping_bag</span>
                            <p class="font-bold text-sm">Pro Shop</p>
                            <p class="text-[10px] text-on-surface-variant mt-1">Browse rackets, shoes & gear</p>
                        </a>
                    </div>
                </div>

                <!-- SETTINGS TAB -->
                <div id="panel-settings" class="db-panel hidden">
                    <div class="mb-6 anim-in anim-d1">
                        <span class="text-accent text-[11px] font-bold tracking-[0.25em] uppercase mb-1 block">Account</span>
                        <h1 class="text-2xl md:text-3xl font-bold">Settings</h1>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 anim-in anim-d2">
                        <div class="bg-white rounded-2xl border border-outline-variant/20 p-6 shadow-sm">
                            <h3 class="font-bold text-sm mb-5 flex items-center gap-2">
                                <span class="material-symbols-outlined text-primary text-lg">person</span> Profile Information
                            </h3>
                            <div class="space-y-4">
                                <div>
                                    <label class="text-[10px] font-bold text-on-surface-variant uppercase tracking-widest block mb-1.5">Full Name</label>
                                    <input type="text" value="<?php echo htmlspecialchars($full_name); ?>" class="w-full px-4 py-3 bg-surface rounded-xl border border-outline-variant/30 text-sm font-semibold" readonly>
                                </div>
                                <div>
                                    <label class="text-[10px] font-bold text-on-surface-variant uppercase tracking-widest block mb-1.5">Email Address</label>
                                    <input type="email" value="<?php echo htmlspecialchars($email); ?>" class="w-full px-4 py-3 bg-surface rounded-xl border border-outline-variant/30 text-sm font-semibold opacity-60 cursor-not-allowed" disabled>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white rounded-2xl border border-outline-variant/20 p-6 shadow-sm">
                            <h3 class="font-bold text-sm mb-5 flex items-center gap-2">
                                <span class="material-symbols-outlined text-primary text-lg">sports_tennis</span> Skill Level
                            </h3>
                            <div class="grid grid-cols-2 gap-3" id="skillGrid">
                                <?php foreach (['Beginner', 'Intermediate', 'Advanced', 'Pro'] as $level):
                                    $icons = ['Beginner' => 'sports', 'Intermediate' => 'sports_soccer', 'Advanced' => 'sports_martial_arts', 'Pro' => 'emoji_events'];
                                ?>
                                <div class="skill-chip <?php echo $skill_level === $level ? 'selected' : ''; ?>" onclick="setSkill('<?php echo $level; ?>')" data-skill="<?php echo $level; ?>">
                                    <span class="material-symbols-outlined text-lg block mb-1 <?php echo $skill_level === $level ? 'text-primary' : 'text-on-surface-variant'; ?>"><?php echo $icons[$level]; ?></span>
                                    <?php echo $level; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <p class="text-[10px] text-on-surface-variant mt-4 font-medium" id="skillMsg"></p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- SUPPORT TAB -->
            <div id="panel-support" class="db-panel hidden">
                <div class="mb-6 anim-in anim-d1">
                    <span class="text-accent text-[11px] font-bold tracking-[0.25em] uppercase mb-1 block">Support</span>
                    <h1 class="text-2xl md:text-3xl font-bold">Support</h1>
                </div>

                <div class="grid grid-cols-1 gap-6 anim-in anim-d2">
                    <div class="bg-white rounded-2xl border border-outline-variant/20 p-6 shadow-sm">
                        <h3 class="font-bold text-sm mb-2 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary text-lg">headset</span> Contact Support
                        </h3>
                        <p class="text-xs text-on-surface-variant mb-4">Send us a message or email us directly at <a href="mailto:support@hipowerbc.xyz" class="text-primary font-semibold hover:underline">support@hipowerbc.xyz</a>. For urgent booking or payment issues, include your booking or order reference.</p>
                        <a href="support.php?tab=contact" class="bg-white rounded-2xl border border-outline-variant/20 px-5 py-2.5 shadow-sm hover:shadow-md transition active:scale-95 text-primary font-bold text-sm flex items-center gap-2">
                            <span class="material-symbols-outlined text-[18px]">mail</span> Contact Support Center
                        </a>
                    </div>
                </div>
            </div>

            <!-- REFUND REQUEST PAGE -->
            <section id="refundPage" class="hidden">
                        <button class="back-btn" onclick="showDashboard()"><span class="material-symbols-outlined text-[16px]">arrow_back</span> Back to My Bookings</button>
                <div class="page-heading">
                    <div><div class="eyebrow">PAYMENTS &amp; CANCELLATIONS</div><h1>Refund Request</h1></div>
                </div>
                <div class="refund-layout">
                    <section class="panel form-panel">
                        <h2>Request Details</h2>
                        <p class="subtext">Select an approved booking or order, explain the issue, and attach proof. The admin will review and coordinate the refund.</p>
                        <div class="notice-box" style="margin-bottom:16px; border-left:4px solid #ff5722; background:#fff7f3;">
                            <strong>Refund Policy</strong><br>
                            • Refund requests may be submitted only for <strong>approved bookings</strong> or <strong>confirmed orders</strong>.<br>
                            • A <strong>proof file is required</strong> (receipt, screenshot, or other supporting document).<br>
                            • Approved and verified requests qualify for a <strong>100% full refund</strong> of the amount paid, processed manually after admin review.
                        </div>
                        <div id="successBanner" class="success-banner" role="status"></div>
                        <div class="booking-summary">
                            <div class="summary-head"><strong>Selected Item</strong><span class="status">Approved</span></div>
                            <?php $firstRefundable = !empty($refundable_items) ? $refundable_items[0] : null; ?>
                            <div class="summary-row"><span>Item</span><strong id="summaryBooking"><?php echo $firstRefundable ? htmlspecialchars($firstRefundable['label']) : '—'; ?></strong></div>
                            <div class="summary-row"><span>Details</span><strong id="summaryCourt"><?php echo $firstRefundable ? htmlspecialchars($firstRefundable['court']) : '—'; ?></strong></div>
                            <div class="summary-row"><span>Date</span><strong id="summaryDate"><?php echo $firstRefundable ? htmlspecialchars($firstRefundable['date']) : '—'; ?></strong></div>
                            <div class="summary-row"><span>Amount paid</span><strong id="summaryAmount"><?php echo $firstRefundable ? htmlspecialchars($firstRefundable['amount']) : '—'; ?></strong></div>
                            <div class="summary-row"><span>Refund amount (100%)</span><strong id="summaryRefund" style="color:#ff5722;"><?php echo $firstRefundable ? htmlspecialchars($firstRefundable['amount']) : '—'; ?></strong></div>
                        </div>
                        <form id="refundForm" enctype="multipart/form-data">
                            <?php echo get_csrf_input(); ?>
                            <input type="hidden" name="order_id" id="refund_order_id" value="0">
                            <input type="hidden" name="booking_id" id="refund_booking_id" value="0">
                            <div class="field">
                                <label for="bookingId">Approved booking or order</label>
                                <select id="bookingId" name="bookingId" required>
                                    <?php if (empty($refundable_items)): ?>
                                        <option value="">No approved bookings or orders available</option>
                                    <?php else: ?>
                                        <?php foreach ($refundable_items as $it): ?>
                                            <option value="<?php echo $it['type'] . ':' . $it['id']; ?>" data-type="<?php echo $it['type']; ?>" data-id="<?php echo $it['id']; ?>" data-court="<?php echo htmlspecialchars($it['court']); ?>" data-date="<?php echo htmlspecialchars($it['date']); ?>" data-amount="<?php echo htmlspecialchars($it['amount']); ?>"><?php echo $it['label']; ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="reason">Reason for request</label>
                                <select id="reason" name="reason" required>
                                    <option value="">Select a reason</option>
                                    <option value="Change of plans">Change of plans</option>
                                    <option value="Unable to attend">Unable to attend</option>
                                    <option value="Personal emergency">Personal emergency</option>
                                    <option value="Item or order issue">Item or order issue</option>
                                    <option value="Duplicate payment">Duplicate payment</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="details">Additional details / explanation <span style="font-weight:400;color:#8190a5">(optional)</span></label>
                                <textarea id="details" name="details" maxlength="250" placeholder="Explain why you are requesting a refund..."></textarea>
                            </div>
                            <div class="field">
                                <label for="refund_proof">Proof (required)</label>
                                <input type="file" id="refund_proof" name="proof" accept="image/jpeg,image/png,image/gif,application/pdf" required>
                                <p class="text-[10px] text-outline mt-1">JPG, PNG, GIF or PDF, max 5 MB (receipt/screenshot).</p>
                            </div>
                            <label class="policy">
                                <input type="checkbox" id="policyCheck" required>
                                <span><strong>Policy Confirmation</strong> I understand refunds are available only for approved bookings or confirmed orders, that proof is required, and that approved and verified requests receive a <strong>100% full refund</strong> of the amount paid. Submitting a request does not guarantee a refund and is subject to admin review and manual reimbursement.</span>
                            </label>
                            <div class="form-actions">
                                <button class="primary-btn" type="button" onclick="submitRefund()">Submit Request</button>
                                <button class="secondary-btn" type="button" onclick="showDashboard()">Cancel</button>
                            </div>
                        </form>
                    </section>

                    <aside class="panel history-panel">
                        <h2>My Requests</h2>
                        <p class="subtext">Track the status of requests you have submitted.</p>
                        <div id="refundHistory">
                            <?php
                            $any_refund = false;
                            foreach ($user_refunds['booking'] as $id => $r) {
                                $any_refund = true;
                                $status_label = refund_status_label($r['status']);
                                echo '<div class="history-item"><div class="row"><strong>' . htmlspecialchars('Booking #' . $id) . '</strong><span class="status pending">' . htmlspecialchars($status_label) . '</span></div><p>Amount: ₱' . number_format((float)$r['amount'], 2) . '</p><div class="row"><span style="color:#70819b;font-size:12px">Status</span><strong style="font-size:12px;color:#9b6500">' . htmlspecialchars($status_label) . '</strong></div></div>';
                            }
                            foreach ($user_refunds['order'] as $id => $r) {
                                $any_refund = true;
                                $status_label = refund_status_label($r['status']);
                                echo '<div class="history-item"><div class="row"><strong>' . htmlspecialchars('Order #ORD-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT)) . '</strong><span class="status pending">' . htmlspecialchars($status_label) . '</span></div><p>Amount: ₱' . number_format((float)$r['amount'], 2) . '</p><div class="row"><span style="color:#70819b;font-size:12px">Status</span><strong style="font-size:12px;color:#9b6500">' . htmlspecialchars($status_label) . '</strong></div></div>';
                            }
                            if (!$any_refund) {
                                echo '<div class="empty-history">No requests submitted yet.</div>';
                            }
                            ?>
                        </div>
                        <div class="notice-box"><strong>Policy reminder</strong><br>• Refund requests are available for <strong>approved bookings</strong> and <strong>confirmed orders</strong>.<br>• A <strong>proof file is required</strong> with every request.<br>• Approved and verified requests receive a <strong>100% full refund</strong> of the amount paid.<br><br><strong>What happens next?</strong><br>1. You submit a request with proof.<br>2. The admin reviews the reason and payment.<br>3. The status is updated after review and reimbursement is coordinated manually.</div>
                    </aside>
                </div>
            </section>
        </main>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php include __DIR__ . '/includes/mobile_nav.php'; ?>

<!-- Logout Modal -->
<div id="logoutModal" class="hidden fixed inset-0 z-[200] flex items-center justify-center px-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="toggleLogout()"></div>
    <div class="relative bg-white w-full max-w-sm rounded-3xl shadow-2xl overflow-hidden modal-animate" id="logoutContent">
        <div class="p-8 text-center">
            <div class="w-16 h-16 bg-red-50 text-accent rounded-2xl flex items-center justify-center mx-auto mb-4 border border-red-100">
                <span class="material-symbols-outlined text-3xl">logout</span>
            </div>
            <h3 class="text-xl font-bold mb-2">Ready to leave?</h3>
            <p class="text-sm text-on-surface-variant mb-8">You'll need to log in again to access your dashboard.</p>
            <div class="flex gap-3">
                <button onclick="toggleLogout()" class="flex-1 py-3 bg-surface border border-outline-variant/30 text-on-surface-variant rounded-xl font-bold text-sm hover:bg-surface-container-low transition">Cancel</button>
                <a href="logout" class="flex-1 py-3 bg-accent text-white rounded-xl font-bold text-sm hover:shadow-lg transition text-center">Log Out</a>
            </div>
        </div>
    </div>
</div>

<!-- Cancel Confirmation Modal (bookings + orders) -->
<div id="cancelModal" class="hidden fixed inset-0 z-[200] items-center justify-center px-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeCancelModal()"></div>
    <div class="relative bg-white w-full max-w-sm rounded-3xl shadow-2xl overflow-hidden modal-animate" role="dialog" aria-modal="true" aria-labelledby="cancelTitle">
        <div class="p-8 text-center">
            <div class="w-16 h-16 bg-red-50 text-accent rounded-2xl flex items-center justify-center mx-auto mb-4 border border-red-100">
                <span class="material-symbols-outlined text-3xl">cancel</span>
            </div>
            <h3 class="text-xl font-bold mb-2" id="cancelTitle">Cancel this booking?</h3>
            <p class="text-sm text-on-surface-variant mb-2" id="cancelMsg">This action cannot be undone.</p>
            <p class="text-xs text-on-surface-variant/70 mb-6" id="cancelRef"></p>
            <p class="hidden text-xs font-bold text-red-600 mb-4" id="cancelError"></p>
            <div class="flex gap-3">
                <button type="button" id="cancelKeepBtn" onclick="closeCancelModal()" class="flex-1 py-3 bg-surface border border-outline-variant/30 text-on-surface-variant rounded-xl font-bold text-sm hover:bg-surface-container-low transition">Keep it</button>
                <button type="button" id="cancelConfirmBtn" onclick="confirmCancel()" class="flex-1 py-3 bg-accent text-white rounded-xl font-bold text-sm hover:shadow-lg transition flex items-center justify-center gap-2">
                    <span id="cancelConfirmLabel">Yes, cancel</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Order Details Modal -->
<div id="orderDetailsModal" class="hidden fixed inset-0 z-[200] items-center justify-center px-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeOrderDetails()"></div>
    <div class="relative bg-white w-full max-w-lg rounded-3xl shadow-2xl overflow-hidden modal-animate" role="dialog" aria-modal="true" aria-labelledby="odTitle">
        <div class="flex items-start justify-between px-6 py-4 border-b border-outline-variant/30">
            <div class="min-w-0">
                <h3 class="font-bold text-on-surface" id="odTitle">Order details</h3>
                <p class="text-xs text-outline mt-0.5" id="odSub">&nbsp;</p>
            </div>
            <button onclick="closeOrderDetails()" class="w-9 h-9 rounded-lg hover:bg-surface-container flex items-center justify-center text-on-surface-variant shrink-0" aria-label="Close">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="px-6 py-5 max-h-[60vh] overflow-y-auto" id="odBody">
            <div class="flex items-center justify-center py-10 text-outline">
                <span class="material-symbols-outlined animate-spin mr-2">progress_activity</span> Loading…
            </div>
        </div>
        <div class="px-6 py-4 border-t border-outline-variant/30 flex items-center justify-between bg-surface-bright">
            <span class="text-sm font-bold text-on-surface-variant">Total</span>
            <span class="text-lg font-bold text-primary" id="odTotal">₱0.00</span>
        </div>
    </div>
</div>

<script>
// Tab Switching
const allTabs = ['bookings', 'orders', 'ai', 'tips', 'support', 'settings'];
function switchTab(tab) {
    document.querySelectorAll('.db-panel').forEach(p => p.classList.add('hidden'));
    const panel = document.getElementById('panel-' + tab);
    if (panel) panel.classList.remove('hidden');
    document.querySelectorAll('.side-menu a').forEach(a => a.classList.remove('active'));
    const navMap = { bookings: 'bookingsNav', orders: 'ordersNav', ai: 'aiNav', tips: 'tipsNav', support: 'supportNav', settings: 'settingsNav' };
    const sideNav = document.getElementById(navMap[tab] || '');
    if (sideNav) sideNav.classList.add('active');
    document.querySelectorAll('.mob-tab').forEach(b => {
        b.classList.remove('active', 'border-primary', 'text-primary');
        b.classList.add('border-transparent', 'text-on-surface-variant');
    });
    const mobTab = document.getElementById('mob-tab-' + tab);
    if (mobTab) {
        mobTab.classList.add('active', 'border-primary', 'text-primary');
        mobTab.classList.remove('border-transparent', 'text-on-surface-variant');
    }
}

// Dashboard / Refund page toggle (mockup)
const dashboardPage = document.getElementById('dashboardPage');
const refundPage = document.getElementById('refundPage');
function showDashboard() {
    document.querySelectorAll('.side-menu a').forEach(a => a.classList.remove('active'));
    if (dashboardPage) dashboardPage.classList.remove('hidden');
    if (refundPage) refundPage.classList.add('hidden');
    const bookingsNav = document.getElementById('bookingsNav');
    if (bookingsNav) bookingsNav.classList.add('active');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function showRefund() {
    document.querySelectorAll('.side-menu a').forEach(a => a.classList.remove('active'));
    if (dashboardPage) dashboardPage.classList.add('hidden');
    if (refundPage) refundPage.classList.remove('hidden');
    const sb = document.getElementById('summaryBooking');
    const bi = document.getElementById('bookingId');
    if (sb && bi) sb.textContent = bi.options[bi.selectedIndex] ? bi.options[bi.selectedIndex].textContent.trim() : '';
    const sbanner = document.getElementById('successBanner');
    if (sbanner) sbanner.classList.remove('show');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
// Refund form elements
const refundForm = document.getElementById('refundForm');
const bookingId = document.getElementById('bookingId');
const reason = document.getElementById('reason');
const details = document.getElementById('details');
const policyCheck = document.getElementById('policyCheck');
const refundHistory = document.getElementById('refundHistory');
const successBanner = document.getElementById('successBanner');
const summaryBooking = document.getElementById('summaryBooking');

let submittedRequests = [];

function renderRequests() {
    if (!refundHistory) return;
    refundHistory.innerHTML = '';
    if (submittedRequests.length === 0) {
        refundHistory.innerHTML = '<div class="empty-history">No refund requests submitted yet.</div>';
        return;
    }
    submittedRequests.forEach(request => {
        const item = document.createElement('div');
        item.className = 'history-item';
        item.innerHTML = `
            <div class="row"><strong>${escapeHtml(request.id)}</strong><span class="status pending">${escapeHtml(request.status)}</span></div>
            <p>Item: ${escapeHtml(request.booking)}<br>Reason: ${escapeHtml(request.reason)}<br>Submitted: ${escapeHtml(request.date)}</p>
            <div class="row"><span style="color:#70819b;font-size:12px">Waiting for admin review</span><strong style="font-size:12px;color:#9b6500">Pending</strong></div>`;
        refundHistory.appendChild(item);
    });
}
function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, char => ({
        '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'
    }[char]));
}

function updateRefundSummary() {
    if (!bookingId) return;
    const opt = bookingId.options[bookingId.selectedIndex];
    const type = opt ? (opt.getAttribute('data-type') || '') : '';
    const id = opt ? (opt.getAttribute('data-id') || '') : '';
    const ro = document.getElementById('refund_order_id');
    const rb = document.getElementById('refund_booking_id');
    if (ro) ro.value = (type === 'order') ? (id || 0) : 0;
    if (rb) rb.value = (type === 'booking') ? (id || 0) : 0;
    if (summaryBooking && opt) summaryBooking.textContent = opt.textContent.trim();
    const sc = document.getElementById('summaryCourt');
    const sd = document.getElementById('summaryDate');
    const sa = document.getElementById('summaryAmount');
    const sr = document.getElementById('summaryRefund');
    const sdl = document.getElementById('summaryDeadline');
    if (sc && opt) sc.textContent = opt.getAttribute('data-court') || '—';
    if (sd && opt) sd.textContent = opt.getAttribute('data-date') || '—';
    if (sa && opt) sa.textContent = opt.getAttribute('data-amount') || '—';
    // Approved and verified requests receive a 100% full refund.
    if (sr && opt) sr.textContent = opt.getAttribute('data-amount') || '—';
    if (sdl && opt) sdl.textContent = opt.getAttribute('data-deadline') || '—';
}
if (bookingId) {
    bookingId.addEventListener('change', updateRefundSummary);
    updateRefundSummary();
}

// Skill Level Update
function setSkill(level) {
    document.querySelectorAll('.skill-chip').forEach(c => c.classList.remove('selected'));
    const chip = document.querySelector('.skill-chip[data-skill="'+level+'"]');
    if (chip) chip.classList.add('selected');
    const msg = document.getElementById('skillMsg');
    if (msg) msg.textContent = '';
    const fd = new FormData();
    fd.append('action', 'update_skill');
    fd.append('skill_level', level);
    fd.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>');
    fetch('/playerdashboard', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                if (msg) { msg.textContent = '✓ Skill level updated'; msg.className = 'text-[10px] text-green-600 font-bold mt-4'; }
                const ss = document.getElementById('sidebarSkill');
                if (ss) ss.textContent = level;
                setTimeout(() => { if (msg) msg.textContent = ''; }, 3000);
            }
        });
}

// Cancel Booking
function cancelBooking(bid) { openCancelModal('booking', bid); }

// Cancel Order (only unpaid orders)
function cancelOrder(oid) { openCancelModal('order', oid); }

// ---- Shared cancellation modal ----
let pendingCancel = null;
function openCancelModal(type, id) {
    pendingCancel = { type: type, id: id };
    const isOrder = type === 'order';
    document.getElementById('cancelTitle').textContent = isOrder ? 'Cancel this order?' : 'Cancel this booking?';
    document.getElementById('cancelMsg').textContent = isOrder
        ? 'Orders can only be cancelled while the status is Pending, before the administrator confirms them. This cannot be undone.'
        : 'Bookings can only be cancelled at least 24 hours before the scheduled start. This cannot be undone.';
    document.getElementById('cancelRef').textContent = isOrder
        ? 'Order #ORD-' + String(id).padStart(5, '0')
        : 'Booking #' + id;
    const err = document.getElementById('cancelError');
    err.textContent = '';
    err.classList.add('hidden');
    setCancelBusy(false);
    const m = document.getElementById('cancelModal');
    m.classList.remove('hidden');
    m.classList.add('flex');
}
function closeCancelModal() {
    const m = document.getElementById('cancelModal');
    if (!m) return;
    m.classList.add('hidden');
    m.classList.remove('flex');
    pendingCancel = null;
}
function setCancelBusy(busy) {
    const btn = document.getElementById('cancelConfirmBtn');
    const keep = document.getElementById('cancelKeepBtn');
    const label = document.getElementById('cancelConfirmLabel');
    btn.disabled = busy;
    keep.disabled = busy;
    btn.classList.toggle('opacity-60', busy);
    label.textContent = busy ? 'Cancelling…' : 'Yes, cancel';
}
function confirmCancel() {
    if (!pendingCancel) return;
    const isOrder = pendingCancel.type === 'order';
    const fd = new FormData();
    fd.append('action', isOrder ? 'cancel_order' : 'cancel_booking');
    fd.append(isOrder ? 'order_id' : 'booking_id', pendingCancel.id);
    fd.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>');
    setCancelBusy(true);
    fetch('/playerdashboard', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                window.location.hash = isOrder ? 'orders' : '';
                location.reload();
            } else {
                showCancelError(d.error || (isOrder ? 'Could not cancel order.' : 'Could not cancel booking.'));
            }
        })
        .catch(() => showCancelError('Network error. Please try again.'));
}
function showCancelError(message) {
    setCancelBusy(false);
    const err = document.getElementById('cancelError');
    err.textContent = message;
    err.classList.remove('hidden');
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeCancelModal();
});
// Open the Orders tab after a cancel-reload.
if (window.location.hash === '#orders') { switchTab('orders'); }

// Logout Modal
function toggleLogout() {
    const m = document.getElementById('logoutModal');
    if (!m) return;
    if (m.classList.contains('hidden')) { m.classList.remove('hidden'); }
    else { m.classList.add('hidden'); }
}

// Open refund (from booking rows) -> refund page
function openRefundModal(payload) {
    const targetType = payload.order_id ? 'order' : 'booking';
    const targetId = payload.order_id || payload.booking_id;
    let matched = false;
    // Try to select the matching option first.
    if (bookingId) {
        for (let i = 0; i < bookingId.options.length; i++) {
            const o = bookingId.options[i];
            if (o.getAttribute('data-type') === targetType && o.getAttribute('data-id') == targetId) {
                bookingId.selectedIndex = i;
                matched = true;
                break;
            }
        }
    }
    if (matched) {
        // Sync the hidden order_id/booking_id from the selected option.
        updateRefundSummary();
    } else {
        // No matching option: keep the clicked item's IDs instead of letting
        // updateRefundSummary() overwrite them with the first dropdown entry.
        const ro = document.getElementById('refund_order_id');
        const rb = document.getElementById('refund_booking_id');
        if (ro) ro.value = payload.order_id || 0;
        if (rb) rb.value = payload.booking_id || 0;
    }
    showRefund();
}

function closeRefundModal() {
    showDashboard();
}

function submitRefund() {
    if (policyCheck && !policyCheck.checked) {
        policyCheck.focus();
        alert('Please confirm the refund policy before submitting.');
        return;
    }
    if (!bookingId || !bookingId.value) {
        alert('Please select an approved booking or order.');
        return;
    }
    const r = reason ? reason.value.trim() : '';
    if (r === '') { alert('Please describe why you are requesting a refund.'); return; }
    const proof = document.getElementById('refund_proof');
    if (!proof || !proof.files || proof.files.length === 0) {
        alert('Please attach proof for your refund request.');
        if (proof) proof.focus();
        return;
    }
    // combine details if provided
    const d = details ? details.value.trim() : '';
    const fd = new FormData(refundForm);
    if (d) {
        // refunds.reason is VARCHAR(255); cap the combined text so the insert cannot fail.
        const combined = (r + ' — ' + d).slice(0, 255);
        fd.set('reason', combined);
    }
    fd.append('action', 'request_refund');
    fetch('/playerdashboard', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                const opt = bookingId ? bookingId.options[bookingId.selectedIndex] : null;
                const req = { id: 'RF-' + String(Date.now()).slice(-6), booking: opt ? opt.textContent.trim() : '', reason: (reason ? reason.value : ''), details: (details && details.value.trim()) ? details.value.trim() : 'None provided', status: 'Pending', date: new Date().toLocaleDateString() };
                // `booking` is used as the generic item label in renderRequests().
                submittedRequests.unshift(req);
                renderRequests();
                if (successBanner) {
                    successBanner.textContent = 'Refund request submitted successfully. Status: Pending review.';
                    successBanner.classList.add('show');
                }
                if (refundForm) refundForm.reset();
                // Reload back onto the refund page so the freshly saved
                // request is visible in "My Requests" instead of the dashboard.
                setTimeout(() => { window.location.href = '/playerdashboard#refund'; }, 1000);
            } else {
                alert(d.error || 'Could not submit refund request.');
            }
        })
        .catch(() => alert('Network error. Please try again.'));
}

// After a successful submission the page reloads with #refund so the new
// request is shown in "My Requests" immediately.
if (window.location.hash === '#refund') { showRefund(); }

// ---- Customer order details ("View Order") ----
function odEscape(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}
function odMoney(value) {
    return '₱' + Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function viewOrder(orderId) {
    const modal = document.getElementById('orderDetailsModal');
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    const body = document.getElementById('odBody');
    const title = document.getElementById('odTitle');
    const sub = document.getElementById('odSub');
    const total = document.getElementById('odTotal');
    body.innerHTML = '<div class="flex items-center justify-center py-10 text-outline"><span class="material-symbols-outlined animate-spin mr-2">progress_activity</span> Loading…</div>';
    title.textContent = 'Order #ORD-' + String(orderId).padStart(5, '0');
    sub.innerHTML = '&nbsp;';
    total.textContent = '₱0.00';

    fetch('/playerdashboard?ajax=order_details&order_id=' + encodeURIComponent(orderId), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) {
                body.innerHTML = '<p class="text-sm font-bold text-red-600">' + odEscape((d && d.error) || 'Could not load this order.') + '</p>';
                return;
            }
            var o = d.order;
            title.textContent = 'Order #' + o.number;
            var bits = [o.date];
            if (o.payment_method) bits.push(String(o.payment_method).replace(/_/g, ' ').replace(/\b\w/g, function (m) { return m.toUpperCase(); }));
            if (o.payment_status) bits.push('Payment: ' + o.payment_status);
            sub.textContent = bits.join(' • ');
            total.textContent = odMoney(o.total);

            var receiptLink = o.payment_method === 'pay_at_counter'
                ? '<a href="/receipt.php?order_id=' + encodeURIComponent(o.id) + '&amp;download=1" class="ml-auto inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-primary/10 text-primary text-[11px] font-bold hover:bg-primary/20 transition-colors"><span class="material-symbols-outlined text-[15px]">download</span> Receipt</a>'
                : '';
            var info = '<div class="flex flex-wrap items-center gap-2 mb-4">' +
                '<span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider ' + odEscape(o.badge_class) + '">' +
                '<span class="w-1.5 h-1.5 rounded-full ' + odEscape(o.dot_class) + '"></span>' + odEscape(o.status_label) + '</span>' +
                (o.reference ? '<span class="text-[11px] text-on-surface-variant">Ref: ' + odEscape(o.reference) + '</span>' : '') +
                (o.payment_ref ? '<span class="text-[11px] text-on-surface-variant">Payment ref: ' + odEscape(o.payment_ref) + '</span>' : '') +
                receiptLink +
                '</div>';

            if (!d.items || !d.items.length) {
                body.innerHTML = info + '<p class="text-sm italic text-outline">No line items recorded for this order.</p>';
                return;
            }
            var rows = '';
            d.items.forEach(function (it) {
                var thumb = it.image
                    ? '<img src="' + odEscape(it.image) + '" alt="" class="w-10 h-10 rounded-lg object-cover shrink-0 border border-outline-variant/30" onerror="this.style.display=\'none\'">'
                    : '';
                rows += '<tr class="border-b border-outline-variant/30">' +
                    '<td class="py-2.5 pr-3 text-sm text-on-surface"><div class="flex items-center gap-3">' + thumb +
                    '<span class="break-words min-w-0">' + odEscape(it.description) + '</span></div></td>' +
                    '<td class="py-2.5 px-3 text-sm text-center text-on-surface-variant">' + odEscape(it.quantity) + '</td>' +
                    '<td class="py-2.5 px-3 text-sm text-right text-on-surface-variant whitespace-nowrap">' + odMoney(it.unit_price) + '</td>' +
                    '<td class="py-2.5 pl-3 text-sm text-right font-bold text-on-surface whitespace-nowrap">' + odMoney(it.amount) + '</td>' +
                    '</tr>';
            });
            body.innerHTML = info +
                '<table class="w-full"><thead><tr class="text-[10px] uppercase tracking-widest text-outline">' +
                '<th class="text-left py-2 pr-3">Item</th><th class="text-center py-2 px-3">Qty</th>' +
                '<th class="text-right py-2 px-3">Unit</th><th class="text-right py-2 pl-3">Amount</th>' +
                '</tr></thead><tbody>' + rows + '</tbody></table>';
        })
        .catch(function () {
            body.innerHTML = '<p class="text-sm font-bold text-red-600">Network error. Please try again.</p>';
        });
}
function closeOrderDetails() {
    const modal = document.getElementById('orderDetailsModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
</script>

</html>