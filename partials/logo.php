<?php
// Logo unique de la marque (symbole + texte). Usage : require_once __DIR__.'/logo.php'; echo mc_logo(); 
// $tone : 'dark' (fond clair) | 'light' (fond sombre). $text : afficher « moncamp.fr ». $px : hauteur du symbole.
if (!function_exists('mc_logo')) {
    function mc_logo(string $tone = 'dark', bool $text = true, int $px = 28): string
    {
        $bg   = $tone === 'light' ? '#fff'    : '#26343b';
        $fg   = $tone === 'light' ? '#26343b' : '#fff';
        $dot  = $tone === 'light' ? '#3d6a8e' : '#8fb3d0';
        $ink  = $tone === 'light' ? '#fff'    : '#26343b';
        $acc  = $tone === 'light' ? '#b9d2e6' : '#3d6a8e';
        $svg  = '<svg width="' . $px . '" height="' . $px . '" viewBox="0 0 32 32" aria-hidden="true" focusable="false" style="flex:none"><rect width="32" height="32" rx="8" fill="' . $bg . '"/><path d="M7 24V15.5L11.5 9.5 16 15.5V24M16 15.5l4.5-6 4.5 6V24" fill="none" stroke="' . $fg . '" stroke-width="2.6" stroke-linejoin="round" stroke-linecap="round"/><circle cx="16" cy="5.6" r="1.7" fill="' . $dot . '"/></svg>';
        if (!$text) return $svg;
        return '<span style="display:inline-flex;align-items:center;gap:9px;white-space:nowrap">' . $svg
            . '<span style="font-family:Montserrat,system-ui,sans-serif;font-weight:800;letter-spacing:-.02em;line-height:1;font-size:' . round($px * .78) . 'px;color:' . $ink . '">moncamp<span style="color:' . $acc . '">.fr</span></span></span>';
    }
}
