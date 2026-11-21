<?php
// includes/bank_details.php
// Editable GCash/Maya + store pickup details shown during checkout.

function bankAccountDetails(): array {
    return [
        [
            'bank'           => 'GCash',
            'account_name'   => 'Edrian Senar',
            'account_number' => '09919903646',
            'type'           => 'GCash',
            'qr'             => '/img/qr/gcash_qr.JPG',
        ],
        [
            'bank'           => 'Maya',
            'account_name'   => 'Edrian Senar',
            'account_number' => '09919903646',
            'type'           => 'Maya',
            'qr'             => '/img/qr/maya_qr.JPG',
        ],
    ];
}

function storePickupInfo(): array {
    return [
        'name'         => 'Hi-Power Badminton Court',
        'address'      => '8000 BBL Compound, Nat\'l Highway, Canlalay, Biñan, Laguna (Landmark: MFS Wheels)',
        'hours'        => 'Mon–Sun, 9:00 AM – 10:00 PM',
        'requirements' => 'Bring your Order/Payment Reference and a valid government-issued ID (e.g., national ID, driver\'s license) when claiming.',
    ];
}

function generatePaymentReference(): string {
    return 'PAY-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}