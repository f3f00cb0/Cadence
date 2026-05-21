<?php
/**
 * Generates pure-PHP PNG icons for the PWA manifest.
 *
 * No GD / Imagick dependency. Encodes IDAT chunks by hand with raw filter 0.
 * Output: amber "M" pixel art on carbon-black background at 192px, 512px,
 * plus a maskable 512px variant with safe-zone inset padding.
 */

$out = __DIR__ . '/../public/img';
@mkdir($out, 0775, true);

/**
 * 16×16 binary glyph for a Fraunces-ish bold "M". 1 = amber, 0 = black.
 */
$M = [
    '1100000000000011',
    '1110000000000111',
    '1111000000001111',
    '1101100000011011',
    '1100110000110011',
    '1100011001100011',
    '1100001111000011',
    '1100000110000011',
    '1100000000000011',
    '1100000000000011',
    '1100000000000011',
    '1100000000000011',
    '1100000000000011',
    '1100000000000011',
    '1100000000000011',
    '1100000000000011',
];

const BG = [11, 12, 13];          // #0b0c0d
const FG = [240, 180, 0];         // #f0b400

function renderPng(int $size, array $glyph, string $path, float $insetRatio = 0.08): void
{
    $w = $h = $size;
    $glyphSize = \count($glyph);
    $inset = (int) round($size * $insetRatio);
    $box = $size - 2 * $inset;
    $cell = (int) floor($box / $glyphSize);
    $renderedSize = $cell * $glyphSize;
    $offsetX = $offsetY = (int) round(($size - $renderedSize) / 2);

    // Build raw RGBA pixel rows (one byte filter prefix per row, then RGBA bytes).
    $raw = '';
    for ($y = 0; $y < $h; $y++) {
        $raw .= "\x00"; // filter: None
        for ($x = 0; $x < $w; $x++) {
            $gx = (int) floor(($x - $offsetX) / $cell);
            $gy = (int) floor(($y - $offsetY) / $cell);
            $on = false;
            if ($gx >= 0 && $gy >= 0 && $gx < $glyphSize && $gy < $glyphSize) {
                $on = $glyph[$gy][$gx] === '1';
            }
            $rgb = $on ? FG : BG;
            $raw .= chr($rgb[0]) . chr($rgb[1]) . chr($rgb[2]) . chr(255);
        }
    }

    $idat = zlib_encode($raw, ZLIB_ENCODING_DEFLATE, 9);

    $png  = "\x89PNG\r\n\x1a\n";
    $png .= chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 6, 0, 0, 0));
    $png .= chunk('IDAT', $idat);
    $png .= chunk('IEND', '');

    file_put_contents($path, $png);
}

function chunk(string $type, string $data): string
{
    $body = $type . $data;
    return pack('N', \strlen($data)) . $body . pack('N', crc32($body));
}

renderPng(192, $M, $out . '/board-icon-192.png');
renderPng(512, $M, $out . '/board-icon-512.png');
renderPng(512, $M, $out . '/board-icon-maskable.png', 0.18); // safe-zone padding

echo "OK — wrote board-icon-192.png, board-icon-512.png, board-icon-maskable.png\n";
