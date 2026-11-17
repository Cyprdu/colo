<?php
// api/equipe_infos.php : espace de l'équipe d'animation d'un séjour (option B).
//   Directeur  GET ?a=get&camp=<jeton séjour> | POST ?a=save {camp, blocs, message, date_versement, montre_equipe}
//              POST ?a=doc_add (multipart : camp, fichier PDF) | POST ?a=doc_del {camp, id}
//   Animateur  GET ?a=doc&i=<id>&t=<jeton inscription> (aussi directeur/admin) | POST ?a=annuler {t}
// Accès : l'animateur inscrit, le directeur du séjour, un admin. Aucun identifiant numérique de séjour n'est accepté.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/animateurs.php';
require_once __DIR__ . '/equipe.php';

/** Séjour du directeur connecté (jeton) */
function eqi_camp(PDO $pdo, string $tok, int $uid): array {
    $c = anim_camp_du_directeur($pdo, $tok, $uid);
    if (!$c) anim_json(['error' => 'Séjour introuvable.'], 404);
    return $c;
}

$uid = anim_user_id();
if (!$uid) anim_json(['error' => 'Connectez-vous.'], 401);
anim_ensure($pdo); equipe_ensure($pdo);
$a = $_GET['a'] ?? '';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$isAdmin = !empty($_SESSION['user']['is_admin']);
if (sec_blocked($pdo, 'equipe_infos', (string)$uid, 120, 1)) anim_json(['error' => 'Trop de requêtes. Patientez une minute.'], 429);
sec_hit($pdo, 'equipe_infos', (string)$uid);

try {
    // ---- Téléchargement d'un document (animateur inscrit, directeur ou admin)
    if ($a === 'doc' && !$post) {
        $d = $pdo->prepare("SELECT * FROM equipe_docs WHERE id = ?"); $d->execute([(int)($_GET['i'] ?? 0)]);
        $doc = $d->fetch(PDO::FETCH_ASSOC);
        $acc = $doc ? equipe_acces($pdo, (string)($_GET['t'] ?? ''), $uid, $isAdmin) : null;
        if (!$doc || !$acc || (int)$acc[1]['id'] !== (int)$doc['camp_id'] || ($acc[2] === 'animateur' && $acc[0]['statut'] === 'Annulé')) { http_response_code(404); exit('Introuvable.'); }
        $path = __DIR__ . '/../uploads/equipe/' . $doc['fichier'] . '.pdf';
        if (!preg_match('/^[a-f0-9]{40}$/', $doc['fichier']) || !is_file($path)) { http_response_code(404); exit('Introuvable.'); }
        sec_trace($pdo, $uid, 'equipe_doc', (int)$doc['id']);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.\-]+/', '_', $doc['nom']) . '.pdf"');
        header('Content-Length: ' . filesize($path)); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
        if (ob_get_level()) ob_end_clean();
        readfile($path); exit;
    }

    // ---- Annulation par l'animateur
    if ($a === 'annuler' && $post) {
        $in = anim_body();
        $acc = equipe_acces($pdo, (string)($in['t'] ?? ''), $uid, false);
        if (!$acc || $acc[2] !== 'animateur') anim_json(['error' => 'Inscription introuvable.'], 404);
        [$ok, $msg] = equipe_annuler($pdo, $acc[0], $acc[1], $uid);
        if ($ok) sec_trace($pdo, $uid, 'equipe_annulation', (int)$acc[0]['id']);
        anim_json($ok ? ['success' => true, 'message' => $msg] : ['error' => $msg], $ok ? 200 : 409);
    }

    // ---- Édition par le directeur
    if ($a === 'get' && !$post) {
        $camp = eqi_camp($pdo, (string)($_GET['camp'] ?? ''), $uid);
        anim_json(equipe_infos($pdo, (int)$camp['id']) + ['camp' => ['nom' => html_entity_decode((string)$camp['nom'])], 'remuneration' => $camp['anim_remuneration']]);
    }
    if ($a === 'save' && $post) {
        $in = anim_body();
        $camp = eqi_camp($pdo, (string)($in['camp'] ?? ''), $uid);
        $blocs = [];
        foreach (array_slice(is_array($in['blocs'] ?? null) ? $in['blocs'] : [], 0, EQUIPE_BLOCS_MAX) as $b) {
            $t = anim_txt($b['titre'] ?? '', 120); $x = mb_substr(trim(strip_tags((string)($b['texte'] ?? ''))), 0, 4000);
            if ($t !== '' || $x !== '') $blocs[] = ['titre' => $t, 'texte' => $x];
        }
        $msg = mb_substr(trim(strip_tags((string)($in['message'] ?? ''))), 0, 2000);
        $dv = DateTime::createFromFormat('Y-m-d', (string)($in['date_versement'] ?? ''));
        $dvOk = $dv && $dv->format('Y-m-d') === $in['date_versement'];
        $old = equipe_infos($pdo, (int)$camp['id']);
        $newMsg = $msg !== '' && $msg !== $old['message'];
        $pdo->prepare("INSERT INTO equipe_infos (camp_id, blocs, message, message_at, date_versement, montre_equipe) VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE blocs = VALUES(blocs), message = VALUES(message), message_at = VALUES(message_at), date_versement = VALUES(date_versement), montre_equipe = VALUES(montre_equipe)")
            ->execute([$camp['id'], json_encode($blocs, JSON_UNESCAPED_UNICODE), $msg ?: null, $msg === '' ? null : ($newMsg ? date('Y-m-d H:i:s') : $old['message_at']),
                       $dvOk ? $dv->format('Y-m-d') : null, !empty($in['montre_equipe']) ? 1 : 0]);
        if ($newMsg) {   // les animateurs inscrits sont prévenus dans leurs notifications
            $st = $pdo->prepare("SELECT DISTINCT user_id FROM equipe_inscriptions WHERE camp_id = ? AND statut <> 'Annulé'"); $st->execute([$camp['id']]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) anim_notifier($pdo, (int)$u, 'Nouveau message de l’équipe pour « ' . html_entity_decode((string)$camp['nom']) . ' ».');
        }
        sec_trace($pdo, $uid, 'equipe_infos', (int)$camp['id']);
        anim_json(['success' => true]);
    }
    if ($a === 'doc_add' && $post) {
        anim_csrf();
        $camp = eqi_camp($pdo, (string)($_POST['camp'] ?? ''), $uid);
        $f = $_FILES['fichier'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > EQUIPE_DOC_MAX || $f['size'] < 100) anim_json(['error' => 'PDF de 5 Mo maximum requis.'], 400);
        $n = $pdo->prepare("SELECT COUNT(*) FROM equipe_docs WHERE camp_id = ?"); $n->execute([$camp['id']]);
        if ((int)$n->fetchColumn() >= EQUIPE_DOCS_MAX) anim_json(['error' => 'Maximum ' . EQUIPE_DOCS_MAX . ' documents.'], 400);
        // contrôle du contenu réel (pas seulement l'extension) : type MIME détecté, signature %PDF, aucun script ni fichier embarqué
        $raw = (string)file_get_contents($f['tmp_name']);
        if ((new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) !== 'application/pdf' || strncmp($raw, '%PDF-', 5) !== 0 || preg_match('#/(JavaScript|JS|Launch|EmbeddedFile)\b#', $raw))
            anim_json(['error' => 'Fichier refusé : PDF sans script ni fichier joint uniquement.'], 400);
        $dir = __DIR__ . '/../uploads/equipe/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        @file_put_contents($dir . '.htaccess', "Require all denied\nDeny from all\n");
        $name = bin2hex(random_bytes(20));
        if (!move_uploaded_file($f['tmp_name'], $dir . $name . '.pdf')) anim_json(['error' => 'Envoi impossible.'], 500);
        $nom = mb_substr(preg_replace('/\.pdf$/i', '', preg_replace('/[^\w .\-()]+/u', '_', basename($f['name']))), 0, 200);
        $pdo->prepare("INSERT INTO equipe_docs (camp_id, nom, fichier, taille) VALUES (?,?,?,?)")->execute([$camp['id'], $nom, $name, (int)$f['size']]);
        sec_trace($pdo, $uid, 'equipe_doc_add', (int)$pdo->lastInsertId());
        anim_json(['success' => true, 'docs' => equipe_infos($pdo, (int)$camp['id'])['docs']], 201);
    }
    if ($a === 'doc_del' && $post) {
        $in = anim_body();
        $camp = eqi_camp($pdo, (string)($in['camp'] ?? ''), $uid);
        $d = $pdo->prepare("SELECT fichier FROM equipe_docs WHERE id = ? AND camp_id = ?"); $d->execute([(int)($in['id'] ?? 0), $camp['id']]);
        if ($fich = $d->fetchColumn()) {
            $pdo->prepare("DELETE FROM equipe_docs WHERE id = ? AND camp_id = ?")->execute([(int)$in['id'], $camp['id']]);
            if (preg_match('/^[a-f0-9]{40}$/', $fich)) @unlink(__DIR__ . '/../uploads/equipe/' . $fich . '.pdf');
        }
        anim_json(['success' => true, 'docs' => equipe_infos($pdo, (int)$camp['id'])['docs']]);
    }
    anim_json(['error' => 'Action inconnue.'], 400);
} catch (Throwable $e) {
    error_log('equipe_infos: ' . $e->getMessage());
    anim_json(['error' => 'Service momentanément indisponible.'], 500);
}
