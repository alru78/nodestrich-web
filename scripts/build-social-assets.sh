#!/usr/bin/env bash
# Regenerates the static assets used by dreamhost-site/app/social-card.php:
#   assets/social/card-bg.jpg   aurora background (no copy), from design/brand/article-card.html
#   assets/social/wordmark.png  transparent white wordmark, from design/brand/nodestrich_logo_white.svg
# The server only has PHP/GD, which cannot blur or rasterize SVG, so these are rendered
# once with headless Chrome and committed. Re-run after changing the brand design.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CHROME="${CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}"
OUT="$ROOT/dreamhost-site/assets/social"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

shot() { # shot <w> <h> <out> <url> [extra flags...]
  local w="$1" h="$2" out="$3" url="$4"; shift 4
  "$CHROME" --headless=new --disable-gpu --hide-scrollbars --window-size="$w,$h" --screenshot="$out" "$@" "$url" >/dev/null 2>&1
}

shot 1200 630 "$TMP/bg.png" "file://$ROOT/design/brand/article-card.html?bg=1"
sips -s format jpeg -s formatOptions 92 "$TMP/bg.png" --out "$OUT/card-bg.jpg" >/dev/null

# 80px tall = the 40px CSS wordmark at the generator's 2x supersampled canvas.
cat > "$TMP/wordmark.html" <<HTML
<!doctype html><style>html,body{margin:0;background:transparent}img{display:block;width:668px;height:80px}</style>
<img src="file://$ROOT/design/brand/nodestrich_logo_white.svg">
HTML
shot 668 80 "$OUT/wordmark.png" "file://$TMP/wordmark.html" --default-background-color=00000000

echo "wrote $OUT/card-bg.jpg and $OUT/wordmark.png"
