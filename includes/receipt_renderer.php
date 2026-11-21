<?php
// includes/receipt_renderer.php
// Renders a printable shop-order receipt as a PNG image (GD + bundled
// DejaVu fonts) or, when GD/FreeType is unavailable, as an SVG image.
// Both paths share the same layout so the receipt looks the same everywhere.

if (!function_exists('hp_receipt_font_path')) {
    function hp_receipt_font_path(bool $bold): ?string {
        $file = $bold ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf';
        $path = dirname(__DIR__) . '/assets/fonts/' . $file;
        return is_file($path) ? $path : null;
    }
}

if (!function_exists('hp_receipt_gd_available')) {
    function hp_receipt_gd_available(): bool {
        return function_exists('imagecreatetruecolor')
            && function_exists('imagettftext')
            && function_exists('imagepng')
            && hp_receipt_font_path(false) !== null;
    }
}

if (!function_exists('hp_receipt_rgb')) {
    function hp_receipt_rgb(string $hex): array {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6) { $hex = '000000'; }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}

if (!function_exists('hp_receipt_money')) {
    function hp_receipt_money($amount): string {
        return '₱' . number_format((float)$amount, 2);
    }
}

if (!function_exists('hp_receipt_measure')) {
    function hp_receipt_measure(string $text, float $size, bool $bold = false): float {
        if ($text === '') { return 0.0; }
        $font = hp_receipt_font_path($bold);
        if ($font !== null && function_exists('imagettfbbox')) {
            $box = @imagettfbbox($size, 0, $font, $text);
            if (is_array($box)) {
                $minX = min($box[0], $box[2], $box[4], $box[6]);
                $maxX = max($box[0], $box[2], $box[4], $box[6]);
                return (float)($maxX - $minX);
            }
        }
        // Approximation used only when GD/FreeType is unavailable (SVG mode).
        $factor = $bold ? 0.61 : 0.57;
        return mb_strlen($text, 'UTF-8') * $size * $factor;
    }
}

if (!function_exists('hp_receipt_wrap')) {
    function hp_receipt_wrap(string $text, float $size, bool $bold, float $maxWidth): array {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if ($text === '') { return ['']; }

        $lines = [];
        $current = '';
        foreach (explode(' ', $text) as $word) {
            $try = $current === '' ? $word : $current . ' ' . $word;
            if (hp_receipt_measure($try, $size, $bold) <= $maxWidth || $current === '') {
                // A single word wider than the column: hard-split it.
                if ($current === '' && hp_receipt_measure($word, $size, $bold) > $maxWidth) {
                    $chunk = '';
                    $letters = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    foreach ($letters as $ch) {
                        $next = $chunk . $ch;
                        if ($chunk !== '' && hp_receipt_measure($next, $size, $bold) > $maxWidth) {
                            $lines[] = $chunk;
                            $chunk = $ch;
                        } else {
                            $chunk = $next;
                        }
                    }
                    $current = $chunk;
                } else {
                    $current = $try;
                }
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }
        if ($current !== '') { $lines[] = $current; }
        return $lines ?: [''];
    }
}

if (!class_exists('HP_Receipt_Builder')) {
    final class HP_Receipt_Builder {
        public float $width = 900.0;
        public float $pad = 48.0;
        public float $contentW = 804.0;
        public float $y = 34.0;
        public float $height = 0.0;
        public array $ops = [];

        public function __construct() {
            $this->contentW = $this->width - 2 * $this->pad;
        }

        public function rect(float $x, float $y, float $w, float $h, ?string $fill = null, ?string $stroke = null, float $sw = 1.0): void {
            $this->ops[] = ['t' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'fill' => $fill, 'stroke' => $stroke, 'sw' => $sw];
        }

        public function line(float $x1, float $y1, float $x2, float $y2, string $color, float $w = 1.0, bool $dash = false): void {
            $this->ops[] = ['t' => 'line', 'x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2, 'color' => $color, 'w' => $w, 'dash' => $dash];
        }

        public function dashed(float $y): void {
            $this->line($this->pad, $y, $this->width - $this->pad, $y, '#cbd5e1', 1.0, true);
        }

        public function text(float $x, float $baseline, string $text, float $size, string $color = '#142747', bool $bold = false, string $align = 'left'): void {
            if ($text === '') { return; }
            $this->ops[] = ['t' => 'text', 'x' => $x, 'y' => $baseline, 'text' => $text, 'size' => $size, 'color' => $color, 'bold' => $bold, 'align' => $align];
        }

        public function image(string $path, float $x, float $y, float $w, float $h): void {
            $this->ops[] = ['t' => 'image', 'path' => $path, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
        }
    }
}

if (!function_exists('hp_receipt_build')) {
    function hp_receipt_build(array $d): HP_Receipt_Builder {
        $b = new HP_Receipt_Builder();
        $W = $b->width;
        $pad = $b->pad;
        $C = $b->contentW;

        $navy  = '#08111d';
        $text  = '#142747';
        $muted = '#64748b';
        $line  = '#e2e8f0';
        $orange = '#ff5722';
        $green = '#047857';
        $amber = '#b45309';

        // Top accent bar
        $b->rect(0, 0, $W, 10, $navy);
        $y = 32.0;

        // Logo (centered)
        $logo = $d['logo_path'] ?? null;
        if ($logo && is_file($logo)) {
            $size = @getimagesize($logo);
            if (is_array($size) && !empty($size[0]) && !empty($size[1])) {
                $targetH = 74.0;
                $targetW = $size[0] * ($targetH / $size[1]);
                if ($targetW > 300) { $targetW = 300.0; $targetH = $size[1] * ($targetW / $size[0]); }
                $b->image($logo, ($W - $targetW) / 2, $y, $targetW, $targetH);
                $y += $targetH + 12;
            }
        }

        // Brand + store details
        $b->text($W / 2, $y + 17, strtoupper($d['store']['name'] ?? 'HI-POWER BADMINTON CENTER'), 20, $navy, true, 'center');
        $y += 28;
        foreach (hp_receipt_wrap((string)($d['store']['address'] ?? ''), 10.5, false, 650) as $addrLine) {
            $b->text($W / 2, $y + 10, $addrLine, 10.5, $muted, false, 'center');
            $y += 15;
        }
        if (!empty($d['store']['hours'])) {
            $b->text($W / 2, $y + 10, (string)$d['store']['hours'], 10.5, $muted, false, 'center');
            $y += 18;
        }
        $y += 8;
        $b->dashed($y);
        $y += 24;

        // Title + status pill. Booking receipts override these labels so a
        // QR-code payment awaiting admin review never says "PAY AT COUNTER".
        $b->text($pad, $y + 15, (string)($d['title'] ?? 'ORDER RECEIPT'), 18, $navy, true);
        $paid = !empty($d['paid']);
        $pillText = (string)($d['status_pill'] ?? ($paid ? 'PAID' : 'PAY AT COUNTER'));
        $pillW = hp_receipt_measure($pillText, 10, true) + 30;
        $b->rect($W - $pad - $pillW, $y + 1, $pillW, 26, $paid ? '#ecfdf5' : '#fff7ed', $paid ? '#a7f3d0' : '#fed7aa', 1);
        $b->text($W - $pad - $pillW / 2, $y + 18, $pillText, 10, $paid ? $green : $amber, true, 'center');
        $y += 44;

        // Meta grid (3 rows x 2 columns)
        $col1 = $pad;
        $col2 = $pad + $C * 0.55;
        $meta = [
            ['RECEIPT NO.', (string)($d['receipt_no'] ?? ''), $col1],
            ['CUSTOMER', (string)($d['customer'] ?? ''), $col2],
            ['ORDER NO.', (string)($d['order_no'] ?? ''), $col1],
            ['PAYMENT METHOD', (string)($d['method'] ?? ''), $col2],
            ['DATE', (string)($d['date'] ?? ''), $col1],
            ['PAYMENT STATUS', (string)($d['payment_status'] ?? ''), $col2],
        ];
        foreach ($meta as $i => $m) {
            $b->text($m[2], $y + 9, $m[0], 8.5, $muted, true);
            foreach (hp_receipt_wrap($m[1], 12, true, $C * 0.45 - 10) as $j => $valLine) {
                $b->text($m[2], $y + 25 + ($j * 15), $valLine, 12, $text, true);
            }
            if ($i % 2 === 1) { $y += 42; }
        }

        $y += 6;
        $b->dashed($y);
        $y += 24;

        // Table columns
        $colItem = $pad;
        $colQty  = $W - $pad - 210;
        $colUnit = $W - $pad - 120;
        $colAmt  = $W - $pad;
        $itemMax = $colQty - 46 - $colItem;

        $b->text($colItem, $y + 10, 'ITEM', 8.5, $muted, true);
        $b->text($colQty,  $y + 10, 'QTY', 8.5, $muted, true, 'center');
        $b->text($colUnit, $y + 10, 'UNIT', 8.5, $muted, true, 'right');
        $b->text($colAmt,  $y + 10, 'AMOUNT', 8.5, $muted, true, 'right');
        $y += 22;
        $b->line($pad, $y, $W - $pad, $y, $line, 1.0);
        $y += 2;

        $items = $d['items'] ?? [];
        foreach ($items as $idx => $it) {
            $descLines = hp_receipt_wrap((string)($it['description'] ?? 'Item'), 11.5, false, $itemMax);
            $rowH = max(34.0, count($descLines) * 16 + 16);
            if ($idx % 2 === 1) {
                $b->rect($pad, $y, $C, $rowH, '#f8fafc');
            }
            $ly = $y + 16;
            foreach ($descLines as $dl) {
                $b->text($colItem, $ly, $dl, 11.5, $text);
                $ly += 16;
            }
            $qty = max(1, (int)($it['quantity'] ?? 1));
            $b->text($colQty, $y + 16, 'x' . $qty, 11, $muted, false, 'center');
            $b->text($colUnit, $y + 16, hp_receipt_money($it['unit_price'] ?? 0), 11, $muted, false, 'right');
            $b->text($colAmt, $y + 16, hp_receipt_money($it['amount'] ?? 0), 11.5, $text, true, 'right');
            $b->line($pad, $y + $rowH, $W - $pad, $y + $rowH, '#eef2f7', 1.0);
            $y += $rowH;
        }
        if (empty($items)) {
            $b->text($pad, $y + 18, 'No line items recorded.', 11.5, $muted);
            $y += 38;
        }

        // Totals
        $y += 12;
        $b->dashed($y);
        $y += 26;

        $labelX = $W - $pad - 170;
        $subtotal = (float)($d['subtotal'] ?? 0);
        $total    = (float)($d['total'] ?? 0);

        $b->text($labelX, $y + 12, 'Subtotal', 11, $muted, false, 'right');
        $b->text($colAmt, $y + 12, hp_receipt_money($subtotal), 11, $text, true, 'right');
        $y += 24;

        if (abs($total - $subtotal) > 0.009) {
            $b->text($labelX, $y + 12, 'Adjustment', 11, $muted, false, 'right');
            $b->text($colAmt, $y + 12, hp_receipt_money($total - $subtotal), 11, $text, true, 'right');
            $y += 24;
        }

        $b->rect($W - $pad - 300, $y, 300, 46, '#f8fafc', $line, 1);
        $b->text($W - $pad - 284, $y + 29, 'TOTAL', 12, $navy, true);
        $b->text($colAmt - 16, $y + 31, hp_receipt_money($total), 19, $orange, true, 'right');
        $y += 62;

        // Notice box
        $notice = $paid
            ? 'Payment received at the counter. Thank you! Keep this receipt for your records.'
            : 'Please present this receipt at the counter and pay ' . hp_receipt_money($total) . ' in cash when you pick up your order.';
        if (!empty($d['store']['requirements'])) {
            $notice .= ' ' . (string)$d['store']['requirements'];
        }
        $noticeLines = hp_receipt_wrap($notice, 10.5, false, $C - 32);
        $boxH = count($noticeLines) * 15 + 24;
        $b->rect($pad, $y, $C, $boxH, $paid ? '#ecfdf5' : '#fff7ed', $paid ? '#a7f3d0' : '#fed7aa', 1);
        $ny = $y + 18;
        foreach ($noticeLines as $nl) {
            $b->text($pad + 16, $ny, $nl, 10.5, $paid ? '#065f46' : '#92400e');
            $ny += 15;
        }
        $y += $boxH + 14;

        // Optional order notes
        if (!empty($d['notes'])) {
            $noteLines = hp_receipt_wrap('Notes: ' . (string)$d['notes'], 10, false, $C - 28);
            $noteH = count($noteLines) * 14 + 20;
            $b->rect($pad, $y, $C, $noteH, '#f8fafc', $line, 1);
            $ny = $y + 15;
            foreach ($noteLines as $nl) {
                $b->text($pad + 14, $ny, $nl, 10, $muted);
                $ny += 14;
            }
            $y += $noteH + 12;
        }

        // Footer
        $b->dashed($y);
        $y += 26;
        $b->text($W / 2, $y + 14, 'Thank you for your purchase!', 13, $navy, true, 'center');
        $y += 24;
        $b->text($W / 2, $y + 11, 'This is a system-generated receipt from hipowerbc.xyz.', 9.5, '#94a3b8', false, 'center');
        $y += 22;
        $b->text($W / 2, $y + 11, 'Keep this receipt as proof of your order.', 9.5, '#94a3b8', false, 'center');
        $y += 26;

        $b->height = $y + 6;
        return $b;
    }
}

if (!function_exists('hp_receipt_png')) {
    function hp_receipt_png(HP_Receipt_Builder $b): string {
        $W = (int)ceil($b->width);
        $H = (int)ceil($b->height);
        $im = imagecreatetruecolor($W, $H);
        if (!$im) { throw new RuntimeException('Could not allocate receipt image.'); }
        imagealphablending($im, true);
        $white = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 0, 0, $W, $H, $white);

        $colors = [];
        $color = function (string $hex) use ($im, &$colors) {
            if (!isset($colors[$hex])) {
                [$r, $g, $bl] = hp_receipt_rgb($hex);
                $colors[$hex] = imagecolorallocate($im, $r, $g, $bl);
            }
            return $colors[$hex];
        };

        foreach ($b->ops as $op) {
            switch ($op['t']) {
                case 'rect':
                    $x = (int)round($op['x']);
                    $y = (int)round($op['y']);
                    $w = max(1, (int)round($op['w']));
                    $h = max(1, (int)round($op['h']));
                    if ($op['fill'] !== null) {
                        imagefilledrectangle($im, $x, $y, $x + $w - 1, $y + $h - 1, $color($op['fill']));
                    }
                    if ($op['stroke'] !== null) {
                        imagerectangle($im, $x, $y, $x + $w - 1, $y + $h - 1, $color($op['stroke']));
                    }
                    break;

                case 'line':
                    imagesetthickness($im, max(1, (int)round($op['w'])));
                    $lc = $color($op['color']);
                    if (!empty($op['dash'])) {
                        $x1 = $op['x1'];
                        $x2 = $op['x2'];
                        $ly = (int)round($op['y1']);
                        for ($x = $x1; $x < $x2; $x += 14) {
                            imageline($im, (int)round($x), $ly, (int)round(min($x + 8, $x2)), $ly, $lc);
                        }
                    } else {
                        imageline($im, (int)round($op['x1']), (int)round($op['y1']), (int)round($op['x2']), (int)round($op['y2']), $lc);
                    }
                    imagesetthickness($im, 1);
                    break;

                case 'text':
                    $font = hp_receipt_font_path(!empty($op['bold']));
                    if ($font === null) { break; }
                    $width = hp_receipt_measure($op['text'], $op['size'], !empty($op['bold']));
                    $x = $op['x'];
                    if ($op['align'] === 'center') { $x -= $width / 2; }
                    elseif ($op['align'] === 'right') { $x -= $width; }
                    @imagettftext($im, $op['size'], 0, (int)round($x), (int)round($op['y']), $color($op['color']), $font, $op['text']);
                    break;

                case 'image':
                    if (!is_file($op['path'])) { break; }
                    $src = @imagecreatefrompng($op['path']);
                    if (!$src) {
                        $src = @imagecreatefromjpeg($op['path']);
                    }
                    if (!$src) { break; }
                    imagealphablending($im, true);
                    imagecopyresampled(
                        $im, $src,
                        (int)round($op['x']), (int)round($op['y']), 0, 0,
                        max(1, (int)round($op['w'])), max(1, (int)round($op['h'])),
                        imagesx($src), imagesy($src)
                    );
                    imagedestroy($src);
                    break;
            }
        }

        ob_start();
        imagepng($im, null, 6);
        $bytes = (string)ob_get_clean();
        imagedestroy($im);
        return $bytes;
    }
}

if (!function_exists('hp_receipt_svg')) {
    function hp_receipt_svg(HP_Receipt_Builder $b): string {
        $W = (int)ceil($b->width);
        $H = (int)ceil($b->height);
        $esc = function (string $s): string {
            return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        };
        $fonts = "DejaVu Sans, Arial, Helvetica, sans-serif";

        $svg  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $svg .= '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="' . $W . '" height="' . $H . '" viewBox="0 0 ' . $W . ' ' . $H . '">' . "\n";
        $svg .= '<rect x="0" y="0" width="' . $W . '" height="' . $H . '" fill="#ffffff"/>' . "\n";

        foreach ($b->ops as $op) {
            switch ($op['t']) {
                case 'rect':
                    $svg .= '<rect x="' . round($op['x'], 2) . '" y="' . round($op['y'], 2) . '" width="' . round($op['w'], 2) . '" height="' . round($op['h'], 2) . '"';
                    $svg .= ' fill="' . (!empty($op['fill']) ? $op['fill'] : 'none') . '"';
                    if (!empty($op['stroke'])) {
                        $svg .= ' stroke="' . $op['stroke'] . '" stroke-width="' . round($op['sw'], 2) . '"';
                    }
                    $svg .= '/>' . "\n";
                    break;

                case 'line':
                    $svg .= '<line x1="' . round($op['x1'], 2) . '" y1="' . round($op['y1'], 2) . '" x2="' . round($op['x2'], 2) . '" y2="' . round($op['y2'], 2) . '" stroke="' . $op['color'] . '" stroke-width="' . round($op['w'], 2) . '"';
                    if (!empty($op['dash'])) { $svg .= ' stroke-dasharray="8 6"'; }
                    $svg .= '/>' . "\n";
                    break;

                case 'text':
                    $anchor = $op['align'] === 'center' ? 'middle' : ($op['align'] === 'right' ? 'end' : 'start');
                    $svg .= '<text x="' . round($op['x'], 2) . '" y="' . round($op['y'], 2) . '" font-family="' . $fonts . '" font-size="' . round($op['size'], 2) . '"';
                    $svg .= ' font-weight="' . (!empty($op['bold']) ? 'bold' : 'normal') . '" fill="' . $op['color'] . '" text-anchor="' . $anchor . '">' . $esc((string)$op['text']) . '</text>' . "\n";
                    break;

                case 'image':
                    if (!is_file($op['path'])) { break; }
                    $mime = 'image/png';
                    if (preg_match('/\.jpe?g$/i', $op['path'])) { $mime = 'image/jpeg'; }
                    elseif (preg_match('/\.webp$/i', $op['path'])) { $mime = 'image/webp'; }
                    $data = @file_get_contents($op['path']);
                    if ($data === false) { break; }
                    $href = 'data:' . $mime . ';base64,' . base64_encode($data);
                    $svg .= '<image x="' . round($op['x'], 2) . '" y="' . round($op['y'], 2) . '" width="' . round($op['w'], 2) . '" height="' . round($op['h'], 2) . '" href="' . $href . '" xlink:href="' . $href . '"/>' . "\n";
                    break;
            }
        }

        $svg .= '</svg>' . "\n";
        return $svg;
    }
}

if (!function_exists('hp_receipt_render')) {
    /**
     * @param array  $data   Receipt data (see receipt.php for the shape).
     * @param string $format 'png' or 'svg'. Falls back to SVG without GD.
     * @return array{0:string,1:string} [bytes, actual format]
     */
    function hp_receipt_render(array $data, string $format = 'png'): array {
        $b = hp_receipt_build($data);
        if ($format === 'png' && hp_receipt_gd_available()) {
            return [hp_receipt_png($b), 'png'];
        }
        return [hp_receipt_svg($b), 'svg'];
    }
}
