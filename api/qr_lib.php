<?php
if (defined('QR_LIB')) return;
define('QR_LIB', 1);

function qr_matrix(string $txt): array {
    $T = [1 => [10, [[1, 16]]], [16, [[1, 28]]], [26, [[1, 44]]], [18, [[2, 32]]], [24, [[2, 43]]], [16, [[4, 27]]], [18, [[4, 31]]],
          [22, [[2, 38], [2, 39]]], [22, [[3, 36], [2, 37]]], [26, [[4, 43], [1, 44]]]];
    $AL = [1 => [], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50]];
    $n = strlen($txt);
    for ($v = 1; $v <= 10; $v++) {
        $cap = 0; foreach ($T[$v][1] as [$c, $d]) $cap += $c * $d;
        if (4 + ($v < 10 ? 8 : 16) + 8 * $n <= $cap * 8) break;
    }
    if ($v > 10) throw new RuntimeException('QR : texte trop long');
    [$ecn, $grp] = $T[$v];

    $bits = '0100' . str_pad(decbin($n), $v < 10 ? 8 : 16, '0', STR_PAD_LEFT);
    for ($i = 0; $i < $n; $i++) $bits .= str_pad(decbin(ord($txt[$i])), 8, '0', STR_PAD_LEFT);
    $bits .= str_repeat('0', min(4, $cap * 8 - strlen($bits)));
    $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
    $data = array_map('bindec', str_split($bits, 8));
    for ($p = 0; count($data) < $cap; $p ^= 1) $data[] = $p ? 0x11 : 0xEC;

    $exp = []; $log = []; $x = 1;
    for ($i = 0; $i < 255; $i++) { $exp[$i] = $x; $log[$x] = $i; $x <<= 1; if ($x & 0x100) $x ^= 0x11D; }
    $mul = fn($a, $b) => $a && $b ? $exp[($log[$a] + $log[$b]) % 255] : 0;
    $gen = [1];
    for ($i = 0; $i < $ecn; $i++) {
        $g = array_fill(0, count($gen) + 1, 0);
        foreach ($gen as $j => $c) { $g[$j] ^= $c; $g[$j + 1] ^= $mul($c, $exp[$i]); }
        $gen = $g;
    }
    $blocks = []; $ecs = []; $o = 0;
    foreach ($grp as [$c, $d]) for ($k = 0; $k < $c; $k++) {
        $b = array_slice($data, $o, $d); $o += $d;
        $r = array_merge($b, array_fill(0, $ecn, 0));
        for ($i = 0; $i < $d; $i++) { $f = $r[$i]; if ($f) for ($j = 1; $j <= $ecn; $j++) $r[$i + $j] ^= $mul($gen[$j], $f); }
        $blocks[] = $b; $ecs[] = array_slice($r, $d);
    }
    $cw = [];
    $mx = max(array_map('count', $blocks));
    for ($i = 0; $i < $mx; $i++) foreach ($blocks as $b) if (isset($b[$i])) $cw[] = $b[$i];
    for ($i = 0; $i < $ecn; $i++) foreach ($ecs as $e) $cw[] = $e[$i];

    $s = 17 + 4 * $v;
    $m = array_fill(0, $s, array_fill(0, $s, false));
    $fn = $m;
    $set = function ($x, $y, $d) use (&$m, &$fn) { $m[$y][$x] = $d; $fn[$y][$x] = true; };
    for ($i = 0; $i < $s; $i++) { $set(6, $i, $i % 2 === 0); $set($i, 6, $i % 2 === 0); }
    foreach ([[3, 3], [$s - 4, 3], [3, $s - 4]] as [$cx, $cy])
        for ($dy = -4; $dy <= 4; $dy++) for ($dx = -4; $dx <= 4; $dx++) {
            $xx = $cx + $dx; $yy = $cy + $dy;
            if ($xx >= 0 && $xx < $s && $yy >= 0 && $yy < $s) { $dist = max(abs($dx), abs($dy)); $set($xx, $yy, $dist !== 2 && $dist !== 4); }
        }
    $al = $AL[$v]; $na = count($al);
    foreach ($al as $i => $ax) foreach ($al as $j => $ay) {
        if (($i === 0 && $j === 0) || ($i === 0 && $j === $na - 1) || ($i === $na - 1 && $j === 0)) continue;
        for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) $set($ax + $dx, $ay + $dy, max(abs($dx), abs($dy)) !== 1);
    }
    $format = function (int $mask) use ($set, $s) {
        $d = $mask; $r = $d;
        for ($i = 0; $i < 10; $i++) $r = ($r << 1) ^ (($r >> 9) * 0x537);
        $b = (($d << 10) | $r) ^ 0x5412;
        $bit = fn($i) => (($b >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) $set(8, $i, $bit($i));
        $set(8, 7, $bit(6)); $set(8, 8, $bit(7)); $set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) $set(14 - $i, 8, $bit($i));
        for ($i = 0; $i < 8; $i++) $set($s - 1 - $i, 8, $bit($i));
        for ($i = 8; $i < 15; $i++) $set(8, $s - 15 + $i, $bit($i));
        $set(8, $s - 8, true);
    };
    $format(0);
    if ($v >= 7) {
        $r = $v;
        for ($i = 0; $i < 12; $i++) $r = ($r << 1) ^ (($r >> 11) * 0x1F25);
        $b = ($v << 12) | $r;
        for ($i = 0; $i < 18; $i++) { $d = (($b >> $i) & 1) === 1; $a = $s - 11 + $i % 3; $c = intdiv($i, 3); $set($a, $c, $d); $set($c, $a, $d); }
    }
    $i = 0; $tot = count($cw) * 8;
    for ($right = $s - 1; $right >= 1; $right -= 2) {
        if ($right === 6) $right = 5;
        for ($vert = 0; $vert < $s; $vert++) for ($j = 0; $j < 2; $j++) {
            $x = $right - $j; $y = (($right + 1) & 2) === 0 ? $s - 1 - $vert : $vert;
            if (!$fn[$y][$x] && $i < $tot) { $m[$y][$x] = (($cw[$i >> 3] >> (7 - ($i & 7))) & 1) === 1; $i++; }
        }
    }
    $masks = [fn($x, $y) => ($x + $y) % 2 === 0, fn($x, $y) => $y % 2 === 0, fn($x, $y) => $x % 3 === 0, fn($x, $y) => ($x + $y) % 3 === 0,
              fn($x, $y) => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0, fn($x, $y) => $x * $y % 2 + $x * $y % 3 === 0,
              fn($x, $y) => ($x * $y % 2 + $x * $y % 3) % 2 === 0, fn($x, $y) => (($x + $y) % 2 + $x * $y % 3) % 2 === 0];
    $base = $m; $best = null; $bestP = PHP_INT_MAX;
    foreach ($masks as $k => $f) {
        $m = $base;
        for ($y = 0; $y < $s; $y++) for ($x = 0; $x < $s; $x++) if (!$fn[$y][$x] && $f($x, $y)) $m[$y][$x] = !$m[$y][$x];
        $format($k);
        $p = 0; $dark = 0;
        for ($a = 0; $a < $s; $a++) {
            $rr = 1; $rc = 1;
            for ($b = 0; $b < $s; $b++) {
                if ($m[$a][$b]) $dark++;
                if ($b > 0) {
                    if ($m[$a][$b] === $m[$a][$b - 1]) { if (++$rr === 5) $p += 3; elseif ($rr > 5) $p++; } else $rr = 1;
                    if ($m[$b][$a] === $m[$b - 1][$a]) { if (++$rc === 5) $p += 3; elseif ($rc > 5) $p++; } else $rc = 1;
                }
                if ($a > 0 && $b > 0 && $m[$a][$b] === $m[$a - 1][$b] && $m[$a][$b] === $m[$a][$b - 1] && $m[$a][$b] === $m[$a - 1][$b - 1]) $p += 3;
            }
        }
        $p += intdiv(abs($dark * 20 - $s * $s * 10), $s * $s) * 10;
        if ($p < $bestP) { $bestP = $p; $best = $m; }
    }
    return $best;
}

function qr_svg(string $txt, int $taille = 200): string {
    $m = qr_matrix($txt); $s = count($m); $q = 4; $w = $s + 2 * $q; $d = '';
    foreach ($m as $y => $row) foreach ($row as $x => $on) if ($on) $d .= 'M' . ($x + $q) . ' ' . ($y + $q) . 'h1v1h-1z';
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . $w . '" width="' . $taille . '" height="' . $taille . '" shape-rendering="crispEdges" role="img" aria-label="QR code de configuration"><rect width="' . $w . '" height="' . $w . '" fill="#fff"/><path fill="#000" d="' . $d . '"/></svg>';
}
