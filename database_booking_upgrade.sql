-- ============================================================
-- Booking first-come-first-served upgrade
-- Run ONCE via phpMyAdmin.
-- ============================================================

CREATE TABLE IF NOT EXISTS booking_locks (
    court_id      INT NOT NULL,
    booking_date  DATE NOT NULL,
    PRIMARY KEY (court_id, booking_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ensure created_at exists on Bookings.
ALTER TABLE Bookings
    ADD COLUMN IF NOT EXISTS created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP;

-- Index to speed up availability checks.
CREATE INDEX IF NOT EXISTS idx_booking_court_date ON Bookings (court_id, booking_date, start_time);

-- Ensure refunds.proof_path exists.
ALTER TABLE refunds
    ADD COLUMN IF NOT EXISTS proof_path VARCHAR(255) NULL;
