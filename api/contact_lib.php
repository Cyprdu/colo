<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'contact_lib.php') { http_response_code(404); exit; }

const CONTACT_MIN_SECONDS = 4;

function contact_form_ts(): string {
    $t = (string)time();
    return $t . '.' . substr(hash_hmac('sha256', 'contact|' . $t . '|' . session_id(), adm_key('contact')), 0, 20);
}

function contact_spam_reason(array $f, bool $logged): ?string {
    if (trim((string)($f['site_web'] ?? '')) !== '') return 'pot de miel rempli';
    $ts = (string)($f['ts'] ?? '');
    if (!preg_match('/^(\d{10})\.([a-f0-9]{20})$/', $ts, $m) || !hash_equals(substr(hash_hmac('sha256', 'contact|' . $m[1] . '|' . session_id(), adm_key('contact')), 0, 20), $m[2])) return 'jeton de formulaire absent';
    if (time() - (int)$m[1] < CONTACT_MIN_SECONDS) return 'envoyé trop vite';
    $txt = (string)($f['message'] ?? '') . ' ' . (string)($f['motif_autre'] ?? '') . ' ' . (string)($f['nom'] ?? '') . ' ' . (string)($f['prenom'] ?? '');
    if (preg_match('/\[url=|<a\s+href|\[link/i', $txt)) return 'balises de lien';
    preg_match_all('~(https?://|www\.)([a-z0-9.-]+)~i', $txt, $mm);
    $ext = array_filter($mm[2] ?? [], fn($d) => !preg_match('/(^|\.)moncamp\.fr$/i', $d));
    if (count($ext) >= 2 || (count($ext) >= 1 && !$logged)) return 'liens externes';
    if (preg_match('/\b(casino|viagra|cialis|crypto|bitcoin|forex|porn|backlinks?|seo services?|guest post|rank your (web)?site|loan offer|escort|betting|onlyfans|whatsapp me|telegram me)\b/i', $txt)) return 'mots-clés de spam';
    $letters = preg_match_all('/\p{L}/u', $txt);
    $foreign = preg_match_all('/[\p{Cyrillic}\p{Han}\p{Hangul}\p{Thai}\p{Arabic}]/u', $txt);
    if ($letters > 20 && $foreign / $letters > 0.3) return 'alphabet inhabituel';
    $nm = trim((string)($f['nom'] ?? '') . (string)($f['prenom'] ?? ''));
    if (!$logged && $nm !== '' && preg_match('/^[A-Za-z]{12,}$/', $nm) && !preg_match('/[aeiouy]{1}.*[aeiouy]/i', $nm)) return 'nom aléatoire';
    return null;
}
