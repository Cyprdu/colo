<?php
// api/anim_animateur.php : tout ce que fait un ANIMATEUR connecté.
//   GET  ?a=profil                         son profil de candidature
//   POST ?a=profil  (multipart)            enregistre le profil (+ CV pdf/doc/docx, 5 Mo max)
//   GET  ?a=camp&t=<jeton séjour>          fiche d'un séjour qui recrute
//   POST ?a=postuler {camp|lien, message}  candidature
//   GET  ?a=mes                            ses candidatures + notifications
//   POST ?a=retirer {c}                    retire une candidature (jeton)
//   POST ?a=notifs_lues
require_once 'config.php';
require_once 'animateurs.php';

$uid = anim_require_animateur();
anim_ensure($pdo);
$a = $_GET['a'] ?? '';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

function anim_camp_public(array $c, ?string $statut): array {
    $strip = fn($s) => trim(preg_replace('/\s+/', ' ', strip_tags(htmlspecialchars_decode((string)$s))));
    $img = trim((string)$c['image_url']);
    if ($img !== '' && !preg_match('#^(https?:)?//#', $img) && strpos($img, 'uploads/') !== 0) $img = 'uploads/camps/' . ltrim($img, '/');
    return [
        'token' => $c['token'], 'nom' => $strip($c['nom']), 'ville' => $strip($c['ville']), 'image_url' => $img ?: null,
        'date_debut' => $c['date_debut'], 'date_fin' => $c['date_fin'], 'age_min' => $c['age_min'], 'age_max' => $c['age_max'],
        'description' => mb_substr($strip($c['description'] ?? ''), 0, 3000),
        'organisateur' => $strip($c['organisateur_nom'] ?? ''),
        'quota' => (int)($c['quota_max_anim'] ?? 0) ?: null,
        'remuneration' => $c['anim_remuneration'] !== null ? (float)$c['anim_remuneration'] : null,
        'bafa_obligatoire' => !empty($c['bafa_obligatoire']), 'majeur' => !empty($c['anim_plus_18']),
        'ma_candidature' => $statut,
    ];
}

try {
    if ($a === 'profil' && !$post) {
        $p = anim_profil($pdo, $uid);
        $p['has_cv'] = !empty($p['cv_fichier']);
        unset($p['cv_fichier'], $p['user_id']);
        $p['complet'] = anim_profil_complet(anim_profil($pdo, $uid));
        anim_json($p);
    }

    if ($a === 'profil' && $post) {
        anim_csrf();
        $cur = anim_profil($pdo, $uid);
        $statut = $_POST['bafa_statut'] ?? 'aucun';
        if (!in_array($statut, ['bafa', 'en_cours', 'equivalent', 'aucun'], true)) $statut = 'aucun';
        $cv = $cur['cv_fichier']; $cvNom = $cur['cv_nom'];

        if (!empty($_POST['supprimer_cv']) && $cv) {
            @unlink(__DIR__ . '/../uploads/cv/' . $cv); $cv = null; $cvNom = null;
        }
        if (!empty($_FILES['cv']) && $_FILES['cv']['error'] !== UPLOAD_ERR_NO_FILE) {
            $f = $_FILES['cv'];
            if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > ANIM_CV_MAX) anim_json(['error' => 'CV trop lourd (5 Mo maximum) ou envoi impossible.'], 400);
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            $ok = ['pdf' => ['application/pdf'], 'doc' => ['application/msword', 'application/octet-stream'],
                   'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip']];
            if (!isset($ok[$ext]) || !in_array($mime, $ok[$ext], true)) anim_json(['error' => 'Format accepté : PDF, DOC ou DOCX.'], 400);
            $dir = __DIR__ . '/../uploads/cv/';
            if (!is_dir($dir)) { mkdir($dir, 0755, true); }
            @file_put_contents($dir . '.htaccess', "Require all denied\nDeny from all\n");
            $name = bin2hex(random_bytes(20)) . '.' . $ext;
            if (!move_uploaded_file($f['tmp_name'], $dir . $name)) anim_json(['error' => 'Envoi du CV impossible.'], 500);
            if ($cv) @unlink($dir . $cv);
            $cv = $name; $cvNom = mb_substr(preg_replace('/[^\w .\-()]+/u', '_', basename($f['name'])), 0, 200);
        }

        $pdo->prepare("INSERT INTO animateur_profils (user_id, presentation, bafa_statut, bafa_numero, formation, experiences, disponibilites, cv_fichier, cv_nom)
            VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE presentation=VALUES(presentation), bafa_statut=VALUES(bafa_statut), bafa_numero=VALUES(bafa_numero),
            formation=VALUES(formation), experiences=VALUES(experiences), disponibilites=VALUES(disponibilites), cv_fichier=VALUES(cv_fichier), cv_nom=VALUES(cv_nom)")
            ->execute([$uid, anim_txt($_POST['presentation'] ?? '', 2000), $statut, anim_txt($_POST['bafa_numero'] ?? '', 40), anim_txt($_POST['formation'] ?? '', 255),
                       anim_txt($_POST['experiences'] ?? '', 3000), anim_txt($_POST['disponibilites'] ?? '', 1000), $cv, $cvNom]);
        anim_json(['success' => true, 'complet' => anim_profil_complet(anim_profil($pdo, $uid))]);
    }

    if ($a === 'camp' && !$post) {
        $tok = (string)($_GET['t'] ?? '');
        $camp = null;
        if (preg_match('/^[a-f0-9]{16,64}$/i', $tok)) {
            $st = $pdo->prepare("SELECT * FROM camps WHERE token = ? AND recrute_animateurs = 1 AND valide = 1 AND supprime = 0 AND refuse = 0");
            $st->execute([$tok]); $camp = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$camp) anim_json(['error' => "Ce séjour ne recrute pas ou le lien n'est plus valide."], 404);
        $o = $pdo->prepare("SELECT nom FROM organisateurs WHERE id = ?"); $o->execute([$camp['organisateur_id']]);
        $camp['organisateur_nom'] = $o->fetchColumn();
        $m = $pdo->prepare("SELECT statut FROM candidatures WHERE camp_id = ? AND user_id = ?"); $m->execute([$camp['id'], $uid]);
        anim_json(anim_camp_public($camp, $m->fetchColumn() ?: null));
    }

    if ($a === 'postuler' && $post) {
        $in = anim_body();
        $lienId = null;
        {
            $tok = (string)($in['camp'] ?? '');
            $st = $pdo->prepare("SELECT * FROM camps WHERE token = ? AND recrute_animateurs = 1 AND valide = 1 AND supprime = 0 AND refuse = 0");
            $st->execute([$tok]); $camp = $st->fetch(PDO::FETCH_ASSOC);
            if (!$camp) anim_json(['error' => 'Ce séjour ne recrute pas.'], 404);
        }
        if (!anim_profil_complet(anim_profil($pdo, $uid))) anim_json(['error' => "Complétez d'abord votre profil de candidature.", 'profil' => true], 409);
        if (!empty($camp['anim_plus_18'])) {
            $d = $pdo->prepare("SELECT date_naissance FROM users WHERE id = ?"); $d->execute([$uid]); $dn = $d->fetchColumn();
            if ($dn && (new DateTime($dn))->diff(new DateTime())->y < 18) anim_json(['error' => 'Ce séjour est réservé aux animateurs majeurs.'], 403);
        }
        $dup = $pdo->prepare("SELECT id, statut FROM candidatures WHERE camp_id = ? AND user_id = ?"); $dup->execute([$camp['id'], $uid]);
        $old = $dup->fetch(PDO::FETCH_ASSOC);
        if ($old && $old['statut'] !== 'Retirée') anim_json(['error' => 'Vous avez déjà postulé à ce séjour.'], 409);
        if ($old) {   // candidature retirée puis renvoyée : on la remet à zéro
            $pdo->prepare("UPDATE candidatures SET statut = 'En attente', motivation = ?, vue_at = NULL, decided_at = NULL, note_directeur = NULL, created_at = NOW() WHERE id = ?")
                ->execute([anim_txt($in['message'] ?? '', 1500), $old['id']]);
        } else {
            $pdo->prepare("INSERT INTO candidatures (user_id, camp_id, motivation, statut, token, lien_id) VALUES (?,?,?,'En attente',?,?)")
                ->execute([$uid, $camp['id'], anim_txt($in['message'] ?? '', 1500), bin2hex(random_bytes(16)), $lienId]);
        }
        anim_json(['success' => true], 201);
    }

    if ($a === 'mes' && !$post) {
        $st = $pdo->prepare("SELECT cd.token, cd.statut, cd.created_at, cd.decided_at, cd.vue_at, cd.note_directeur, cd.motivation, c.token AS camp_token, c.nom, c.ville, c.image_url,
                c.date_debut, c.date_fin, o.nom AS organisateur, c.anim_remuneration
            FROM candidatures cd JOIN camps c ON c.id = cd.camp_id LEFT JOIN organisateurs o ON o.id = c.organisateur_id
            WHERE cd.user_id = ? ORDER BY cd.created_at DESC");
        $st->execute([$uid]);
        $apps = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($apps as &$x) {
            $img = trim((string)$x['image_url']);
            if ($img !== '' && !preg_match('#^(https?:)?//#', $img) && strpos($img, 'uploads/') !== 0) $img = 'uploads/camps/' . ltrim($img, '/');
            $x['image_url'] = $img ?: null;
        }
        $n = $pdo->prepare("SELECT id, texte, lu, created_at FROM animateur_notifs WHERE user_id = ? ORDER BY id DESC LIMIT 20");
        $n->execute([$uid]);
        anim_json(['candidatures' => $apps, 'notifs' => $n->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($a === 'retirer' && $post) {
        $t = (string)(anim_body()['c'] ?? '');
        $pdo->prepare("UPDATE candidatures SET statut = 'Retirée', decided_at = NOW() WHERE token = ? AND user_id = ? AND statut IN (?, ?)")->execute([$t, $uid, 'En attente', "Liste d'attente"]);
        anim_json(['success' => true]);
    }

    if ($a === 'notifs_lues' && $post) {
        $pdo->prepare("UPDATE animateur_notifs SET lu = 1 WHERE user_id = ?")->execute([$uid]);
        anim_json(['success' => true]);
    }

    anim_json(['error' => 'Action inconnue.'], 400);
} catch (Throwable $e) {
    error_log('anim_animateur: ' . $e->getMessage());
    anim_json(['error' => 'Service momentanément indisponible.'], 500);
}
