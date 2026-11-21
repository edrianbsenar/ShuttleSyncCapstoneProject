<?php
// receipt.php
// Downloadable / printable receipt image for a shop order or court booking.
//   /receipt.php?order_id=42             -> shop order receipt (inline PNG / SVG fallback)
//   /receipt.php?order_id=42&download=1  -> shop order receipt download
//   /receipt.php?booking_id=9            -> court booking receipt
//   /receipt.php?booking_id=9&download=1 -> court booking receipt download
// Only the buyer may fetch a receipt.

require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database_connect.php';
require_once __DIR__ . '/includes/csrf_helper.php';
require_once __DIR__ . '/includes/bank_details.php';
require_once __DIR__ . '/includes/receipt_renderer.php';

Config::load();

function receipt_fail(int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

function receipt_output(array $receipt, string $receipt_no, string $order_no): void {
    $format = strtolower((string)($_GET['format'] ?? 'png'));
    if ($format !== 'svg') { $format = 'png'; }

    try {
        [$bytes, $actual_format] = hp_receipt_render($receipt, $format);
    } catch (Throwable $e) {
        error_log('Receipt render failed: ' . $e->getMessage());
        receipt_fail(500, 'Could not generate the receipt.');
    }

    $ext = $actual_format === 'svg' ? 'svg' : 'png';
    $mime = $actual_format === 'svg' ? 'image/svg+xml' : 'image/png';
    $filename = 'HiPower-Receipt-' . $order_no . '.' . $ext;
    $download = isset($_GET['download']) && $_GET['download'] !== '0';

    while (ob_get_level() > 0) { ob_end_clean(); }

    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');

    echo $bytes;
    exit;
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    receipt_fail(401, 'Please sign in to view this receipt.');
}

$user_id    = (int)($_SESSION['user_id'] ?? 0);
$booking_id = (int)($_GET['booking_id'] ?? 0);
$order_id   = (int)($_GET['order_id'] ?? 0);
if ($user_id <= 0 || ($booking_id <= 0 && $order_id <= 0)) {
    receipt_fail(400, 'Missing receipt reference.');
}

// Court booking receipt. Online bookings are fully paid at checkout, so the
// customer can view this instead of a misleading "Pay Now" action.
if ($booking_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT b.booking_id, b.booking_reference, b.booking_date, b.start_time, b.end_time,
                   b.total_price, b.status AS booking_status, b.created_at AS booking_created,
                   c.name AS court_name,
                   p.payment_reference, p.status AS payment_status, p.created_at AS payment_created,
                   u.full_name, u.email
            FROM Bookings b
            LEFT JOIN Courts c ON c.court_id = b.court_id
            LEFT JOIN payments p ON p.payment_id = b.payment_id
            LEFT JOIN Users u ON u.user_id = b.user_id
            WHERE b.booking_id = ? AND (b.user_id = ? OR p.user_id = ?)
            LIMIT 1
        ");
        $stmt->execute([$booking_id, $user_id, $user_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        receipt_fail(500, 'Booking receipt data is unavailable right now.');
    }
    if (!$booking) receipt_fail(404, 'Receipt not found.');

    $order_no = 'BK-' . str_pad((string)$booking_id, 5, '0', STR_PAD_LEFT);
    $total    = round((float)($booking['total_price'] ?? 0), 2);

    $court = trim((string)($booking['court_name'] ?? ''));
    if ($court === '') $court = 'Court Reservation';

    $book_date = !empty($booking['booking_date'])
        ? date('M d, Y', strtotime((string)$booking['booking_date']))
        : '—';
    $time_parts = [];
    if (!empty($booking['start_time'])) $time_parts[] = date('h:i A', strtotime((string)$booking['start_time']));
    if (!empty($booking['end_time'])) $time_parts[] = date('h:i A', strtotime((string)$booking['end_time']));
    $time_label = $time_parts ? implode(' – ', $time_parts) : '—';

    $receipt_items = [[
        'description' => 'Court Reservation — ' . $court . ' (' . $book_date . ', ' . $time_label . ')',
        'quantity'    => 1,
        'unit_price'  => $total,
        'amount'      => $total,
    ]];
    $subtotal = $total;

    $payment_status = (string)($booking['payment_status'] ?? '');
    $booking_status = (string)($booking['booking_status'] ?? '');
    // Court bookings are paid through the QR code (GCash/Maya), never at the
    // counter. The admin decision is shown simply as Pending or Approved.
    $approved = $payment_status === 'Verified' || in_array($booking_status, ['Paid', 'Confirmed', 'Completed'], true);
    $paid = $approved;
    $payment_label = $approved ? 'Approved' : 'Pending';
    $method = 'QR Code (GCash/Maya)';

    $receipt_date = '—';
    if (!empty($booking['payment_created'])) {
        $receipt_date = date('M d, Y, g:i A', strtotime((string)$booking['payment_created']));
    } elseif (!empty($booking['booking_created'])) {
        $receipt_date = date('M d, Y, g:i A', strtotime((string)$booking['booking_created']));
    } else {
        $receipt_date = $book_date;
    }

    $receipt_no = (string)($booking['payment_reference'] ?? '');
    if ($receipt_no === '') $receipt_no = (string)($booking['booking_reference'] ?? '');
    if ($receipt_no === '') $receipt_no = $order_no;

    $store = function_exists('storePickupInfo') ? storePickupInfo() : [
        'name' => 'Hi-Power Badminton Center',
        'address' => '',
        'hours' => '',
        'requirements' => '',
    ];

    $receipt = [
        'logo_path'      => __DIR__ . '/img/logo.png',
        'store'          => $store,
        'title'          => 'BOOKING RECEIPT',
        'status_pill'    => $approved ? 'APPROVED' : 'PENDING',
        'receipt_no'     => $receipt_no,
        'order_no'       => $order_no,
        'date'           => $receipt_date,
        'customer'       => (string)($booking['full_name'] ?? '') !== '' ? (string)$booking['full_name'] : 'Customer',
        'method'         => $method,
        'payment_status' => $payment_label,
        'order_status'   => $booking_status,
        'paid'           => $paid,
        'items'          => $receipt_items,
        'subtotal'       => $subtotal,
        'total'          => $total,
        'notes'          => '',
    ];

    receipt_output($receipt, $receipt_no, $order_no);
}

$recent_ids = function_exists('hp_recent_order_ids') ? hp_recent_order_ids() : [];
$recent_sql = '';
if (!empty($recent_ids)) {
    $recent_sql = ' OR o.order_id IN (' . implode(',', array_fill(0, count($recent_ids), '?')) . ')';
}

$order = null;
try {
    $stmt = $pdo->prepare("
        SELECT o.order_id, o.order_number, o.order_date, o.total_amount, o.status,
               o.payment_method, o.payment_reference,
               p.status AS payment_status, p.created_at AS payment_created,
               u.full_name, u.email
        FROM Orders o
        LEFT JOIN payments p ON o.payment_id = p.payment_id
        LEFT JOIN Users u ON o.user_id = u.user_id
        WHERE o.order_id = ? AND (
            o.user_id = ?
            OR p.user_id = ?
            OR EXISTS (
                SELECT 1 FROM payment_items pi
                JOIN payments pp ON pp.payment_id = pi.payment_id
                WHERE pi.order_id = o.order_id AND pp.user_id = ?
            )
            $recent_sql
        )
        LIMIT 1
    ");
    $stmt->execute(array_merge([$order_id, $user_id, $user_id, $user_id], $recent_ids));
    $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
    // Legacy schema without Orders.payment_id / payment_items.
    try {
        $legacy_recent = '';
        if (!empty($recent_ids)) {
            $legacy_recent = ' OR o.order_id IN (' . implode(',', array_fill(0, count($recent_ids), '?')) . ')';
        }
        $stmt = $pdo->prepare("
            SELECT o.order_id, o.order_number, o.order_date, o.total_amount, o.status,
                   u.full_name, u.email
            FROM Orders o
            LEFT JOIN Users u ON o.user_id = u.user_id
            WHERE o.order_id = ? AND (o.user_id = ?$legacy_recent)
            LIMIT 1
        ");
        $stmt->execute(array_merge([$order_id, $user_id], $recent_ids));
        $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e2) {
        receipt_fail(500, 'Receipt data is unavailable right now.');
    }
}

if (!$order) {
    receipt_fail(404, 'Receipt not found.');
}

// Line items (preferred table, then legacy fallback).
$items = [];
try {
    $ist = $pdo->prepare("SELECT description, quantity, amount FROM payment_items WHERE order_id = ? AND item_type = 'shop' ORDER BY payment_item_id ASC");
    $ist->execute([$order_id]);
    $items = $ist->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    try {
        $ist = $pdo->prepare("SELECT product_name AS description, quantity, (price * quantity) AS amount FROM Order_Items WHERE order_id = ? ORDER BY order_item_id ASC");
        $ist->execute([$order_id]);
        $items = $ist->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {
        $items = [];
    }
}

$receipt_items = [];
$subtotal = 0.0;
foreach ($items as $it) {
    $qty = max(1, (int)($it['quantity'] ?? 1));
    $amount = round((float)($it['amount'] ?? 0), 2);
    $desc = (string)($it['description'] ?? 'Item');

    // Checkout can store "| Image: <url>" inside the description.
    $desc = preg_replace('/\s*\|\s*Image:\s*https?:\/\/[^\s)]+/i', '', $desc);
    $desc = trim(preg_replace('/\s+/', ' ', strip_tags((string)$desc)));

    $receipt_items[] = [
        'description' => $desc !== '' ? $desc : 'Item',
        'quantity'    => $qty,
        'unit_price'  => round($amount / $qty, 2),
        'amount'      => $amount,
    ];
    $subtotal += $amount;
}
$subtotal = round($subtotal, 2);

$total = round((float)($order['total_amount'] ?? 0), 2);
if (empty($receipt_items)) {
    $subtotal = $total;
}

$payment_status = (string)($order['payment_status'] ?? '');
$order_status   = (string)($order['status'] ?? 'Pending');
$paid = ($payment_status === 'Verified') || in_array($order_status, ['Paid', 'Completed', 'Delivered'], true);

$method_raw = (string)($order['payment_method'] ?? '');
if ($method_raw === 'pay_at_counter') {
    $method = 'Pay at Counter (Cash)';
} elseif ($method_raw === 'gcash_maya') {
    $method = 'GCash / Maya';
} elseif ($method_raw !== '') {
    $method = ucwords(str_replace(['_', '-'], ' ', $method_raw));
} else {
    $method = 'Pay at Counter (Cash)';
}

$payment_label = $payment_status !== '' ? $payment_status : ($paid ? 'Paid' : 'Pending Payment');
if ($payment_status === 'Verified') { $payment_label = 'Paid'; }

$date_label = '—';
if (!empty($order['order_date'])) {
    $date_label = date('M d, Y', strtotime($order['order_date']));
    if (!empty($order['payment_created'])) {
        $date_label .= ', ' . date('g:i A', strtotime($order['payment_created']));
    }
}

$receipt_no = (string)($order['payment_reference'] ?? '');
if ($receipt_no === '') { $receipt_no = (string)($order['order_number'] ?? ''); }
if ($receipt_no === '') { $receipt_no = 'ORD-' . str_pad((string)$order_id, 5, '0', STR_PAD_LEFT); }

$order_no = 'ORD-' . str_pad((string)$order_id, 5, '0', STR_PAD_LEFT);

$store = function_exists('storePickupInfo') ? storePickupInfo() : [
    'name' => 'Hi-Power Badminton Center',
    'address' => '',
    'hours' => '',
    'requirements' => '',
];

$receipt = [
    'logo_path'      => __DIR__ . '/img/logo.png',
    'store'          => $store,
    'title'          => 'SHOP ORDER RECEIPT',
    'receipt_no'     => $receipt_no,
    'order_no'       => $order_no,
    'date'           => $date_label,
    'customer'       => (string)($order['full_name'] ?? '') !== '' ? (string)$order['full_name'] : 'Customer',
    'method'         => $method,
    'payment_status' => $payment_label,
    'order_status'   => $order_status,
    'paid'           => $paid,
    'items'          => $receipt_items,
    'subtotal'       => $subtotal,
    'total'          => $total,
    'notes'          => '',
];

receipt_output($receipt, $receipt_no, $order_no);
