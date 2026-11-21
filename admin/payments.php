<?php
// Admin: Verify / cancel bank-transfer payments (compact queue + receipt modal)
require_once __DIR__ . '/../includes/session_boot.php';

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
require_once __DIR__ . '/../includes/inventory_helper.php';

inventory_ensure_schema($pdo);

$admin_name = $_SESSION['full_name'];
$admin_role = $_SESSION['role'];
$success_message = '';
$error_message = '';

// ==========================================
// VERIFY A PAYMENT
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && ($_POST['action'] ?? '') === 'verify_payment') {
    verify_csrf_token();
    $payment_id = (int)($_POST['payment_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("UPDATE payments SET status = 'Verified', verified_at = NOW() WHERE payment_id = ? AND status = 'Pending Payment'");
        $stmt->execute([$payment_id]);

        if ($stmt->rowCount() === 0) {
            throw new Exception("Payment is no longer pending.");
        }

        $items = $pdo->prepare("SELECT * FROM payment_items WHERE payment_id = ?");
        $items->execute([$payment_id]);

        while ($row = $items->fetch()) {
            if ($row['item_type'] === 'shop') {
                if (!empty($row['order_id'])) {
                    // Payment settled -> order awaits fulfillment ('Pending').
                    $pdo->prepare("UPDATE Orders SET status = 'Pending' WHERE order_id = ? AND status IN ('Pending Payment', 'Confirmed')")->execute([$row['order_id']]);
                    
                    // Notify player
                    try {
                        notify_order_status_change($pdo, $row['order_id'], 'Pending Payment', 'Pending');
                    } catch (Exception $e) {
                        error_log("Notification failed: " . $e->getMessage());
                    }
                }
                if (!empty($row['order_id'])) {
                    // Idempotent: checkout already reserved the stock, so this is
                    // a no-op for orders placed after the inventory fix.
                    $stock_result = deduct_stock_for_order($pdo, (int)$row['order_id']);
                    if ($stock_result === HP_STOCK_DEDUCT_ERROR) {
                        throw new Exception("Could not update stock for the verified order.");
                    }
                } elseif (!empty($row['product_id'])) {
                    // Legacy item without an order_id — deduct directly.
                    $pdo->prepare("UPDATE Products SET stock_quantity = GREATEST(stock_quantity - ?, 0) WHERE product_id = ?")->execute([(int)$row['quantity'], $row['product_id']]);
                }
            } elseif ($row['item_type'] === 'booking') {
                if (!empty($row['booking_id'])) {
                    $pdo->prepare("UPDATE Bookings SET status = 'Confirmed' WHERE booking_id = ? AND status = 'Pending Payment'")->execute([$row['booking_id']]);
                    
                    // Notify player
                    try {
                        notify_booking_status_change($pdo, $row['booking_id'], 'Pending Payment', 'Confirmed', null);
                    } catch (Exception $e) {
                        error_log("Notification failed: " . $e->getMessage());
                    }
                }
            }
        }

        $pdo->commit();
        $success_message = "Payment verified. Linked orders and bookings are now confirmed.";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_message = "Verification failed: " . $e->getMessage();
    }
}

// ==========================================
// CANCEL / REJECT A PAYMENT (e.g. scam claim)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && ($_POST['action'] ?? '') === 'cancel_payment') {
    verify_csrf_token();
    $payment_id = (int)($_POST['payment_id'] ?? 0);
    $reason = mb_substr(trim($_POST['cancel_reason'] ?? ''), 0, 255);

    try {
        $pdo->beginTransaction();

        $cur = $pdo->prepare("SELECT status FROM payments WHERE payment_id = ?");
        $cur->execute([$payment_id]);
        $was = $cur->fetchColumn();

        if ($was === false) throw new Exception("Payment not found.");
        if ($was === 'Cancelled') throw new Exception("Payment is already cancelled.");

        $was_verified = ($was === 'Verified');

        // Stock is reserved at checkout now, so cancelling a payment must give it
        // back whether or not the payment had already been verified. The helper is
        // idempotent, so repeated cancellations can never inflate inventory.
        $oids = $pdo->prepare("SELECT DISTINCT order_id FROM payment_items
                               WHERE payment_id = ? AND item_type = 'shop' AND order_id IS NOT NULL AND order_id > 0");
        $oids->execute([$payment_id]);
        foreach ($oids->fetchAll(PDO::FETCH_COLUMN) as $oid) {
            if (restore_stock_for_order($pdo, (int)$oid) === HP_STOCK_DEDUCT_ERROR) {
                throw new Exception("Could not return stock for a cancelled order.");
            }
        }

        // Legacy lines without an order_id only had stock deducted when a payment
        // was verified, so only restock those in that case.
        if ($was_verified) {
            $legacy = $pdo->prepare("SELECT product_id, quantity FROM payment_items
                                     WHERE payment_id = ? AND item_type = 'shop'
                                       AND product_id IS NOT NULL
                                       AND (order_id IS NULL OR order_id = 0)");
            $legacy->execute([$payment_id]);
            $restock = $pdo->prepare("UPDATE Products SET stock_quantity = stock_quantity + ? WHERE product_id = ?");
            while ($row = $legacy->fetch(PDO::FETCH_ASSOC)) {
                $restock->execute([(int)$row['quantity'], (int)$row['product_id']]);
            }
        }

        $pdo->prepare("UPDATE payments SET status = 'Cancelled', cancelled_at = NOW(), cancel_reason = ? WHERE payment_id = ?")->execute([$reason, $payment_id]);

        // Get affected orders and bookings for notifications
        $affected_orders = $pdo->prepare("SELECT o.order_id, o.status FROM Orders o JOIN payment_items pi ON o.order_id = pi.order_id WHERE pi.payment_id = ? AND o.status != 'Cancelled'");
        $affected_orders->execute([$payment_id]);
        $orders_to_notify = $affected_orders->fetchAll(PDO::FETCH_ASSOC);
        
        $affected_bookings = $pdo->prepare("SELECT b.booking_id, b.status FROM Bookings b JOIN payment_items pi ON b.booking_id = pi.booking_id WHERE pi.payment_id = ? AND b.status != 'Cancelled'");
        $affected_bookings->execute([$payment_id]);
        $bookings_to_notify = $affected_bookings->fetchAll(PDO::FETCH_ASSOC);

        $pdo->prepare("UPDATE Orders o JOIN payment_items pi ON o.order_id = pi.order_id SET o.status = 'Cancelled' WHERE pi.payment_id = ? AND o.status != 'Cancelled'")->execute([$payment_id]);
        $pdo->prepare("UPDATE Bookings b JOIN payment_items pi ON b.booking_id = pi.booking_id SET b.status = 'Cancelled' WHERE pi.payment_id = ? AND b.status != 'Cancelled'")->execute([$payment_id]);

        $pdo->commit();
        
        // Send notifications after commit
        foreach ($orders_to_notify as $order) {
            try {
                notify_order_status_change($pdo, $order['order_id'], $order['status'], 'Cancelled');
            } catch (Exception $e) {
                error_log("Notification failed: " . $e->getMessage());
            }
        }
        foreach ($bookings_to_notify as $booking) {
            try {
                notify_booking_status_change($pdo, $booking['booking_id'], $booking['status'], 'Cancelled', $reason);
            } catch (Exception $e) {
                error_log("Notification failed: " . $e->getMessage());
            }
        }
        
        $success_message = "Payment cancelled. Linked orders and bookings have been cancelled.";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_message = "Cancel failed: " . $e->getMessage();
    }
}

// ==========================================
// FETCH PAYMENTS
// ==========================================
try {
    $payments_stmt = $pdo->query("
        SELECT p.payment_id, p.payment_reference, p.total_amount, p.status, p.receipt_path, p.notes, p.created_at, p.verified_at, p.cancelled_at, p.cancel_reason, u.full_name, u.email
        FROM payments p
        JOIN Users u ON p.user_id = u.user_id
        ORDER BY (p.status = 'Pending Payment') DESC, p.created_at DESC
    ");
    $payments = $payments_stmt->fetchAll();
} catch (Exception $e) {
    $payments = [];
    $error_message = "The payments tables are missing. Import <code>database_banktransfer.sql</code> once via phpMyAdmin, then reload this page.";
}

$pending_count = 0;
foreach ($payments as $p) { if ($p['status'] === 'Pending Payment') $pending_count++; }
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>Payments | HI-POWER Admin</title>
    <?php include __DIR__ . '/../includes/tailwind_config.php'; ?>
    <style>
        .badge-pending { background:#fffbeb; color:#d97706; border:1px solid #fde68a; }
        .badge-verified { background:#ecfdf5; color:#059669; border:1px solid #a7f3d0; }
        .badge-cancelled { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
        .pay-row { transition: all 0.15s ease; cursor: pointer; }
        .pay-row:hover { background: #f8faff; }
        .pay-row.pending-row { border-left: 4px solid #d97706; }
        .pay-row.verified-row { border-left: 4px solid #059669; }
        .pay-row.cancelled-row { border-left: 4px solid #dc2626; opacity: 0.75; }
        .modal-overlay { background: rgba(15,23,42,0.55); backdrop-filter: blur(2px); }
        .modal-card { animation: modalIn 0.22s ease-out; }
        @keyframes modalIn { from { opacity: 0; transform: translateY(14px) scale(0.98); } to { opacity: 1; transform: none; } }
    </style>
</head>
<body class="bg-background min-h-screen">

<form id="verifyForm" method="POST" action="payments" style="display:none;">
    <?php echo get_csrf_input(); ?>
    <input type="hidden" name="action" value="verify_payment">
    <input type="hidden" name="payment_id" id="verifyPaymentId">
</form>
<form id="cancelForm" method="POST" action="payments" style="display:none;">
    <?php echo get_csrf_input(); ?>
    <input type="hidden" name="action" value="cancel_payment">
    <input type="hidden" name="payment_id" id="cancelPaymentId">
    <input type="hidden" name="cancel_reason" id="cancelReason">
</form>

<?php include __DIR__ . '/includes/admin_header.php'; ?>

<main class="flex-1 ml-0 md:ml-64 p-margin-md min-h-screen">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-on-surface">Payments</h1>
            <p class="text-sm text-on-surface-variant">Tap a row to inspect and verify GCash/Maya or Pay-at-Counter payments</p>
        </div>
        <?php if ($pending_count > 0): ?>
            <span class="badge-pending px-3 py-1.5 rounded-full text-xs font-bold"><?php echo $pending_count; ?> pending</span>
        <?php endif; ?>
    </div>

    <?php if (!empty($success_message)): ?>
        <div class="mb-6 p-4 bg-primary-container/20 border border-primary/50 rounded-lg flex items-center gap-2 shadow-sm">
            <span class="material-symbols-outlined text-primary">check_circle</span>
            <p class="text-primary text-sm font-bold"><?php echo htmlspecialchars($success_message); ?></p>
        </div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
        <div class="mb-6 p-4 bg-error-container/20 border border-error/50 rounded-lg flex items-center gap-2 shadow-sm">
            <span class="material-symbols-outlined text-error">error</span>
            <p class="text-error text-sm font-bold"><?php echo htmlspecialchars($error_message); ?></p>
        </div>
    <?php endif; ?>

    <?php if (empty($payments)): ?>
        <div class="bg-white border border-dashed border-outline-variant/60 rounded-2xl p-16 text-center">
            <span class="material-symbols-outlined text-5xl text-outline/40">account_balance_wallet</span>
            <p class="text-on-surface-variant mt-3">No payments yet.</p>
        </div>
    <?php else: ?>
    <div class="bg-white rounded-2xl border border-outline-variant shadow-kinetic overflow-hidden">
        <!-- Column headers -->
        <div class="hidden md:grid grid-cols-12 gap-3 px-5 py-3 bg-surface/80 border-b border-outline-variant/60 text-[10px] font-bold uppercase tracking-widest text-outline">
            <div class="col-span-4">Payment</div>
            <div class="col-span-3">Customer</div>
            <div class="col-span-2">Submitted</div>
            <div class="col-span-1 text-right">Amount</div>
            <div class="col-span-2 flex justify-end">Actions</div>
        </div>

        <?php foreach ($payments as $p):
            $is_pending   = $p['status'] === 'Pending Payment';
            $is_verified  = $p['status'] === 'Verified';
            $is_cancelled = $p['status'] === 'Cancelled';
            $row_class = $is_pending ? 'pending-row' : ($is_verified ? 'verified-row' : 'cancelled-row');
            $badge_class = $is_cancelled ? 'badge-cancelled' : ($is_verified ? 'badge-verified' : 'badge-pending');
        ?>
        <!-- Compact row -->
        <div class="pay-row <?php echo $row_class; ?> grid grid-cols-1 md:grid-cols-12 gap-2 md:gap-3 items-center px-5 py-3.5 border-b border-outline-variant/40" onclick="openReceipt(<?php echo $p['payment_id']; ?>)">
            <div class="col-span-4 md:flex md:items-center md:gap-3 min-w-0">
                <div class="w-9 h-9 rounded-lg <?php echo $is_cancelled ? 'bg-red-50 text-red-600' : ($is_verified ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600'); ?> flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-[18px]"><?php echo $is_cancelled ? 'block' : ($is_verified ? 'verified' : 'schedule'); ?></span>
                </div>
                <div class="min-w-0">
                    <p class="font-bold text-on-surface text-sm truncate"><?php echo htmlspecialchars($p['payment_reference']); ?></p>
                    <span class="md:hidden badge-inline px-2 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-widest <?php echo $badge_class; ?>"><?php echo htmlspecialchars($p['status']); ?></span>
                </div>
            </div>
            <div class="col-span-3 text-sm text-on-surface-variant truncate min-w-0"><?php echo htmlspecialchars($p['full_name']); ?></div>
            <div class="col-span-2 text-sm text-outline"><?php echo date('M d, Y', strtotime($p['created_at'])); ?></div>
            <div class="col-span-1 md:text-right text-sm font-bold text-on-surface">&#8369;<?php echo number_format($p['total_amount'], 2); ?></div>
            <div class="col-span-2 flex items-center md:justify-end gap-2 shrink-0">
                <span class="hidden md:inline px-2 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-widest <?php echo $badge_class; ?>"><?php echo htmlspecialchars($p['status']); ?></span>
                <button type="button" onclick="openReceipt(<?php echo $p['payment_id']; ?>)" class="px-3 py-1.5 rounded-lg bg-primary/10 text-primary font-bold text-xs hover:bg-primary/20 transition-colors inline-flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[15px]"><?php echo empty($p['receipt_path']) ? 'storefront' : 'receipt_long'; ?></span> <?php echo empty($p['receipt_path']) ? 'Details' : 'Receipt'; ?>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<!-- ============ RECEIPT MODAL ============ -->
<div id="payModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="modal-overlay fixed inset-0" onclick="closeModal()"></div>
    <div class="relative min-h-full flex items-start justify-center p-4 sm:p-8 pt-16">
        <?php foreach ($payments as $p):
            $is_pending   = $p['status'] === 'Pending Payment';
            $is_verified  = $p['status'] === 'Verified';
            $is_cancelled = $p['status'] === 'Cancelled';
            $badge_class  = $is_cancelled ? 'badge-cancelled' : ($is_verified ? 'badge-verified' : 'badge-pending');
            $items = $pdo->prepare("SELECT * FROM payment_items WHERE payment_id = ? ORDER BY payment_item_id ASC");
            $items->execute([$p['payment_id']]);
            $p_items = $items->fetchAll();
        ?>
        <div id="payDetail-<?php echo $p['payment_id']; ?>" class="hidden modal-card w-full max-w-3xl bg-white rounded-2xl border border-outline-variant shadow-2xl overflow-hidden mb-8">
            <!-- Modal header -->
            <div class="flex items-center justify-between gap-3 px-6 py-4 bg-surface/70 border-b border-outline-variant/60">
                <div class="flex items-center gap-3">
                    <span class="w-10 h-10 rounded-xl <?php echo $is_cancelled ? 'bg-red-50 text-red-600' : ($is_verified ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600'); ?> flex items-center justify-center">
                        <span class="material-symbols-outlined">receipt_long</span>
                    </span>
                    <div>
                        <p class="font-bold text-on-surface"><?php echo htmlspecialchars($p['payment_reference']); ?></p>
                        <p class="text-xs text-on-surface-variant"><?php echo htmlspecialchars($p['full_name']); ?> &middot; <?php echo htmlspecialchars($p['email']); ?></p>
                    </div>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-widest <?php echo $badge_class; ?>"><?php echo htmlspecialchars($p['status']); ?></span>
                    <button type="button" onclick="closeModal()" class="w-9 h-9 rounded-lg bg-surface-container hover:bg-surface-container-high text-on-surface flex items-center justify-center">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-5 p-6">
                <!-- Receipt -->
                <div class="md:col-span-1">
                    <?php if (!empty($p['receipt_path'])): ?>
                    <p class="text-[10px] font-bold text-outline uppercase tracking-widest mb-2">Transfer Receipt</p>
                    <a href="<?php echo htmlspecialchars($p['receipt_path']); ?>" target="_blank" class="block border border-outline-variant rounded-xl overflow-hidden group">
                        <img src="<?php echo htmlspecialchars($p['receipt_path']); ?>" alt="Transfer receipt" class="w-full h-44 object-cover bg-surface-variant group-hover:scale-105 transition-transform duration-300">
                    </a>
                    <?php else: ?>
                    <p class="text-[10px] font-bold text-outline uppercase tracking-widest mb-2">Payment Method</p>
                    <div class="w-full h-44 rounded-xl border border-dashed border-amber-300 bg-amber-50 flex flex-col items-center justify-center text-center px-4">
                        <span class="material-symbols-outlined text-amber-500 text-4xl">storefront</span>
                        <p class="mt-2 text-sm font-bold text-amber-700">Pay at Counter</p>
                        <p class="text-[11px] text-amber-600 mt-1">No online receipt required. Confirm when cash is received.</p>
                    </div>
                    <?php endif; ?>
                    <p class="text-[11px] text-outline mt-2">Submitted <?php echo date('M d, Y g:i A', strtotime($p['created_at'])); ?></p>
                    <?php if ($is_verified && !empty($p['verified_at'])): ?>
                        <p class="text-[11px] text-emerald-600 font-bold">Verified <?php echo date('M d, Y g:i A', strtotime($p['verified_at'])); ?></p>
                    <?php endif; ?>
                    <?php if ($is_cancelled): ?>
                        <p class="text-[11px] text-red-600 font-bold">Cancelled <?php echo !empty($p['cancelled_at']) ? date('M d, Y g:i A', strtotime($p['cancelled_at'])) : ''; ?></p>
                        <?php if (!empty($p['cancel_reason'])): ?>
                            <p class="text-[11px] text-red-600 mt-1"><strong class="font-bold">Reason:</strong> <?php echo nl2br(htmlspecialchars($p['cancel_reason'])); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Items / notes -->
                <div class="md:col-span-2">
                    <p class="text-[10px] font-bold text-outline uppercase tracking-widest mb-2">Covers (<?php echo count($p_items); ?>)</p>
                    <div class="space-y-2">
                        <?php foreach ($p_items as $pi):
                            $icon = $pi['item_type'] === 'shop' ? 'shopping_bag' : 'stadium';
                            $type_label = $pi['item_type'] === 'shop' ? 'Shop Item' : 'Court Booking';
                        ?>
                        <div class="flex items-center gap-3 bg-surface rounded-xl border border-outline-variant/60 px-4 py-3">
                            <span class="w-9 h-9 rounded-lg <?php echo $pi['item_type'] === 'shop' ? 'bg-accent/10 text-accent' : 'bg-primary/10 text-primary'; ?> flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-[18px]"><?php echo $icon; ?></span>
                            </span>
                            <div class="min-w-0 flex-grow">
                                <p class="text-sm font-bold text-on-surface truncate"><?php echo htmlspecialchars($pi['description']); ?></p>
                                <p class="text-[11px] text-outline font-bold uppercase tracking-widest"><?php echo $type_label; ?><?php echo $pi['quantity'] > 1 ? ' &times; ' . (int)$pi['quantity'] : ''; ?></p>
                            </div>
                            <span class="font-bold text-on-surface text-sm shrink-0">&#8369;<?php echo number_format($pi['amount'], 2); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-4 flex items-center justify-between bg-surface rounded-xl border border-outline-variant/60 px-4 py-3">
                        <span class="text-sm font-bold text-on-surface">Total Due / Paid</span>
                        <span class="text-xl font-bold text-primary">&#8369;<?php echo number_format($p['total_amount'], 2); ?></span>
                    </div>

                    <?php if (!empty($p['notes'])): ?>
                        <p class="mt-3 text-[11px] text-on-surface-variant bg-surface rounded-lg border border-outline-variant/60 px-3 py-2"><strong class="font-bold">Notes:</strong> <?php echo nl2br(htmlspecialchars($p['notes'])); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Footer actions -->
            <div class="px-6 py-4 border-t border-outline-variant/50 bg-surface/60 flex items-center justify-between gap-3">
                <p class="text-[11px] text-outline"><?php echo $is_cancelled ? 'Rejected &mdash; orders/bookings are voided.' : (empty($p['receipt_path']) ? 'Confirm the cash payment at the counter before verifying.' : 'Compare with the exact transfer amount before deciding.'); ?></p>
                <div class="flex items-center gap-2 shrink-0">
                    <button type="button" onclick="closeModal()" class="px-4 py-2.5 rounded-xl font-bold text-sm bg-surface-container-highest text-on-surface-variant hover:bg-outline-variant/30 transition-colors">Close</button>
                    <?php if ($is_pending): ?>
                        <button type="button" onclick="cancelPayment(<?php echo $p['payment_id']; ?>)" class="px-4 py-2.5 rounded-xl font-bold text-sm inline-flex items-center gap-2 bg-white text-error border-2 border-error/40 hover:bg-error/5 transition-colors">
                            <span class="material-symbols-outlined text-[18px]">block</span> Cancel
                        </button>
                        <button type="button" onclick="verifyPayment(<?php echo $p['payment_id']; ?>)" class="btn-primary px-4 py-2.5 rounded-xl font-bold text-sm inline-flex items-center gap-2">
                            <span class="material-symbols-outlined text-[18px]">verified_user</span> Verify
                        </button>
                    <?php elseif ($is_verified): ?>
                        <button type="button" onclick="cancelPayment(<?php echo $p['payment_id']; ?>)" class="px-4 py-2.5 rounded-xl font-bold text-sm inline-flex items-center gap-2 bg-white text-error border-2 border-error/40 hover:bg-error/5 transition-colors">
                            <span class="material-symbols-outlined text-[18px]">undo</span> Cancel &amp; Restore Stock
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
    function openReceipt(paymentId) {
        const section = document.getElementById('payDetail-' + paymentId);
        if (!section) return;
        document.querySelectorAll('#payModal [id^="payDetail-"]').forEach(el => el.classList.add('hidden'));
        section.classList.remove('hidden');
        document.getElementById('payModal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        document.getElementById('payModal').classList.add('hidden');
        document.body.style.overflow = '';
    }

    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

    function verifyPayment(paymentId) {
        if (confirm('Verify this payment? The linked orders and bookings will be confirmed.')) {
            document.getElementById('verifyPaymentId').value = paymentId;
            document.getElementById('verifyForm').submit();
        }
    }

    function cancelPayment(paymentId) {
        const reason = prompt(
            "Cancel this payment?\n\nLinked orders and court bookings will be CANCELLED. If it was already verified, product stock is restored.\n\nYou can add a reason for the record below.",
            'Suspected fraudulent transfer'
        );
        if (reason === null) return;
        document.getElementById('cancelPaymentId').value = paymentId;
        document.getElementById('cancelReason').value = (reason || '').slice(0, 255);
        document.getElementById('cancelForm').submit();
    }
</script>
</body>
</html>