<?php
// Start session
require_once __DIR__ . '/../includes/session_boot.php';

// Access Control
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: /authentication/login");
    exit;
}
if ($_SESSION['role'] !== 'Admin' && $_SESSION['role'] !== 'Head Manager') {
    header("Location: ../playerdashboard.php");
    exit;
}

require_once __DIR__ . '/../includes/database_connect.php';
require_once __DIR__ . '/../includes/csrf_helper.php';
require_once __DIR__ . '/../includes/notification_helper.php';

$admin_name = $_SESSION['full_name'];
$admin_role = $_SESSION['role'];
$success_message = '';
$error_message = '';

// ==========================================
// HANDLE BOOKING ACTIONS
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    verify_csrf_token();
    if ($_POST['action'] == 'update_booking_status') {
        try {
            $booking_id = (int)$_POST['booking_id'];
            $new_status = $_POST['status']; // 'Confirmed' (Paid) or 'Cancelled'

            if ($new_status === 'Cancelled') {
                // TC-AS-022: cancellation requires a reason.
                $reason = trim($_POST['cancel_reason'] ?? '');
                if ($reason === '') throw new Exception("Please provide a cancellation reason.");

                // Prevent cancelling bookings that have already ended.
                $cur = $pdo->prepare("SELECT booking_date, end_time, status FROM Bookings WHERE booking_id = ?");
                $cur->execute([$booking_id]);
                $b = $cur->fetch();
                if ($b === false) throw new Exception("Booking not found.");
                if (in_array($b['status'], ['Cancelled', 'Expired'], true)) {
                    throw new Exception("Booking is already cancelled or expired.");
                }
                if (strtotime($b['booking_date'] . ' ' . $b['end_time']) <= time()) {
                    throw new Exception("Cannot cancel a booking that has already ended.");
                }

                $old_status = $b['status'];
                try {
                    $pdo->prepare("UPDATE Bookings SET status = 'Cancelled', cancel_reason = ?, cancelled_at = NOW() WHERE booking_id = ?")->execute([$reason, $booking_id]);
                } catch (PDOException $e) {
                    $pdo->prepare("UPDATE Bookings SET status = 'Cancelled' WHERE booking_id = ?")->execute([$booking_id]);
                }
                
                // Notify player
                try {
                    notify_booking_status_change($pdo, $booking_id, $old_status, 'Cancelled', $reason);
                } catch (Exception $e) {
                    error_log("Notification failed: " . $e->getMessage());
                }
                
                $success_message = "Booking cancelled.";
            } elseif ($new_status === 'Confirmed') {
                // Get old status for notification
                $cur = $pdo->prepare("SELECT status FROM Bookings WHERE booking_id = ?");
                $cur->execute([$booking_id]);
                $old_status = $cur->fetchColumn();
                
                $pdo->prepare("UPDATE Bookings SET status = 'Confirmed' WHERE booking_id = ?")->execute([$booking_id]);
                
                // Notify player
                try {
                    notify_booking_status_change($pdo, $booking_id, $old_status, 'Confirmed', null);
                } catch (Exception $e) {
                    error_log("Notification failed: " . $e->getMessage());
                }
                
                $success_message = "Booking successfully marked as Paid/Confirmed.";
            } else {
                throw new Exception("Invalid status.");
            }
        } catch (Exception $e) {
            $error_message = "Failed to update booking status: " . $e->getMessage();
        }
    }

    // TC-AS-021: process a refund for a court booking.
    // The old reschedule tool (court/date/time reassignment) has been replaced:
    // an admin refund records a 100% booking refund and cancels the slot.
    if ($_POST['action'] == 'refund_booking') {
        try {
            $booking_id = (int)($_POST['booking_id'] ?? 0);
            $notes      = trim($_POST['admin_notes'] ?? '');

            if ($booking_id <= 0) throw new Exception("Invalid booking.");
            if ($notes === '') throw new Exception("Please provide a reason/note for the refund.");

            $q = $pdo->prepare("
                SELECT b.booking_id, b.user_id, b.status, b.booking_date, b.start_time, b.end_time,
                       b.total_price, b.payment_id, p.status AS payment_status
                FROM Bookings b
                LEFT JOIN payments p ON p.payment_id = b.payment_id
                WHERE b.booking_id = ?
            ");
            $q->execute([$booking_id]);
            $booking = $q->fetch();
            if (!$booking) throw new Exception("Booking not found.");
            $amount = (float)$booking['total_price'];
            if ($amount <= 0) throw new Exception("There is no amount to refund.");

            // Migration-safe: refunds.proof_path / admin_notes support.
            foreach (['proof_path' => 'VARCHAR(255) NULL', 'admin_notes' => 'TEXT NULL'] as $col => $def) {
                try {
                    if (!$pdo->query("SHOW COLUMNS FROM refunds LIKE '$col'")->fetch()) {
                        $pdo->exec("ALTER TABLE refunds ADD COLUMN `$col` $def");
                    }
                } catch (PDOException $e) { /* ignore */ }
            }

            // Cancel the slot if it is still active and record the timestamp.
            $was_active = in_array($booking['status'], ['Pending', 'Pending Payment', 'Confirmed', 'Paid', 'Completed'], true);
            if ($was_active) {
                $pdo->prepare("UPDATE Bookings SET status = 'Cancelled', cancelled_at = NOW(), cancel_reason = ? WHERE booking_id = ?")
                    ->execute([$notes, $booking_id]);
                try {
                    require_once __DIR__ . '/../includes/notification_helper.php';
                    notify_booking_status_change($pdo, $booking_id, $booking['status'], 'Cancelled', $notes);
                } catch (Exception $e) { /* ignore notification failures */ }
            }

            // Reuse an existing request for this booking, otherwise create one.
            $ex = $pdo->prepare("SELECT refund_id FROM refunds WHERE booking_id = ? ORDER BY refund_id DESC LIMIT 1");
            $ex->execute([$booking_id]);
            $existing_id = (int)($ex->fetchColumn() ?: 0);

            $reason = function_exists('mb_substr') ? mb_substr($notes, 0, 252) : substr($notes, 0, 252);

            if ($existing_id > 0) {
                $pdo->prepare("UPDATE refunds SET status = 'Approved', amount = ?, reason = ?, admin_notes = ?, payment_id = ?, processed_at = NULL WHERE refund_id = ?")
                    ->execute([$amount, $reason, $notes, $booking['payment_id'], $existing_id]);
                $refund_id = $existing_id;
            } else {
                $pdo->prepare("INSERT INTO refunds (user_id, payment_id, order_id, booking_id, amount, reason, admin_notes, status) VALUES (?, ?, NULL, ?, ?, ?, ?, 'Approved')")
                    ->execute([$booking['user_id'], $booking['payment_id'], $booking_id, $amount, $reason, $notes]);
                $refund_id = (int)$pdo->lastInsertId();
            }

            $refund_ref = '#REF-' . str_pad((string)$refund_id, 5, '0', STR_PAD_LEFT);
            $success_message = "Refund approved for Booking #{$booking_id} ({$refund_ref}) — ₱" . number_format($amount, 2)
                . ". Mark it as Refunded in Refund Requests once the customer has been reimbursed.";
        } catch (Exception $e) {
            $error_message = "Refund failed: " . $e->getMessage();
        }
    }
}

// ==========================================
// FETCH METRICS (Today's Stats)
// ==========================================
$today = date('Y-m-d');

// Bookings Today
$today_bookings_stmt = $pdo->prepare("SELECT COUNT(*) FROM Bookings WHERE booking_date = ? AND status != 'Cancelled'");
$today_bookings_stmt->execute([$today]);
$today_bookings = $today_bookings_stmt->fetchColumn() ?? 0;

// Pending Payments (All-time pending)
$pending_payments = $pdo->query("SELECT COUNT(*) FROM Bookings WHERE status = 'Pending'")->fetchColumn() ?? 0;

// Revenue Today
$today_rev_stmt = $pdo->prepare("SELECT SUM(total_price) FROM Bookings WHERE booking_date = ? AND status = 'Confirmed'");
$today_rev_stmt->execute([$today]);
$today_revenue = $today_rev_stmt->fetchColumn() ?? 0;

// ==========================================
// FETCH ALL BOOKINGS
// ==========================================
$bookings_stmt = $pdo->query("
    SELECT b.booking_id, b.booking_reference, b.booking_date, b.start_time, b.end_time, b.status, b.total_price, b.court_id,
           c.name as court_name, u.full_name 
    FROM Bookings b 
    JOIN Courts c ON b.court_id = c.court_id 
    JOIN Users u ON b.user_id = u.user_id 
    WHERE b.status != 'In Cart'
    ORDER BY b.booking_date DESC, b.start_time DESC
");
$all_bookings = $bookings_stmt->fetchAll();

// Cancellation reasons (column added by database_admin_upgrade.sql - tolerate absence)
$cancel_reasons = [];
try {
    $cr = $pdo->query("SELECT booking_id, cancel_reason FROM Bookings WHERE cancel_reason IS NOT NULL AND cancel_reason <> ''");
    foreach ($cr->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cancel_reasons[(int)$row['booking_id']] = $row['cancel_reason'];
    }
} catch (PDOException $e) { $cancel_reasons = []; }

// Courts list is no longer needed: the admin refund tool does not reassign slots.

?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>User Bookings Master Schedule | HI-POWER Admin</title>
    <?php include __DIR__ . '/../includes/tailwind_config.php'; ?>
    <style>
        .status-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px; border-radius: 9999px; font-size: 11px; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; }
        .status-badge--confirmed { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
        .status-badge--pending  { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
        .status-badge--cancelled { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .booking-table-row:hover { background: #f8f9fc; }
        .filter-btn { transition: all 0.15s ease; }
        .filter-btn.active { background: #ff5722; color: #fff; border-color: #ff5722; }
        .filter-btn:not(.active):hover { background: #f2f3f7; border-color: #ff5722; color: #ff5722; }
        @keyframes modalIn { from { opacity: 0; transform: translateY(14px) scale(0.98); } to { opacity: 1; transform: none; } }
    </style>
</head>
<body class="bg-surface text-on-background min-h-screen">

<form id="bookingActionForm" method="POST" action="user_bookings" style="display:none;">
    <?php echo get_csrf_input(); ?>
    <input type="hidden" name="action" value="update_booking_status">
    <input type="hidden" name="booking_id" id="action_booking_id">
    <input type="hidden" name="status" id="action_status">
</form>

<?php include __DIR__ . '/includes/admin_header.php'; ?>

    <main class="md:ml-[260px] p-4 md:p-8 min-h-screen">

        <?php if (!empty($success_message)): ?>
            <div class="mb-6 p-4 bg-emerald-50 border border-emerald-200 rounded-2xl flex items-center gap-3 shadow-sm">
                <div class="w-9 h-9 rounded-xl bg-emerald-100 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-emerald-600 text-[20px]">check_circle</span>
                </div>
                <p class="text-emerald-700 text-sm font-semibold"><?php echo htmlspecialchars($success_message); ?></p>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-2xl flex items-center gap-3 shadow-sm">
                <div class="w-9 h-9 rounded-xl bg-red-100 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-red-600 text-[20px]">error</span>
                </div>
                <p class="text-red-700 text-sm font-semibold"><?php echo htmlspecialchars($error_message); ?></p>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="mb-8 mt-2">
            <nav class="flex items-center gap-2 text-xs font-semibold text-outline uppercase tracking-widest mb-3">
                <a href="dashboard" class="hover:text-primary transition-colors">Dashboard</a>
                <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                <span class="text-primary">Bookings</span>
            </nav>
            <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
                <div>
                    <h1 class="text-2xl md:text-3xl font-bold text-on-surface tracking-tight">Master Schedule</h1>
                    <p class="text-on-surface-variant text-sm mt-1 max-w-xl">View and manage all court bookings. Confirm payments, cancel reservations, and track today's activity at a glance.</p>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline text-[20px]">search</span>
                        <input id="searchInput" class="w-64 bg-white border border-outline-variant/40 rounded-xl py-2.5 pl-10 pr-4 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary transition-all shadow-sm placeholder:text-outline" placeholder="Search customer or court..." type="text">
                    </div>
                </div>
            </div>
        </div>

        <!-- Metrics Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-5 flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-primary/10 flex items-center justify-center">
                    <span class="material-symbols-outlined text-primary text-[24px]">calendar_today</span>
                </div>
                <div>
                    <p class="text-xs font-bold text-outline uppercase tracking-widest">Today's Bookings</p>
                    <p class="text-2xl font-bold text-on-surface mt-0.5"><?php echo number_format($today_bookings); ?></p>
                </div>
            </div>
            <div class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-5 flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-amber-50 flex items-center justify-center">
                    <span class="material-symbols-outlined text-amber-500 text-[24px]">pending_actions</span>
                </div>
                <div>
                    <p class="text-xs font-bold text-outline uppercase tracking-widest">Pending Payments</p>
                    <p class="text-2xl font-bold text-on-surface mt-0.5"><?php echo number_format($pending_payments); ?></p>
                </div>
            </div>
            <div class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-5 flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-emerald-50 flex items-center justify-center">
                    <span class="material-symbols-outlined text-emerald-600 text-[24px]">payments</span>
                </div>
                <div>
                    <p class="text-xs font-bold text-outline uppercase tracking-widest">Revenue Today</p>
                    <p class="text-2xl font-bold text-on-surface mt-0.5">₱<?php echo number_format($today_revenue, 2); ?></p>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <span class="text-xs font-bold text-outline uppercase tracking-widest mr-1">Status:</span>
            <button class="filter-btn active px-4 py-1.5 text-xs font-bold border border-outline-variant/40 rounded-full" data-filter="all">All</button>
            <button class="filter-btn px-4 py-1.5 text-xs font-bold border border-outline-variant/40 rounded-full text-on-surface-variant" data-filter="pending">Pending</button>
            <button class="filter-btn px-4 py-1.5 text-xs font-bold border border-outline-variant/40 rounded-full text-on-surface-variant" data-filter="confirmed">Paid</button>
            <button class="filter-btn px-4 py-1.5 text-xs font-bold border border-outline-variant/40 rounded-full text-on-surface-variant" data-filter="cancelled">Cancelled</button>
        </div>

        <!-- Bookings Table -->
        <div class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm overflow-hidden">
            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-left border-collapse min-w-[1000px]">
                    <thead>
                        <tr class="border-b border-outline-variant/20">
                            <th class="py-4 px-6 text-[10px] font-bold text-outline uppercase tracking-widest">Court Details</th>
                            <th class="py-4 px-6 text-[10px] font-bold text-outline uppercase tracking-widest">Customer</th>
                            <th class="py-4 px-6 text-[10px] font-bold text-outline uppercase tracking-widest">Date & Time</th>
                            <th class="py-4 px-6 text-[10px] font-bold text-outline uppercase tracking-widest">Duration</th>
                            <th class="py-4 px-6 text-[10px] font-bold text-outline uppercase tracking-widest">Amount</th>
                            <th class="py-4 px-6 text-[10px] font-bold text-outline uppercase tracking-widest">Status</th>
                            <th class="py-4 px-6 text-[10px] font-bold text-outline uppercase tracking-widest text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/20">
                        <?php if(empty($all_bookings)): ?>
                            <tr>
                                <td colspan="7" class="py-16 text-center">
                                    <div class="flex flex-col items-center">
                                        <span class="material-symbols-outlined text-outline-variant text-[48px] mb-3">event_busy</span>
                                        <p class="text-on-surface-variant font-semibold text-sm">No bookings found</p>
                                        <p class="text-outline text-xs mt-1">Bookings will appear here once customers start reserving courts.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($all_bookings as $booking): 
                                // Calculate duration
                                $duration_hours = (strtotime($booking['end_time']) - strtotime($booking['start_time'])) / 3600;
                                
                                // Generate Initials
                                $words = explode(" ", trim($booking['full_name']));
                                $initials = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
                                
                                // Status styling
                                $status = $booking['status'];
                                // Paid & available can be reassigned/cancelled; only unpaid can be confirmed.
                                $can_confirm = in_array($status, ['Pending', 'Pending Payment'], true);
                                $is_active   = !in_array($status, ['Cancelled', 'Expired'], true);
                                 $is_ended    = strtotime($booking['booking_date'] . ' ' . $booking['end_time']) <= time();
                                
                                if (in_array($status, ['Confirmed', 'Completed', 'Paid'], true)) {
                                    $status_badge = 'status-badge status-badge--confirmed';
                                    $display_status = 'Paid';
                                    $filter_status = 'confirmed';
                                } elseif (in_array($status, ['Pending', 'Pending Payment'], true)) {
                                    $status_badge = 'status-badge status-badge--pending';
                                    $display_status = ($status === 'Pending Payment') ? 'Awaiting Payment' : 'Pending';
                                    $filter_status = 'pending';
                                } else {
                                    $status_badge = 'status-badge status-badge--cancelled';
                                    $display_status = 'Cancelled';
                                    $filter_status = 'cancelled';
                                }
                            ?>
                            <tr class="booking-table-row transition-colors" data-status="<?php echo $filter_status; ?>">
                                <td class="py-4 px-6">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-xl bg-primary/5 border border-primary/10 flex items-center justify-center shrink-0">
                                            <span class="material-symbols-outlined text-primary text-[20px]">stadium</span>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="font-bold text-sm text-on-surface truncate"><?php echo htmlspecialchars($booking['court_name']); ?></p>
                                            <p class="text-[10px] text-outline font-mono font-bold tracking-wider uppercase mt-0.5"><?php echo htmlspecialchars($booking['booking_reference']); ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-4 px-6">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-full bg-primary/10 text-primary flex items-center justify-center text-[11px] font-bold shrink-0">
                                            <?php echo $initials; ?>
                                        </div>
                                        <span class="font-semibold text-sm text-on-surface truncate"><?php echo htmlspecialchars($booking['full_name']); ?></span>
                                    </div>
                                </td>
                                <td class="py-4 px-6">
                                    <p class="font-semibold text-sm text-on-surface"><?php echo date('M d, Y', strtotime($booking['booking_date'])); ?></p>
                                    <p class="text-xs text-on-surface-variant mt-0.5"><?php echo date('h:i A', strtotime($booking['start_time'])) . ' – ' . date('h:i A', strtotime($booking['end_time'])); ?></p>
                                </td>
                                <td class="py-4 px-6">
                                    <span class="text-sm font-semibold text-on-surface"><?php echo $duration_hours; ?> hr</span>
                                </td>
                                <td class="py-4 px-6">
                                    <span class="text-sm font-bold text-on-surface">₱<?php echo number_format($booking['total_price'], 2); ?></span>
                                </td>
                                <td class="py-4 px-6">
                                    <span class="<?php echo $status_badge; ?>"><?php echo $display_status; ?></span>
                                    <?php if ($filter_status === 'cancelled' && !empty($cancel_reasons[(int)$booking['booking_id']])): ?>
                                        <p class="text-[10px] text-outline mt-1 max-w-[180px] truncate" title="<?php echo htmlspecialchars($cancel_reasons[(int)$booking['booking_id']]); ?>">
                                            Reason: <?php echo htmlspecialchars($cancel_reasons[(int)$booking['booking_id']]); ?>
                                        </p>
                                    <?php endif; ?>
                                </td>
                                <td class="py-4 px-6">
                                    <div class="flex justify-end gap-1.5">
                                        <button class="w-9 h-9 rounded-xl flex items-center justify-center transition-all <?php echo $can_confirm ? 'text-emerald-600 hover:bg-emerald-50 active:scale-90' : 'text-outline/40 cursor-not-allowed'; ?>" 
                                                <?php echo $can_confirm ? "onclick=\"updateBookingStatus({$booking['booking_id']}, 'Confirmed')\"" : 'disabled'; ?> 
                                                title="Mark as Paid">
                                            <span class="material-symbols-outlined text-[20px]">check_circle</span>
                                        </button>
                                        <button class="w-9 h-9 rounded-xl flex items-center justify-center transition-all <?php echo ($is_active && !$is_ended) ? 'text-primary hover:bg-primary/5 active:scale-90' : 'text-outline/40 cursor-not-allowed'; ?>" 
                                                <?php echo ($is_active && !$is_ended) ? "onclick='openRefundModal(" . json_encode([
                                                    'id' => (int)$booking['booking_id'],
                                                    'ref' => $booking['booking_reference'],
                                                    'name' => $booking['full_name'],
                                                    'court' => $booking['court_name'],
                                                    'date' => $booking['booking_date'],
                                                    'start' => date('H:i', strtotime($booking['start_time'])),
                                                    'end' => date('H:i', strtotime($booking['end_time'])),
                                                    'amount' => '₱' . number_format((float)$booking['total_price'], 2),
                                                ], JSON_HEX_APOS | JSON_HEX_QUOT) . ")'" : 'disabled'; ?> 
                                                title="Refund Booking">
                                            <span class="material-symbols-outlined text-[20px]">currency_exchange</span>
                                        </button>
                                        <button class="w-9 h-9 rounded-xl flex items-center justify-center transition-all <?php echo ($is_active && !$is_ended) ? 'text-red-500 hover:bg-red-50 active:scale-90' : 'text-outline/40 cursor-not-allowed'; ?>" 
                                                <?php echo ($is_active && !$is_ended) ? "onclick=\"openCancelModal({$booking['booking_id']})\"" : 'disabled'; ?> 
                                                title="Cancel Booking">
                                            <span class="material-symbols-outlined text-[20px]">cancel</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer -->

    </main>

<!-- Cancel Booking Modal (TC-AS-022) -->
<div id="cancelModal" class="fixed inset-0 z-[200] hidden items-center justify-center p-4" style="background: rgba(15,23,42,0.55); backdrop-filter: blur(2px);">
    <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden" style="animation: modalIn .22s ease-out;">
        <form method="POST" action="user_bookings" class="p-6 space-y-5">
            <?php echo get_csrf_input(); ?>
            <input type="hidden" name="action" value="update_booking_status">
            <input type="hidden" name="status" value="Cancelled">
            <input type="hidden" name="booking_id" id="cancel_booking_id">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-red-50 flex items-center justify-center">
                    <span class="material-symbols-outlined text-red-500">cancel</span>
                </div>
                <div>
                    <h3 class="font-bold text-on-surface">Cancel Booking</h3>
                    <p class="text-xs text-outline">This releases the court slot immediately.</p>
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-on-surface-variant uppercase tracking-widest mb-2">Reason for cancellation</label>
                <textarea name="cancel_reason" required rows="3" class="w-full px-3 py-2.5 bg-surface border border-outline-variant/60 rounded-xl text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 resize-none" placeholder="e.g. Customer request, court maintenance, payment issue"></textarea>
            </div>
            <div class="flex gap-3 pt-1">
                <button type="button" onclick="closeCancelModal()" class="flex-1 h-11 bg-surface border border-outline-variant/60 text-on-surface rounded-xl font-bold text-sm hover:bg-surface-container-high transition-all">Keep Booking</button>
                <button type="submit" class="flex-1 h-11 bg-red-500 text-white rounded-xl font-bold text-sm hover:bg-red-600 transition-all">Cancel Booking</button>
            </div>
        </form>
    </div>
</div>

<!-- Refund Booking Modal (TC-AS-021) -->
<div id="refundModal" class="fixed inset-0 z-[200] hidden items-center justify-center p-4" style="background: rgba(15,23,42,0.55); backdrop-filter: blur(2px);">
    <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden" style="animation: modalIn .22s ease-out;">
        <form method="POST" action="user_bookings" class="p-6 space-y-5">
            <?php echo get_csrf_input(); ?>
            <input type="hidden" name="action" value="refund_booking">
            <input type="hidden" name="booking_id" id="res_booking_id">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center">
                    <span class="material-symbols-outlined text-emerald-600">currency_exchange</span>
                </div>
                <div>
                    <h3 class="font-bold text-on-surface">Refund Booking</h3>
                    <p class="text-xs text-outline" id="res_customer">&nbsp;</p>
                </div>
            </div>
            <div class="rounded-xl bg-surface border border-outline-variant/40 p-4 text-sm space-y-1.5">
                <div class="flex justify-between gap-3"><span class="text-on-surface-variant">Booking</span><strong id="res_booking_ref" class="font-mono text-xs">—</strong></div>
                <div class="flex justify-between gap-3"><span class="text-on-surface-variant">Court</span><strong id="res_court_name">—</strong></div>
                <div class="flex justify-between gap-3"><span class="text-on-surface-variant">Schedule</span><strong id="res_schedule">—</strong></div>
                <div class="flex justify-between gap-3"><span class="text-on-surface-variant">Amount paid</span><strong id="res_amount">—</strong></div>
                <div class="flex justify-between gap-3"><span class="text-on-surface-variant">Refund</span><strong class="text-emerald-600">100% full refund</strong></div>
            </div>
            <div class="p-3 rounded-xl bg-amber-50 border border-amber-200 text-[11px] text-amber-800 leading-relaxed">
                This cancels the court slot and records a <strong>100% refund</strong> for the booking. Verify the payment in the Payments page first, then mark the request as <strong>Refunded</strong> in Refund Requests after reimbursing the customer.
            </div>
            <div>
                <label class="block text-xs font-bold text-on-surface-variant uppercase tracking-widest mb-2">Reason / admin note</label>
                <textarea name="admin_notes" required rows="3" class="w-full px-3 py-2.5 bg-surface border border-outline-variant/60 rounded-xl text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 resize-none" placeholder="e.g. Approved refund request, court maintenance, payment issue"></textarea>
            </div>
            <div class="flex gap-3 pt-1">
                <button type="button" onclick="closeRefundModal()" class="flex-1 h-11 bg-surface border border-outline-variant/60 text-on-surface rounded-xl font-bold text-sm hover:bg-surface-container-high transition-all">Cancel</button>
                <button type="submit" class="flex-1 h-11 bg-emerald-600 text-white rounded-xl font-bold text-sm hover:brightness-110 transition-all">Approve 100% Refund</button>
            </div>
        </form>
    </div>
</div>

<script>
    function updateBookingStatus(bookingId, newStatus) {
        if (newStatus === 'Confirmed') {
            if (confirm('Confirm payment for this booking?')) {
                document.getElementById('action_booking_id').value = bookingId;
                document.getElementById('action_status').value = newStatus;
                document.getElementById('bookingActionForm').submit();
            }
        } else {
            openCancelModal(bookingId);
        }
    }

    function openCancelModal(bookingId) {
        document.getElementById('cancel_booking_id').value = bookingId;
        const m = document.getElementById('cancelModal');
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
    function closeCancelModal() {
        const m = document.getElementById('cancelModal');
        m.classList.add('hidden');
        m.classList.remove('flex');
    }

    function openRefundModal(b) {
        document.getElementById('res_booking_id').value = b.id;
        document.getElementById('res_customer').textContent = b.name || '';
        document.getElementById('res_booking_ref').textContent = b.ref || ('Booking #' + b.id);
        document.getElementById('res_court_name').textContent = b.court || '—';
        document.getElementById('res_schedule').textContent = [b.date, (b.start && b.end) ? (b.start + '–' + b.end) : ''].filter(Boolean).join(' • ');
        document.getElementById('res_amount').textContent = b.amount || '—';
        const m = document.getElementById('refundModal');
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
    function closeRefundModal() {
        const m = document.getElementById('refundModal');
        m.classList.add('hidden');
        m.classList.remove('flex');
    }

    // Close modals on backdrop click
    ['cancelModal', 'refundModal'].forEach(id => {
        const m = document.getElementById(id);
        if (m) m.addEventListener('click', e => { if (e.target === m) { m.classList.add('hidden'); m.classList.remove('flex'); } });
    });

    document.addEventListener('DOMContentLoaded', () => {
        // Search Logic
        const searchInput = document.getElementById('searchInput');
        searchInput.addEventListener('input', (e) => {
            const term = e.target.value.toLowerCase();
            applyFilters();
        });

        // Filter Logic
        const filterBtns = document.querySelectorAll('.filter-btn');
        let activeFilter = 'all';

        filterBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                filterBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                activeFilter = btn.dataset.filter;
                applyFilters();
            });
        });

        function applyFilters() {
            const term = document.getElementById('searchInput').value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr[data-status]');
            let visibleCount = 0;

            rows.forEach(row => {
                const matchesFilter = activeFilter === 'all' || row.dataset.status === activeFilter;
                const matchesSearch = !term || row.textContent.toLowerCase().includes(term);
                const show = matchesFilter && matchesSearch;
                row.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            });

            // Show empty state if all rows hidden
            let emptyRow = document.querySelector('tbody tr:not([data-status])');
            if (emptyRow) {
                if (visibleCount === 0) {
                    emptyRow.style.display = '';
                    const td = emptyRow.querySelector('td');
                    if (td) {
                        td.innerHTML = '<div class="flex flex-col items-center py-12"><span class="material-symbols-outlined text-outline-variant text-[48px] mb-3">search_off</span><p class="text-on-surface-variant font-semibold text-sm">No matching bookings</p><p class="text-outline text-xs mt-1">Try adjusting your search or filter criteria.</p></div>';
                    }
                } else {
                    emptyRow.style.display = 'none';
                }
            }
        }

        // Auto-hide alerts
        const alerts = document.querySelectorAll('.bg-emerald-50, .bg-red-50');
        if (alerts.length > 0) {
            setTimeout(() => {
                alerts.forEach(alert => {
                    alert.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-8px)';
                    setTimeout(() => alert.remove(), 500);
                });
            }, 5000);
        }

        // Entrance animation
        const rows = document.querySelectorAll('tbody tr[data-status]');
        rows.forEach((row, index) => {
            row.style.opacity = '0';
            row.style.transform = 'translateY(8px)';
            setTimeout(() => {
                row.style.transition = 'all 0.35s cubic-bezier(0.16, 1, 0.3, 1)';
                row.style.opacity = '1';
                row.style.transform = 'translateY(0)';
            }, 60 + index * 40);
        });

        // Button press effect
        document.querySelectorAll('button:not([type="submit"])').forEach(el => {
            el.addEventListener('mousedown', () => { el.style.transform = 'scale(0.93)'; });
            el.addEventListener('mouseup', () => { el.style.transform = 'scale(1)'; });
            el.addEventListener('mouseleave', () => { el.style.transform = 'scale(1)'; });
        });
    });
</script>

</body>
</html>
