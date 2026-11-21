<?php
/**
 * Notification Helper
 * Simple notification system for status changes
 */

if (!function_exists('create_notification')) {
    /**
     * Create a notification for a user
     * @param PDO $pdo Database connection
     * @param int $user_id User to notify
     * @param string $type Notification type (booking_status_changed, order_status_changed)
     * @param string $title Notification title
     * @param string $message Notification message
     * @param int|null $booking_id Related booking ID
     * @param int|null $order_id Related order ID
     * @return bool Success
     */
    function create_notification(PDO $pdo, int $user_id, string $type, string $title, string $message, ?int $booking_id = null, ?int $order_id = null): bool {
        try {
            // Ensure table exists (migration-safe)
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
                    notification_id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    type VARCHAR(50) NOT NULL,
                    title VARCHAR(200) NOT NULL,
                    message TEXT NOT NULL,
                    booking_id INT NULL,
                    order_id INT NULL,
                    is_read TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_read (user_id, is_read),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB");
            } catch (PDOException $e) {
                // Table already exists
            }

            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, booking_id, order_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$user_id, $type, $title, $message, $booking_id, $order_id]);
            return true;
        } catch (Exception $e) {
            // Log error but don't break the main action
            error_log("Notification creation failed: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('notify_booking_status_change')) {
    /**
     * Send notification when booking status changes
     * @param PDO $pdo Database connection
     * @param int $booking_id Booking ID
     * @param string $old_status Previous status
     * @param string $new_status New status
     * @param string|null $reason Optional reason (for cancellations)
     */
    function notify_booking_status_change(PDO $pdo, int $booking_id, string $old_status, string $new_status, ?string $reason = null): void {
        try {
            // Get booking details
            $stmt = $pdo->prepare("
                SELECT b.user_id, b.booking_reference, c.name as court_name, b.booking_date, b.start_time
                FROM Bookings b
                JOIN Courts c ON b.court_id = c.court_id
                WHERE b.booking_id = ?
            ");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$booking) return;

            $date_str = date('M d, Y', strtotime($booking['booking_date']));
            $time_str = date('h:i A', strtotime($booking['start_time']));
            
            // Map status to user-friendly message
            $status_map = [
                'Pending' => 'Pending',
                'Pending Payment' => 'Pending',
                'Confirmed' => 'Accepted',
                'Paid' => 'Accepted',
                'Completed' => 'Accepted',
                'Cancelled' => 'Cancelled',
                'Expired' => 'Cancelled'
            ];
            
            $display_status = $status_map[$new_status] ?? $new_status;
            
            if ($display_status === 'Cancelled') {
                $title = "Booking Cancelled";
                $message = "Your booking {$booking['booking_reference']} for {$booking['court_name']} on {$date_str} at {$time_str} has been cancelled.";
                if ($reason) {
                    $message .= " Reason: " . htmlspecialchars($reason);
                }
            } elseif ($display_status === 'Accepted' && in_array($old_status, ['Pending', 'Pending Payment', 'In Cart'], true)) {
                $title = "Booking Confirmed";
                $message = "Your booking {$booking['booking_reference']} for {$booking['court_name']} on {$date_str} at {$time_str} has been confirmed!";
            } else {
                $title = "Booking Updated";
                $message = "Your booking {$booking['booking_reference']} status is now: {$display_status}";
            }
            
            create_notification($pdo, $booking['user_id'], 'booking_status_changed', $title, $message, $booking_id, null);
        } catch (Exception $e) {
            error_log("Booking notification failed: " . $e->getMessage());
        }
    }
}

if (!function_exists('notify_order_status_change')) {
    /**
     * Send notification when order status changes
     * @param PDO $pdo Database connection
     * @param int $order_id Order ID
     * @param string $old_status Previous status
     * @param string $new_status New status
     */
    function notify_order_status_change(PDO $pdo, int $order_id, string $old_status, string $new_status): void {
        try {
            // Get order details
            $stmt = $pdo->prepare("SELECT user_id, total_amount FROM Orders WHERE order_id = ?");
            $stmt->execute([$order_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) return;

            $order_ref = '#ORD-' . str_pad($order_id, 5, '0', STR_PAD_LEFT);
            $amount = '₱' . number_format($order['total_amount'], 2);
            
            // Map status to user-friendly message
            $status_map = [
                'Pending' => 'Pending',
                'Pending Payment' => 'Pending',
                'Processing' => 'Accepted',
                'Confirmed' => 'Accepted',
                'Completed' => 'Accepted',
                'Delivered' => 'Accepted',
                'Cancelled' => 'Cancelled'
            ];
            
            $display_status = $status_map[$new_status] ?? $new_status;
            
            if ($display_status === 'Cancelled') {
                $title = "Order Cancelled";
                $message = "Your order {$order_ref} ({$amount}) has been cancelled.";
            } elseif ($display_status === 'Accepted' && in_array($old_status, ['Pending', 'Pending Payment'], true)) {
                $title = "Order Confirmed";
                $message = "Your order {$order_ref} ({$amount}) has been confirmed and is being processed!";
            } elseif ($new_status === 'Completed' || $new_status === 'Delivered') {
                $title = "Order Completed";
                $message = "Your order {$order_ref} ({$amount}) has been completed and is ready for pickup!";
            } else {
                $title = "Order Updated";
                $message = "Your order {$order_ref} status is now: {$display_status}";
            }
            
            create_notification($pdo, $order['user_id'], 'order_status_changed', $title, $message, null, $order_id);
        } catch (Exception $e) {
            error_log("Order notification failed: " . $e->getMessage());
        }
    }
}
