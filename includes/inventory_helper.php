<?php
/**
 * Inventory helper — single source of truth for product stock movements.
 *
 * Stock is reserved when a shop order is placed and returned when that order is
 * cancelled. Every movement is guarded by Orders.stock_deducted so the same
 * order can never reduce (or restore) stock twice, even if it is touched by
 * both the checkout flow and the admin payment/order screens.
 *
 * Call inventory_ensure_schema($pdo) once at page bootstrap, BEFORE any
 * transaction is opened (ALTER TABLE implicitly commits a MySQL transaction).
 */

if (!defined('HP_LOW_STOCK_THRESHOLD')) {
    define('HP_LOW_STOCK_THRESHOLD', 5);
}

if (!defined('HP_STOCK_DEDUCT_OK')) {
    define('HP_STOCK_DEDUCT_OK', 1);      // stock was changed by this call
    define('HP_STOCK_DEDUCT_SKIPPED', 0); // already applied / nothing to do
    define('HP_STOCK_DEDUCT_ERROR', -1);  // failed — caller should abort
}

if (!function_exists('inventory_ensure_schema')) {
    /**
     * Lazily add the idempotency flag to Orders. Safe to call on every request.
     * Returns true when the schema is ready for stock operations.
     */
    function inventory_ensure_schema(PDO $pdo): bool {
        static $ready = [];

        $key = spl_object_id($pdo);
        if (!empty($ready[$key])) {
            return true;
        }

        try {
            $column = $pdo->query("SHOW COLUMNS FROM Orders LIKE 'stock_deducted'")->fetch();
            if (!$column) {
                // Never ALTER inside a transaction: MySQL would commit the caller's work.
                if ($pdo->inTransaction()) {
                    error_log('Inventory schema update deferred: cannot ALTER TABLE inside a transaction.');
                    return false;
                }
                $pdo->exec("ALTER TABLE Orders ADD COLUMN stock_deducted TINYINT(1) NOT NULL DEFAULT 0");
            }
            $ready[$key] = true;
            return true;
        } catch (Throwable $e) {
            error_log('Inventory schema check failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('deduct_stock_for_order')) {
    /**
     * Reserve the items of an order (idempotent).
     *
     * @return int HP_STOCK_DEDUCT_OK | HP_STOCK_DEDUCT_SKIPPED | HP_STOCK_DEDUCT_ERROR
     */
    function deduct_stock_for_order(PDO $pdo, int $order_id): int {
        if ($order_id <= 0 || !inventory_ensure_schema($pdo)) {
            return HP_STOCK_DEDUCT_ERROR;
        }

        $owns_transaction = !$pdo->inTransaction();
        if ($owns_transaction) {
            $pdo->beginTransaction();
        }

        try {
            // Claim the order first. Only the request that flips 0 -> 1 may move stock.
            $claim = $pdo->prepare("UPDATE Orders SET stock_deducted = 1 WHERE order_id = ? AND stock_deducted = 0");
            $claim->execute([$order_id]);
            if ($claim->rowCount() === 0) {
                if ($owns_transaction) {
                    $pdo->commit();
                }
                return HP_STOCK_DEDUCT_SKIPPED;
            }

            $items = $pdo->prepare("SELECT product_id, quantity FROM payment_items
                                    WHERE order_id = ? AND item_type = 'shop'
                                      AND product_id IS NOT NULL AND quantity > 0");
            $items->execute([$order_id]);

            $update = $pdo->prepare("UPDATE Products
                                     SET stock_quantity = GREATEST(stock_quantity - ?, 0)
                                     WHERE product_id = ?");
            while ($row = $items->fetch(PDO::FETCH_ASSOC)) {
                $update->execute([(int)$row['quantity'], (int)$row['product_id']]);
            }

            if ($owns_transaction) {
                $pdo->commit();
            }
            return HP_STOCK_DEDUCT_OK;
        } catch (Throwable $e) {
            if ($owns_transaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Stock deduction failed for order ' . $order_id . ': ' . $e->getMessage());
            return HP_STOCK_DEDUCT_ERROR;
        }
    }
}

if (!function_exists('restore_stock_for_order')) {
    /**
     * Return an order's items to stock (idempotent; only undoes a real deduction).
     *
     * @return int HP_STOCK_DEDUCT_OK | HP_STOCK_DEDUCT_SKIPPED | HP_STOCK_DEDUCT_ERROR
     */
    function restore_stock_for_order(PDO $pdo, int $order_id): int {
        if ($order_id <= 0 || !inventory_ensure_schema($pdo)) {
            return HP_STOCK_DEDUCT_ERROR;
        }

        $owns_transaction = !$pdo->inTransaction();
        if ($owns_transaction) {
            $pdo->beginTransaction();
        }

        try {
            $release = $pdo->prepare("UPDATE Orders SET stock_deducted = 0 WHERE order_id = ? AND stock_deducted = 1");
            $release->execute([$order_id]);
            if ($release->rowCount() === 0) {
                if ($owns_transaction) {
                    $pdo->commit();
                }
                return HP_STOCK_DEDUCT_SKIPPED;
            }

            $items = $pdo->prepare("SELECT product_id, quantity FROM payment_items
                                    WHERE order_id = ? AND item_type = 'shop'
                                      AND product_id IS NOT NULL AND quantity > 0");
            $items->execute([$order_id]);

            $update = $pdo->prepare("UPDATE Products SET stock_quantity = stock_quantity + ? WHERE product_id = ?");
            while ($row = $items->fetch(PDO::FETCH_ASSOC)) {
                $update->execute([(int)$row['quantity'], (int)$row['product_id']]);
            }

            if ($owns_transaction) {
                $pdo->commit();
            }
            return HP_STOCK_DEDUCT_OK;
        } catch (Throwable $e) {
            if ($owns_transaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Stock restore failed for order ' . $order_id . ': ' . $e->getMessage());
            return HP_STOCK_DEDUCT_ERROR;
        }
    }
}

if (!function_exists('shop_stock_issue')) {
    /**
     * Server-side guard against ordering more than is available.
     * Returns a customer-friendly message, or null when stock is sufficient.
     */
    function shop_stock_issue(PDO $pdo, array $shop_lines): ?string {
        $needed = [];
        foreach ($shop_lines as $line) {
            $pid = (int)($line['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $needed[$pid] = ($needed[$pid] ?? 0) + max(0, (int)($line['quantity'] ?? 0));
        }
        if (!$needed) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($needed), '?'));
        $stmt = $pdo->prepare("SELECT product_id, name, stock_quantity FROM Products WHERE product_id IN ($placeholders)");
        $stmt->execute(array_keys($needed));

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pid = (int)$row['product_id'];
            $available = (int)$row['stock_quantity'];
            if ($needed[$pid] > $available) {
                return 'Not enough stock for ' . $row['name'] . '. Only ' . $available . ' left.';
            }
        }
        return null;
    }
}

if (!function_exists('low_stock_count')) {
    /** Number of products at or below the low-stock threshold (out of stock included). */
    function low_stock_count(PDO $pdo, int $threshold = HP_LOW_STOCK_THRESHOLD): int {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM Products WHERE stock_quantity <= ?");
            $stmt->execute([max(0, $threshold)]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('get_low_stock_products')) {
    /** Low-stock products, emptiest first, for admin alerts. */
    function get_low_stock_products(PDO $pdo, int $threshold = HP_LOW_STOCK_THRESHOLD, int $limit = 5): array {
        $limit = max(1, min(50, $limit));
        try {
            $stmt = $pdo->prepare("SELECT product_id, name, stock_quantity, is_active
                                   FROM Products
                                   WHERE stock_quantity <= ?
                                   ORDER BY stock_quantity ASC, name ASC
                                   LIMIT $limit");
            $stmt->execute([max(0, $threshold)]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}
