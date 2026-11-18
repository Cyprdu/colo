<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'demande_lib.php') { http_response_code(404); exit; }
require_once __DIR__ . '/adm_core.php';

function demande_types(): array { return ['association' => 'Association', 'entreprise' => 'Entreprise', 'collectivite' => 'Collectivité / mairie', 'autre' => 'Autre']; }
function demande_statuts(): array { return ['attente' => 'En attente', 'complements' => 'Compléments demandés', 'validee' => 'Validée', 'refusee' => 'Refusée']; }

function demande_siret_ok(string $s): bool {
    $s = preg_replace('/\s+/', '', $s);
    if (!preg_match('/^\d{14}$/', $s)) return false;
    if (substr($s, 0, 9) === '356000000') return array_sum(str_split($s)) % 5 === 0;
    $sum = 0;
    for ($i = 0; $i < 14; $i++) { $d = (int)$s[13 - $i]; if ($i % 2) { $d *= 2; if ($d > 9) $d -= 9; } $sum += $d; }
    return $sum % 10 === 0;
}

function demande_site_norm(string $u): string {
    $u = trim($u);
    if ($u === '') return '';
    if (!preg_match('~^https?://~i', $u)) $u = 'https://' . $u;
    return filter_var($u, FILTER_VALIDATE_URL) && preg_match('~^https?://[a-z0-9.-]+\.[a-z]{2,}~i', $u) ? mb_substr($u, 0, 255) : '';
}

function demande_event(PDO $pdo, int $did, string $action, ?string $texte = null, ?int $par = null, string $qui = ''): void {
    $pdo->prepare("INSERT INTO demandes_organisateur_evts (demande_id, par, qui, action, texte) VALUES (?, ?, ?, ?, ?)")->execute([$did, $par, mb_substr($qui, 0, 190), $action, $texte]);
}

function demande_current(PDO $pdo, int $uid): ?array {
    adm_core_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM demandes_organisateur WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$uid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function demande_fields(array $in): array {
    $f = [
        'structure' => mb_substr(trim((string)($in['structure'] ?? '')), 0, 160),
        'type_structure' => isset(demande_types()[$in['type_structure'] ?? '']) ? $in['type_structure'] : '',
        'siret' => preg_replace('/\s+/', '', (string)($in['siret'] ?? '')),
        'site' => demande_site_norm((string)($in['site'] ?? '')),
        'tel' => mb_substr(preg_replace('/[^0-9+ .()-]/', '', (string)($in['tel'] ?? '')), 0, 30),
        'ville' => mb_substr(trim((string)($in['ville'] ?? '')), 0, 120),
        'sejours_an' => in_array($in['sejours_an'] ?? '', ['1', '2-5', '6-20', '20+'], true) ? $in['sejours_an'] : '',
        'agrement' => mb_substr(trim((string)($in['agrement'] ?? '')), 0, 120),
        'message' => mb_substr(trim((string)($in['message'] ?? '')), 0, 3000),
    ];
    $err = [];
    if (mb_strlen($f['structure']) < 2) $err[] = 'Indiquez le nom de votre structure.';
    if ($f['type_structure'] === '') $err[] = 'Choisissez le type de structure.';
    if ($f['siret'] !== '' && !demande_siret_ok($f['siret'])) $err[] = 'Le numéro SIRET n’est pas valide (14 chiffres).';
    if (in_array($f['type_structure'], ['entreprise', 'association'], true) && $f['siret'] === '') $err[] = 'Le SIRET est obligatoire pour une entreprise ou une association.';
    if (trim((string)($in['site'] ?? '')) !== '' && $f['site'] === '') $err[] = 'L’adresse du site web n’est pas valide.';
    if (strlen(preg_replace('/\D/', '', $f['tel'])) < 9) $err[] = 'Indiquez un numéro de téléphone.';
    if (mb_strlen($f['message']) < 20) $err[] = 'Présentez votre activité en quelques phrases.';
    return [$f, $err];
}

function demande_mail_user(PDO $pdo, array $d, string $subject, string $html): bool {
    $st = $pdo->prepare("SELECT email, prenom FROM users WHERE id = ?");
    $st->execute([(int)$d['user_id']]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return false;
    return adm_mail((string)$u['email'], $subject, '<p>Bonjour ' . htmlspecialchars((string)$u['prenom']) . ',</p>' . $html);
}

function demande_site_url(): string { return rtrim(defined('BASE_URL') ? BASE_URL : 'https://moncamp.fr', '/'); }
