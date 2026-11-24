<?php
declare(strict_types=1);

// These files are loaded by app/bootstrap.php; refuse a direct request for them.
defined('ROOT_PATH') || exit;

/*
 * Per-page social share cards (og:image / twitter:image), 1200x630.
 *
 * The look comes from design/brand/article-card.html. DreamHost only has PHP/GD, which cannot
 * blur or rasterize SVG, so the aurora background and the wordmark are pre-rendered once with
 * headless Chrome (scripts/build-social-assets.sh) and committed under assets/social/. GD draws
 * the copy on top: pill, gradient headline, tag chips and the URL. Everything is drawn on a 2x
 * canvas and downsampled so the shapes are antialiased.
 *
 * Cards are generated on first request and cached in cache/social/. Bump SOCIAL_CARD_VERSION
 * whenever the design or assets change so crawlers and the cache pick up the new look.
 */

const SOCIAL_CARD_VERSION = 1;
const SOCIAL_CARD_W = 1200;
const SOCIAL_CARD_H = 630;
const SOCIAL_CARD_SCALE = 2;
// Glyph advances are measured at this multiple of the drawn size and scaled back down, because
// FreeType rounds each advance to whole pixels and that error compounds across a headline.
const SOCIAL_CARD_MEASURE = 8;
const SOCIAL_CARD_FALLBACK = '/social_preview.jpg?v=2';

/** Describes the card for a Learn article. Only fields that appear on the card (and its cache key). */
function social_card_spec(array $article): array
{
    return [
        'section' => 'Learn',
        'label' => ucfirst((string) $article['category']),
        'title' => (string) $article['title'],
        'tags' => array_values(array_map('strval', (array) $article['tags'])),
        'path' => '/learn',
    ];
}

function social_card_hash(array $spec): string
{
    return substr(sha1(SOCIAL_CARD_VERSION . '|' . json_encode($spec, JSON_UNESCAPED_UNICODE)), 0, 10);
}

/** Absolute og:image URL for an article; the hash busts crawler caches when the card changes. */
function social_card_url(array $article): string
{
    $siteUrl = rtrim((string) app_config('site_url'), '/');
    return $siteUrl . '/social/learn/' . $article['slug'] . '.jpg?v=' . social_card_hash(social_card_spec($article));
}

/** Serves /social/learn/<category>/<slug>.jpg, falling back to the generic preview if GD fails. */
function handle_social_card(string $slug): void
{
    $article = get_content_by_slug($slug);
    if (!$article) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        return;
    }

    // Buffer so a stray PHP warning/notice can neither corrupt the image nor block the fallback redirect.
    ob_start();
    try {
        $file = social_card_file(social_card_spec($article), 'learn-' . str_replace('/', '-', $article['slug']));
    } catch (Throwable $exception) {
        ob_end_clean();
        error_log('social card failed for ' . $slug . ': ' . $exception->getMessage());
        header('Location: ' . SOCIAL_CARD_FALLBACK, true, 302);
        return;
    }
    ob_end_clean();

    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=86400');
    readfile($file);
}

/** Returns the cached card path for $spec, rendering it first if needed. */
function social_card_file(array $spec, string $name): string
{
    $dir = ROOT_PATH . '/cache/social';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('cannot create ' . $dir);
    }

    $file = $dir . '/' . $name . '-' . social_card_hash($spec) . '.jpg';
    if (is_file($file)) {
        return $file;
    }

    $image = render_social_card($spec);
    $tmp = $file . '.' . getmypid() . '.tmp';
    $ok = imagejpeg($image, $tmp, 92);
    if (!$ok || !rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('cannot write ' . $file);
    }

    // Drop superseded versions of this card.
    foreach (glob($dir . '/' . $name . '-*.jpg') ?: [] as $old) {
        if ($old !== $file) {
            @unlink($old);
        }
    }

    return $file;
}

function render_social_card(array $spec): GdImage
{
    if (!function_exists('imagettftext')) {
        throw new RuntimeException('GD with FreeType is required');
    }

    $assets = ROOT_PATH . '/assets/social';
    foreach (['Inter-ExtraBold.ttf', 'Inter-SemiBold.ttf', 'Inter-Medium.ttf', 'card-bg.jpg', 'wordmark.png'] as $required) {
        if (!is_file($assets . '/' . $required)) {
            throw new RuntimeException('missing social card asset ' . $required);
        }
    }
    $fonts = [
        'title' => $assets . '/Inter-ExtraBold.ttf',
        'strong' => $assets . '/Inter-SemiBold.ttf',
        'text' => $assets . '/Inter-Medium.ttf',
    ];

    $S = SOCIAL_CARD_SCALE;
    $img = imagecreatetruecolor(SOCIAL_CARD_W * $S, SOCIAL_CARD_H * $S);

    $bg = imagecreatefromjpeg($assets . '/card-bg.jpg');
    imagecopyresampled($img, $bg, 0, 0, 0, 0, SOCIAL_CARD_W * $S, SOCIAL_CARD_H * $S, imagesx($bg), imagesy($bg));

    // Layout, in CSS pixels (mirrors design/brand/article-card.html).
    $left = 72;
    $right = SOCIAL_CARD_W - 72;
    $contentW = $right - $left;

    $pillTop = 64;
    $pillH = 40;
    sc_pill($img, $fonts, $left, $pillTop, $pillH, (string) $spec['section'], (string) $spec['label']);

    $footBottom = 574;
    sc_footer($img, $fonts, $assets . '/wordmark.png', $left, $right, $footBottom, (string) $spec['path']);

    // Headline (max 3 lines) and tag chips, vertically centered between the pill and footer.
    $titleSize = 84;
    $lineH = 84;
    $trackTitle = -0.035 * $titleSize;
    $lines = sc_wrap($fonts['title'], $titleSize, $trackTitle, (string) $spec['title'], $contentW, 3);

    $chipH = 42;
    $chipGap = 36;
    $tags = sc_fit_tags($fonts, (array) $spec['tags'], $contentW);
    $blockH = count($lines) * $lineH + ($tags ? $chipGap + $chipH : 0);

    $regionTop = $pillTop + $pillH;
    $regionBottom = ($footBottom - 40) - 24 - 8;
    $blockTop = $regionTop + (($regionBottom - $regionTop) - $blockH) / 2;

    sc_gradient_title($img, $fonts['title'], $titleSize, $trackTitle, $lines, $left, $blockTop, $lineH, $contentW);

    if ($tags) {
        sc_chips($img, $fonts, $tags, $left, $blockTop + count($lines) * $lineH + $chipGap, $chipH);
    }

    $out = imagecreatetruecolor(SOCIAL_CARD_W, SOCIAL_CARD_H);
    imagecopyresampled($out, $img, 0, 0, 0, 0, SOCIAL_CARD_W, SOCIAL_CARD_H, SOCIAL_CARD_W * $S, SOCIAL_CARD_H * $S);

    return $out;
}

/* ---------- Text ---------- */

/** CSS px -> canvas px. */
function sc_px(float $v): int
{
    return (int) round($v * SOCIAL_CARD_SCALE);
}

/** GD sizes are points (96 dpi): px * 0.75, on the 2x canvas. */
function sc_pt(float $cssPx): float
{
    return $cssPx * SOCIAL_CARD_SCALE * 0.75;
}

/**
 * Cumulative kerning offsets (CSS px) before each character of $text. GD only reads the legacy
 * `kern` table and Inter keeps its kerning in GPOS, so pairs for the headline weight come from
 * app/social-kerning.php (generated by scripts/build-social-kerning.py).
 */
function sc_kerning(string $font, float $size, string $text): array
{
    static $pairs = null;
    $n = mb_strlen($text);
    $offsets = array_fill(0, $n + 1, 0.0);
    if (basename($font) !== 'Inter-ExtraBold.ttf') {
        return $offsets;
    }
    $pairs ??= require APP_PATH . '/social-kerning.php';

    $sum = 0.0;
    for ($i = 1; $i < $n; $i++) {
        $sum += ($pairs[mb_substr($text, $i - 1, 2)] ?? 0) * $size / 2048;
        $offsets[$i] = $sum;
    }
    $offsets[$n] = $sum;

    return $offsets;
}

/** Advance width in CSS px of $text with $track px of letter-spacing added after every glyph. */
function sc_text_width(string $font, float $size, string $text, float $track = 0.0): float
{
    if ($text === '') {
        return 0.0;
    }
    $box = imagettfbbox(sc_pt($size) * SOCIAL_CARD_MEASURE, 0, $font, $text);
    return $box[2] / (SOCIAL_CARD_MEASURE * SOCIAL_CARD_SCALE) + mb_strlen($text) * $track + sc_kerning($font, $size, $text)[mb_strlen($text)];
}

/**
 * Draws $text with its baseline at ($x, $y) in CSS px. GD has no letter-spacing, so with a
 * non-zero $track (or a font with GPOS kerning) each glyph is placed individually.
 */
function sc_text(GdImage $img, string $font, float $size, float $x, float $y, int $color, string $text, float $track = 0.0): void
{
    $pt = sc_pt($size);
    if ($track === 0.0 && basename($font) !== 'Inter-ExtraBold.ttf') {
        imagettftext($img, $pt, 0, sc_px($x), sc_px($y), $color, $font, $text);
        return;
    }

    $n = mb_strlen($text);
    $kern = sc_kerning($font, $size, $text);
    for ($i = 0; $i < $n; $i++) {
        $char = mb_substr($text, $i, 1);
        if ($char === ' ') {
            continue;
        }
        $big = $pt * SOCIAL_CARD_MEASURE;
        $pen = $i === 0
            ? 0.0
            : (imagettfbbox($big, 0, $font, mb_substr($text, 0, $i + 1))[2] - imagettfbbox($big, 0, $font, $char)[2]) / SOCIAL_CARD_MEASURE;
        imagettftext($img, $pt, 0, (int) round(($x + ($kern[$i] + $i * $track)) * SOCIAL_CARD_SCALE + $pen), sc_px($y), $color, $font, $char);
    }
}

/** Baseline that vertically centers a line of text of $size on $centerY (matches CSS line boxes in Inter). */
function sc_baseline(float $centerY, float $size): float
{
    return $centerY + 0.3638 * $size;
}

function sc_wrap(string $font, float $size, float $track, string $text, float $maxWidth, int $maxLines): array
{
    $lines = [];
    $line = '';
    foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
        $candidate = $line === '' ? $word : $line . ' ' . $word;
        if ($line !== '' && sc_text_width($font, $size, $candidate, $track) > $maxWidth) {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $candidate;
        }
    }
    if ($line !== '') {
        $lines[] = $line;
    }

    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, 0, $maxLines);
        $last = $lines[$maxLines - 1];
        // Drop whole words while there is more than one; only trim characters as a last resort.
        while ($last !== '' && sc_text_width($font, $size, $last . '…', $track) > $maxWidth) {
            $cut = mb_strrpos($last, ' ');
            $last = rtrim($cut === false ? mb_substr($last, 0, -1) : mb_substr($last, 0, $cut));
        }
        $lines[$maxLines - 1] = $last . '…';
    }

    return $lines ?: [''];
}

/* ---------- Shapes (antialiased by the 2x canvas) ---------- */

/** A w x h (canvas px) mask holding a white pill shape, inset by $inset canvas px on every side. */
function sc_pill_mask(int $w, int $h, int $inset): GdImage
{
    $mask = imagecreatetruecolor($w, $h);
    imagefill($mask, 0, 0, 0x000000);
    $white = 0xFFFFFF;
    $x1 = $inset;
    $y1 = $inset;
    $x2 = $w - 1 - $inset;
    $y2 = $h - 1 - $inset;
    $d = $y2 - $y1 + 1;
    imagefilledrectangle($mask, $x1 + intdiv($d, 2), $y1, $x2 - intdiv($d, 2), $y2, $white);
    imagefilledellipse($mask, $x1 + intdiv($d, 2), intdiv($y1 + $y2, 2), $d, $d, $white);
    imagefilledellipse($mask, $x2 - intdiv($d, 2), intdiv($y1 + $y2, 2), $d, $d, $white);

    return $mask;
}

/** Blends $rgb over $img wherever $mask is lit, at $alpha * mask intensity. */
function sc_blend_mask(GdImage $img, GdImage $mask, int $ox, int $oy, array $rgb, float $alpha): void
{
    $w = imagesx($mask);
    $h = imagesy($mask);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $m = imagecolorat($mask, $x, $y) & 0xFF;
            if ($m === 0) {
                continue;
            }
            $a = $alpha * $m / 255;
            $px = imagecolorat($img, $ox + $x, $oy + $y);
            $r = (int) round((($px >> 16) & 0xFF) * (1 - $a) + $rgb[0] * $a);
            $g = (int) round((($px >> 8) & 0xFF) * (1 - $a) + $rgb[1] * $a);
            $b = (int) round(($px & 0xFF) * (1 - $a) + $rgb[2] * $a);
            imagesetpixel($img, $ox + $x, $oy + $y, ($r << 16) | ($g << 8) | $b);
        }
    }
}

/** Glass pill/chip: translucent fill plus a 1px (CSS) edge. */
function sc_glass_pill(GdImage $img, float $x, float $y, float $w, float $h, array $edgeRgb, float $edgeAlpha): void
{
    $pw = sc_px($w);
    $ph = sc_px($h);
    $outer = sc_pill_mask($pw, $ph, 0);
    $inner = sc_pill_mask($pw, $ph, sc_px(1));

    sc_blend_mask($img, $outer, sc_px($x), sc_px($y), [255, 255, 255], 0.06);

    // Edge ring = outer - inner.
    for ($yy = 0; $yy < $ph; $yy++) {
        for ($xx = 0; $xx < $pw; $xx++) {
            $o = imagecolorat($outer, $xx, $yy) & 0xFF;
            $i = imagecolorat($inner, $xx, $yy) & 0xFF;
            imagesetpixel($outer, $xx, $yy, max(0, $o - $i) * 0x010101);
        }
    }
    sc_blend_mask($img, $outer, sc_px($x), sc_px($y), $edgeRgb, $edgeAlpha);

}

/* ---------- Components ---------- */

/** "● LEARN · INTERMEDIATE" pill: glowing dot, uppercase tracked label, dim separator. */
function sc_pill(GdImage $img, array $fonts, float $x, float $y, float $h, string $section, string $label): void
{
    $size = 16;
    $track = 0.12 * $size;
    $section = mb_strtoupper($section);
    $label = mb_strtoupper($label);

    $wSection = sc_text_width($fonts['strong'], $size, $section, $track);
    $wSep = sc_text_width($fonts['strong'], $size, '·', $track);
    $wLabel = sc_text_width($fonts['strong'], $size, $label, $track);

    $gap = 10;
    $dot = 9;
    $pad = 18;
    $border = 1;
    $w = $border + $pad + $dot + $gap + $wSection + $gap - 2 + $wSep - 2 + $gap + $wLabel + $pad + $border;

    sc_glass_pill($img, $x, $y, $w, $h, [255, 79, 216], 0.45);

    $cy = $y + $h / 2;
    $cx = $x + $border + $pad + $dot / 2;

    // Dot with a soft glow.
    $glow = imagecolorallocatealpha($img, 255, 79, 216, 121); // ~4.5% per ring, stacked
    for ($i = 10; $i >= 1; $i--) {
        $d = ($dot / 2 + $i * 1.2) * 2;
        imagefilledellipse($img, sc_px($cx), sc_px($cy), sc_px($d), sc_px($d), $glow);
    }
    imagefilledellipse($img, sc_px($cx), sc_px($cy), sc_px($dot), sc_px($dot), imagecolorallocate($img, 255, 79, 216));

    $ink = imagecolorallocate($img, 255, 209, 245);
    $dim = imagecolorallocatealpha($img, 255, 209, 245, 70); // separator at ~45% opacity
    $baseline = sc_baseline($cy, $size);
    $tx = $x + $border + $pad + $dot + $gap;
    sc_text($img, $fonts['strong'], $size, $tx, $baseline, $ink, $section, $track);
    $tx += $wSection + $gap - 2;
    sc_text($img, $fonts['strong'], $size, $tx, $baseline, $dim, '·', $track);
    $tx += $wSep - 2 + $gap;
    sc_text($img, $fonts['strong'], $size, $tx, $baseline, $ink, $label, $track);
}

/** Tags that fit on one row (max 4), as [name, chip width] pairs. */
function sc_fit_tags(array $fonts, array $tags, float $maxWidth): array
{
    $fit = [];
    $used = 0.0;
    foreach (array_slice($tags, 0, 4) as $tag) {
        $w = sc_chip_width($fonts, (string) $tag);
        if ($used + $w > $maxWidth) {
            break;
        }
        $fit[] = [(string) $tag, $w];
        $used += $w + 12;
    }

    return $fit;
}

function sc_chip_width(array $fonts, string $tag): float
{
    return 1 + 18 + sc_text_width($fonts['strong'], 20, '#') + 2 + sc_text_width($fonts['text'], 20, ' ' . $tag) + 18 + 1;
}

function sc_chips(GdImage $img, array $fonts, array $tags, float $x, float $y, float $h): void
{
    $hash = imagecolorallocate($img, 255, 156, 236);
    $ink = imagecolorallocate($img, 235, 224, 248);
    $baseline = sc_baseline($y + $h / 2, 20);

    foreach ($tags as [$tag, $w]) {
        sc_glass_pill($img, $x, $y, $w, $h, [255, 255, 255], 0.16);
        $tx = $x + 1 + 18;
        sc_text($img, $fonts['strong'], 20, $tx, $baseline, $hash, '#');
        $tx += sc_text_width($fonts['strong'], 20, '#') + 2;
        sc_text($img, $fonts['text'], 20, $tx, $baseline, $ink, ' ' . $tag);
        $x += $w + 12;
    }
}

/** Wordmark bottom-left, nodestrich.com/<section> bottom-right, sharing one baseline. */
function sc_footer(GdImage $img, array $fonts, string $wordmarkPath, float $left, float $right, float $bottom, string $path): void
{
    $mark = imagecreatefrompng($wordmarkPath);
    imagealphablending($img, true);
    imagecopy($img, $mark, sc_px($left), sc_px($bottom) - imagesy($mark), 0, 0, imagesx($mark), imagesy($mark));

    $size = 24;
    $track = -0.005 * $size;
    $host = 'nodestrich.com';
    $wHost = sc_text_width($fonts['text'], $size, $host, $track);
    $wPath = sc_text_width($fonts['text'], $size, $path, $track);
    $x = $right - $wHost - $wPath;

    sc_text($img, $fonts['text'], $size, $x, $bottom, imagecolorallocate($img, 168, 145, 201), $host, $track);
    sc_text($img, $fonts['text'], $size, $x + $wHost, $bottom, imagecolorallocate($img, 235, 224, 248), $path, $track);
}

/**
 * Headline filled with the brand gradient (100deg: #fff, #ffd1f5, #ff7be3, #c9a2ff), spanning the
 * title box like CSS background-clip:text does. Text is drawn as a coverage mask, then colored.
 */
function sc_gradient_title(GdImage $img, string $font, float $size, float $track, array $lines, float $x, float $top, float $lineH, float $boxW): void
{
    $boxH = count($lines) * $lineH + 0.16 * $size;
    $mw = sc_px($boxW);
    $mh = sc_px($boxH);
    $mask = imagecreatetruecolor($mw, $mh);
    imagefill($mask, 0, 0, 0x000000);
    $white = imagecolorallocate($mask, 255, 255, 255);

    // Inter: ascent .96875em, descent .2412em; line-height 1 centers the 1.21em content area.
    $baselineOffset = (-0.105 + 0.96875) * $size;
    foreach ($lines as $i => $line) {
        sc_text($mask, $font, $size, 0, $i * $lineH + $baselineOffset, $white, $line, $track);
    }

    $stops = [[0.0, [255, 255, 255]], [0.45, [255, 209, 245]], [0.78, [255, 123, 227]], [1.0, [201, 162, 255]]];
    $sin = sin(deg2rad(100));
    $cos = cos(deg2rad(100));
    $len = abs($boxW * $sin) + abs($boxH * $cos);
    $ox = sc_px($x);
    $oy = sc_px($top);

    for ($py = 0; $py < $mh; $py++) {
        for ($px = 0; $px < $mw; $px++) {
            $m = imagecolorat($mask, $px, $py) & 0xFF;
            if ($m === 0) {
                continue;
            }
            $cx = $px / SOCIAL_CARD_SCALE - $boxW / 2;
            $cy = $py / SOCIAL_CARD_SCALE - $boxH / 2;
            $t = max(0.0, min(1.0, 0.5 + ($cx * $sin - $cy * $cos) / $len));

            for ($s = 1; $s < count($stops) - 1 && $t > $stops[$s][0]; $s++) {
            }
            [$t0, $c0] = $stops[$s - 1];
            [$t1, $c1] = $stops[$s];
            $f = ($t - $t0) / ($t1 - $t0);

            $a = $m / 255;
            $dst = imagecolorat($img, $ox + $px, $oy + $py);
            $r = (int) round((($dst >> 16) & 0xFF) * (1 - $a) + ($c0[0] + ($c1[0] - $c0[0]) * $f) * $a);
            $g = (int) round((($dst >> 8) & 0xFF) * (1 - $a) + ($c0[1] + ($c1[1] - $c0[1]) * $f) * $a);
            $b = (int) round(($dst & 0xFF) * (1 - $a) + ($c0[2] + ($c1[2] - $c0[2]) * $f) * $a);
            imagesetpixel($img, $ox + $px, $oy + $py, ($r << 16) | ($g << 8) | $b);
        }
    }

}
