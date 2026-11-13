<?php
// partials/mk_icons.php : icônes SVG en ligne (sans emoji), héritent de la couleur du texte.
function mk_icon(string $name, int $size = 22): string {
    $p = [
        'up'     => '<path d="M12 19V5M5.5 11.5L12 5l6.5 6.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'star'   => '<path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8L3.5 9.7l5.9-.9L12 3.5z" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/>',
        'clock'  => '<circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="2"/><path d="M12 7.5V12l3 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'chart'  => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
        'eye'    => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><circle cx="12" cy="12" r="2.8" stroke="currentColor" stroke-width="2"/>',
        'wallet' => '<path d="M4 7.5A2.5 2.5 0 016.5 5H18v3M4 7.5V17a2 2 0 002 2h13a1 1 0 001-1v-8a1 1 0 00-1-1H6.5A2.5 2.5 0 014 7.5z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><circle cx="16" cy="13.5" r="1.2" fill="currentColor"/>',
        'bank'   => '<path d="M3 9.5L12 4l9 5.5M5 10v7M9.5 10v7M14.5 10v7M19 10v7M3 20h18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'check'  => '<path d="M5 12.5l4.5 4.5L19 7.5" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>',
        'alert'  => '<path d="M12 4l9 15.5H3L12 4zM12 10v4.5M12 17v.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'doc'    => '<path d="M7 3.5h7l4 4V20a.5.5 0 01-.5.5h-10A.5.5 0 017 20V3.5zM14 3.5V8h4M9.5 12.5h5M9.5 16h5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>',
        'arrow'  => '<path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'back'   => '<path d="M19 12H5M11 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'cursor' => '<path d="M5 4l5.5 15 2.2-6.3L19 10.5 5 4z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>',
        'users'  => '<circle cx="9" cy="8.5" r="3.3" stroke="currentColor" stroke-width="2"/><path d="M3 19c.6-3.3 3-5 6-5s5.4 1.7 6 5M16.5 5.5a3 3 0 010 6M18 14.4c1.8.5 2.8 2 3 4.6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
        'shield' => '<path d="M12 3.5l7 2.8v5.4c0 4.2-2.8 7.2-7 8.8-4.2-1.6-7-4.6-7-8.8V6.3l7-2.8z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M9 12l2.2 2.2L15.5 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'info'   => '<circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="2"/><path d="M12 11v5M12 8v.01" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>',
    ];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}
