<?php
require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/database_connect.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Terms &amp; Conditions | HI-POWER Badminton Court</title>
    <?php include __DIR__ . '/includes/tailwind_config.php'; ?>
    <style>
        .section-box { scroll-margin-top: 90px; }
        .term-num { color: #ff5722; font-weight: 700; }
    </style>
</head>
<body class="bg-page text-on-surface min-h-screen flex flex-col">
    <?php include __DIR__ . '/includes/nav_bar.php'; ?>

    <main class="flex-grow w-full max-w-4xl mx-auto px-4 md:px-6 py-10 md:py-14">
        <div class="bg-white rounded-2xl border border-outline-variant/60 shadow-soft p-6 sm:p-10 md:p-12">
            <h1 class="text-3xl font-extrabold text-on-surface mb-2">Terms &amp; Conditions</h1>
            <p class="text-sm text-gray-400 mb-10">Effective date: October 2, 2026</p>

            <section id="terms" class="section-box space-y-5 text-[15px] leading-relaxed text-on-surface-variant">
                <h2 class="text-xl font-bold text-on-surface pt-2">Terms &amp; Conditions</h2>

                <p>Welcome to HI-POWER, a badminton court booking and sports analytics platform. By creating an account, accessing our website, or using our services, you agree to be bound by these Terms &amp; Conditions and our Privacy Policy.</p>

                <div>
                    <p><span class="term-num">1.</span> <strong class="text-on-surface">Acceptance of Terms</strong></p>
                    <p>By registering an account, you confirm that you have read, understood, and agree to these Terms. If you do not agree, you may not use our services.</p>
                </div>

                <div>
                    <p><span class="term-num">2.</span> <strong class="text-on-surface">Accounts</strong></p>
                    <p>You must provide accurate and complete information when creating an account. You are responsible for safeguarding your password and for all activity under your account. Notify us immediately of any unauthorized use.</p>
                </div>

                <div>
                    <p><span class="term-num">3.</span> <strong class="text-on-surface">Court Bookings, Cancellations &amp; Refunds</strong></p>
                    <p>Bookings are subject to availability and are confirmed only after successful payment. Please review your selected date and time before paying. No-shows may be charged in full.</p>
                </div>

                <div>
                    <p><span class="term-num">4.</span> <strong class="text-on-surface">Payments &amp; Refund Policy</strong></p>
                    <p>All payments are processed through third-party/manual payment methods as shown at checkout. Prices are stated in Philippine Pesos (PHP) unless otherwise indicated.</p>
                    <ul class="list-disc pl-5 mt-2 space-y-1 text-on-surface-variant">
                        <li><strong class="text-on-surface">Cancellation window:</strong> Court bookings may be cancelled only at least <strong class="text-on-surface">24 hours before</strong> the scheduled start time. Cancellations within 24 hours of the slot are not permitted.</li>
                        <li><strong class="text-on-surface">Refund request window:</strong> After cancelling, a refund request must be submitted within <strong class="text-on-surface">24 hours of the cancellation</strong>. Requests made beyond that window are no longer accepted.</li>
                        <li><strong class="text-on-surface">Full refund:</strong> Approved and verified requests qualify for a <strong class="text-on-surface">100% full refund</strong> of the total amount paid, processed manually upon admin review and payment verification. Submitting a request does not guarantee a refund.</li>
                    </ul>
                </div>

                <div>
                    <p><span class="term-num">5.</span> <strong class="text-on-surface">Acceptable Use</strong></p>
                    <p>You agree not to misuse the platform, including attempting to disrupt services, accessing other users' accounts, reselling bookings without authorization, or uploading unlawful content.</p>
                </div>

                <div>
                    <p><span class="term-num">6.</span> <strong class="text-on-surface">AI-Powered Analysis</strong></p>
                    <p>HI-POWER provides AI-based movement and form analysis using uploaded media. Results are provided for guidance only and are not a substitute for professional coaching or medical advice. We are not liable for decisions made based on these insights.</p>
                </div>

                <div>
                    <p><span class="term-num">7.</span> <strong class="text-on-surface">Limitation of Liability</strong></p>
                    <p>To the maximum extent permitted by law, HI-POWER shall not be liable for any indirect, incidental, or consequential damages arising from your use of the platform, including but not limited to loss of bookings, data, or revenue.</p>
                </div>

                <div>
                    <p><span class="term-num">8.</span> <strong class="text-on-surface">Termination</strong></p>
                    <p>We may suspend or terminate your account if you violate these Terms. You may close your account at any time by contacting support.</p>
                </div>

                <div>
                    <p><span class="term-num">9.</span> <strong class="text-on-surface">Changes to These Terms</strong></p>
                    <p>We may update these Terms from time to time. Continued use of the platform after changes are posted constitutes acceptance of the revised Terms.</p>
                </div>

                <div>
                    <p><span class="term-num">10.</span> <strong class="text-on-surface">Contact</strong></p>
                    <p>For questions about these Terms, contact us at <a href="mailto:support@hipowerbc.xyz" class="text-brand-blue hover:underline font-medium">support@hipowerbc.xyz</a>.</p>
                </div>
            </section>

            <hr class="my-12 border-outline-variant">

            <section id="privacy" class="section-box space-y-5 text-[15px] leading-relaxed text-on-surface-variant">
                <h2 class="text-xl font-bold text-on-surface pt-2">Privacy Policy</h2>

                <p>Your privacy matters to us. This policy explains what information we collect, how we use it, and the choices you have.</p>

                <div>
                    <p><span class="term-num">1.</span> <strong class="text-on-surface">Information We Collect</strong></p>
                    <p>We collect the information you provide when registering, such as your name, email address, contact details, and skill level. When you book or purchase, we collect booking and transaction records.</p>
                </div>

                <div>
                    <p><span class="term-num">2.</span> <strong class="text-on-surface">Media &amp; AI Analysis</strong></p>
                    <p>When you upload images or videos for AI analysis, we process them solely to generate your movement insights. Media is used only to provide the requested analysis and is not sold or shared with third parties.</p>
                </div>

                <div>
                    <p><span class="term-num">3.</span> <strong class="text-on-surface">Payments</strong></p>
                    <p>Payment details are handled by our third-party payment processors. We do not store card numbers or sensitive payment credentials on our servers.</p>
                </div>

                <div>
                    <p><span class="term-num">4.</span> <strong class="text-on-surface">How We Use Data</strong></p>
                    <p>We use your data to manage your account, process bookings and payments, deliver AI analysis, improve our services, and send service-related communications. We never sell your personal data.</p>
                </div>

                <div>
                    <p><span class="term-num">5.</span> <strong class="text-on-surface">Third-Party Services</strong></p>
                    <p>We may use trusted third-party providers (e.g., payment gateways, analytics, Cloudflare) who process data only under our instruction.</p>
                </div>

                <div>
                    <p><span class="term-num">6.</span> <strong class="text-on-surface">Cookies &amp; Local Storage</strong></p>
                    <p>We use cookies and local storage to keep you signed in and remember your preferences. You can disable cookies in your browser, though some features may not work.</p>
                </div>

                <div>
                    <p><span class="term-num">7.</span> <strong class="text-on-surface">Data Security</strong></p>
                    <p>Passwords are stored as irreversible hashes, and access to personal data is restricted to authorized personnel only.</p>
                </div>

                <div>
                    <p><span class="term-num">8.</span> <strong class="text-on-surface">Your Rights</strong></p>
                    <p>You may request access to, correction of, or deletion of your personal data at any time by contacting <a href="mailto:support@hipowerbc.xyz" class="text-brand-blue hover:underline font-medium">support@hipowerbc.xyz</a>.</p>
                </div>
            </section>
        </div>

        <p class="text-center text-xs text-on-surface-variant mt-8">&copy; <?php echo date('Y'); ?> HI-POWER Badminton Court. All rights reserved.</p>
    </main>

    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>