<?php
// api/revendication_lib.php : fiches d'organismes importées (non revendiquées) et demandes de revendication.
// Une fiche importée appartient au compte du propriétaire du site ; le vrai organisme la revendique avec une preuve
// (e-mail au domaine de son site officiel, ou pièce justificative), un administrateur « Modération » valide,
// puis l'organisme et tous ses séjours passent sur le compte du demandeur.
const REV_DIR = __DIR__ . '/../uploads/revendications/';
const REV_MAX = 6 * 1024 * 1024;

function rev_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM organisateurs")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('non_revendique', $cols, true)) {
            $pdo->exec("ALTER TABLE organisateurs ADD COLUMN non_revendique TINYINT(1) NOT NULL DEFAULT 0");
            $pdo->exec("UPDATE organisateurs o JOIN users u ON u.id = o.user_id SET o.non_revendique = 1
                        WHERE u.is_admin = 1 AND COALESCE(o.stripe_account_id, '') = '' AND COALESCE(o.web, '') <> ''");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS orga_revendications (id INT AUTO_INCREMENT PRIMARY KEY, token CHAR(32) NOT NULL UNIQUE, organisateur_id INT NOT NULL, user_id INT NOT NULL,
            prenom VARCHAR(80) NOT NULL, nom VARCHAR(80) NOT NULL, fonction VARCHAR(120) NOT NULL, tel VARCHAR(30) NULL, email_pro VARCHAR(190) NULL, email_verifie TINYINT(1) NOT NULL DEFAULT 0,
            fichier VARCHAR(80) NULL, fichier_type VARCHAR(40) NULL, message TEXT NULL, statut VARCHAR(10) NOT NULL DEFAULT 'attente', motif TEXT NULL, decide_par INT NULL, decide_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_orga (organisateur_id), KEY idx_statut (statut)) DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('rev_ensure: ' . $e->getMessage()); }
}

/** Domaine du site officiel (sans www.), '' si inexploitable ou si c'est un annuaire tiers. */
function rev_domaine(?string $web): string {
    $h = strtolower((string)parse_url(preg_match('#^https?://#i', (string)$web) ? (string)$web : 'https://' . $web, PHP_URL_HOST));
    $h = preg_replace('/^www\./', '', $h);
    if ($h === '' || !str_contains($h, '.') || preg_match('/(^|\.)(resacolo\.com|facebook\.com|instagram\.com|wixsite\.com|google\.com|linktr\.ee)$/', $h)) return '';
    return $h;
}

function rev_email_ok(string $email, string $dom): bool {
    if ($dom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $d = strtolower(substr(strrchr($email, '@'), 1));
    return $d === $dom || str_ends_with($d, '.' . $dom);
}

/** Valide une demande : organisme + séjours (rattachés par organisateur_id) passent au demandeur. Retourne [avant, après]. */
function rev_valider(PDO $pdo, array $d, int $adminId): array {
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT id, nom, user_id, email, non_revendique FROM organisateurs WHERE id = ? FOR UPDATE");
        $st->execute([$d['organisateur_id']]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) throw new RuntimeException('Organisme introuvable.');
        $nb = $pdo->prepare("SELECT COUNT(*) FROM camps WHERE organisateur_id = ?");
        $nb->execute([$o['id']]);
        $email = $d['email_verifie'] && $d['email_pro'] ? $d['email_pro'] : $o['email'];
        $pdo->prepare("UPDATE organisateurs SET user_id = ?, non_revendique = 0, email = ? WHERE id = ?")->execute([$d['user_id'], $email, $o['id']]);
        $pdo->prepare("UPDATE users SET is_directeur = 1 WHERE id = ?")->execute([$d['user_id']]);
        $pdo->prepare("UPDATE orga_revendications SET statut = 'validee', decide_par = ?, decide_at = NOW() WHERE id = ?")->execute([$adminId, $d['id']]);
        $pdo->prepare("UPDATE orga_revendications SET statut = 'refusee', motif = 'Fiche attribuée à un autre demandeur.', decide_par = ?, decide_at = NOW() WHERE organisateur_id = ? AND statut = 'attente'")->execute([$adminId, $o['id']]);
        $pdo->commit();
        return [['user_id' => (int)$o['user_id'], 'email' => $o['email'], 'non_revendique' => (int)$o['non_revendique']],
                ['user_id' => (int)$d['user_id'], 'email' => $email, 'non_revendique' => 0, 'sejours' => (int)$nb->fetchColumn()]];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function rev_mail(string $to, string $sujet, string $html): void {
    @mail($to, '=?UTF-8?B?' . base64_encode($sujet) . '?=', '<div style="font-family:Arial,sans-serif;font-size:15px;color:#26343b;line-height:1.6">' . $html . '<p style="color:#6b7780;font-size:13px">L\'équipe moncamp.fr</p></div>',
        "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: moncamp.fr <auth@moncamp.fr>\r\n");
}
