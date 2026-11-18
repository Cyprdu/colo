<?php
/**
 * modif_child.php?id=<jeton public de l'enfant>[#rubrique] : fiche complète d'un enfant, navigation latérale par rubriques.
 * Tout est modifiable à tout moment. Écritures protégées : CSRF, limitation de débit, CAPTCHA Turnstile (si configuré),
 * propriété de l'enfant vérifiée côté serveur, consentement journalisé avant toute donnée de santé, documents chiffrés (api/sante.php).
 */
require_once 'api/config.php';
require_once 'api/enfants.php';
require_once 'api/security.php';
require_once 'api/sante.php';
require_once 'api/sante_convert.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user']['id'])) { header('Location: login.php'); exit; }

$token = (string)($_GET['id'] ?? '');
$parent_id = (int)$_SESSION['user']['id'];

// Anciens liens à id numérique : convertis en jeton (uniquement si l'enfant est bien à ce parent)
if (ctype_digit($token)) {
    $j = '';
    $c = $pdo->prepare("SELECT id FROM enfants WHERE id = ? AND parent_id = ?");
    $c->execute([(int)$token, $parent_id]);
    if ($c->fetchColumn()) $j = enfant_jeton($pdo, (int)$token);
    header('Location: ' . ($j ? 'modif_child?id=' . $j : 'children'));
    exit;
}
sante_ensure($pdo);
$enfant = enfant_par_jeton($pdo, $token, $parent_id);
if (!$enfant) { header('Location: children'); exit; }
$child_id = (int)$enfant['id'];
$self = 'modif_child?id=' . $token;

// ---------- champs modifiables par rubrique (liste blanche : jamais de nom de colonne venant du formulaire) ----------
$TXT = [
    'parents' => ['resp1_civilite','resp1_nom','resp1_prenom','resp1_email','resp1_tel','resp1_statut','resp1_profession',
                  'resp2_civilite','resp2_nom','resp2_prenom','resp2_email','resp2_tel','resp2_statut','resp2_profession'],
    'enfant'  => ['civilite','sexe','prenom','nom','date_naissance','adresse','code_postal','ville','pays','email_enfant','tel_mobile_enfant','tel_fixe_enfant'],
    'sante'   => ['taille','poids','medecin_nom','medecin_tel','urgence_nom','urgence_tel','urgence_lien'],
    'autorisations' => ['commentaires'],
];
$BOOL = ['autorisations' => ['droit_image','autorisation_contact','accord_parental','cgv_accepte'], 'newsletter' => ['newsletter_accepte']];
$LONG = ['commentaires'];
$TELS = ['resp1_tel','resp2_tel','tel_mobile_enfant','tel_fixe_enfant','medecin_tel','urgence_tel'];
$MAILS = ['resp1_email','resp2_email','email_enfant'];
// clés stockées dans le JSON de la fiche (vaccins_data), par rubrique
$JSONK = ['parents' => FICHE_JSON_PARENTS, 'sante' => ['autre_nom', 'all_alim', 'all_med', 'all_aut']];
foreach (array_keys(FICHE_VACCINS) as $k) array_push($JSONK['sante'], "v_$k", "d_$k");

$flash = $_SESSION['flash_child'] ?? null; unset($_SESSION['flash_child']);
$message = $flash[1] ?? ''; $message_type = $flash[0] ?? '';
$sectionOuverte = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['act'] ?? 'save:all');
    $sectionOuverte = preg_match('/^[a-z]{3,15}$/', (string)($_POST['current'] ?? '')) ? $_POST['current'] : '';
    try {
        if (!csrf_check()) throw new Exception("Session expirée : rechargez la page puis réessayez.");
        if (sec_blocked($pdo, 'child_w', (string)$parent_id, 80, 10)) throw new Exception("Trop d'enregistrements d'affilée. Patientez quelques minutes.");
        sec_hit($pdo, 'child_w', (string)$parent_id);
        if (!sec_turnstile_ok()) throw new Exception("Vérification anti-robot échouée : réessayez.");

        $sets = []; $vals = [];   // colonnes à mettre à jour
        $put = function (string $col, $v) use (&$sets, &$vals) { $sets[] = "`$col` = ?"; $vals[] = $v; };
        $wantSections = [];
        if (strpos($act, 'save:') === 0) { $s = substr($act, 5); $wantSections = $s === 'all' ? array_keys(FICHE_RUBRIQUES) : [$s]; }
        elseif ($act === 'generer_fiche') $wantSections = ['enfant', 'parents', 'sante'];
        // le formulaire de génération (onglet Documents) envoie ses propres champs g[...] ; seuls les champs présents sont mis à jour
        $src = $act === 'generer_fiche' ? (array)($_POST['g'] ?? []) : $_POST;
        $json = fiche_json($enfant); $jsonTouche = false;

        // --- champs texte / cases des rubriques demandées ---
        foreach ($wantSections as $sec) {
            if (!isset(FICHE_RUBRIQUES[$sec])) throw new Exception('Rubrique inconnue.');
            foreach ($JSONK[$sec] ?? [] as $k) {
                if (!array_key_exists($k, $src)) continue;
                $v = mb_substr(trim((string)$src[$k]), 0, 200);
                if (strpos($k, 'v_') === 0 && !in_array($v, ['', 'oui', 'non'], true)) throw new Exception('Réponse invalide.');
                if (strpos($k, 'd_') === 0 && $v !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || $v > date('Y-m-d'))) throw new Exception('Date de vaccin invalide : ' . $v);
                if (strpos($k, '_tel_') !== false && $v !== '' && !preg_match('/^[0-9 +().-]{6,30}$/', $v)) throw new Exception("Numéro de téléphone invalide : " . $v);
                if (strpos($k, 'all_') === 0) $v = $v === '1' ? '1' : '0';
                $json[$k] = $v; $jsonTouche = true;
            }
            if ($sec === 'sante') foreach (FICHE_YN as $k => [$q, $neg]) {   // questions oui / non : « Non » enregistre explicitement « Aucun(e) »
                if (!array_key_exists("yn_$k", $src)) continue;
                $r = (string)$src["yn_$k"]; $txt = mb_substr(trim((string)($src[$k] ?? '')), 0, 2000);
                if ($r === 'oui' && ($txt === '' || fiche_est_negatif($txt))) throw new Exception("Vous avez répondu « Oui » : précisez. (" . $q . ")");
                $v = $r === 'non' ? $neg : ($r === 'oui' ? $txt : '');
                if (in_array($k, FICHE_YN_JSON, true)) { $json[$k] = $v; $jsonTouche = true; } else { $enfant[$k] = $v; $put($k, $v); }
            }
            foreach ($TXT[$sec] ?? [] as $k) {
                if (!array_key_exists($k, $src)) continue;
                $v = trim((string)$src[$k]);
                $v = mb_substr($v, 0, in_array($k, $LONG, true) ? 2000 : 255);
                $enfant[$k] = $v;
                if (in_array($k, $MAILS, true) && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) throw new Exception("Adresse e-mail invalide : " . $v);
                if (in_array($k, $TELS, true) && $v !== '' && !preg_match('/^[0-9 +().-]{6,30}$/', $v)) throw new Exception("Numéro de téléphone invalide : " . $v);
                if ($k === 'date_naissance') {
                    $d = DateTime::createFromFormat('Y-m-d', $v);
                    if (!$d || $d->format('Y-m-d') !== $v || $d > new DateTime() || $d < new DateTime('1990-01-01')) throw new Exception("Date de naissance invalide.");
                }
                if ($k === 'sexe' && !in_array($v, ['Homme', 'Femme', 'Autre'], true)) throw new Exception("Sexe invalide.");
                if (in_array($k, ['prenom', 'nom'], true) && $v === '') throw new Exception("Le prénom et le nom sont obligatoires.");
                $put($k, $v);
            }
            if ($act !== 'generer_fiche') foreach ($BOOL[$sec] ?? [] as $k) { $enfant[$k] = isset($_POST[$k]) ? 1 : 0; $put($k, $enfant[$k]); }
        }
        if ($jsonTouche) { $enfant['vaccins_data'] = json_encode($json, JSON_UNESCAPED_UNICODE); $put('vaccins_data', $enfant['vaccins_data']); }

        // --- consentement explicite avant toute donnée de santé ---
        $santeEnJeu = in_array('sante', $wantSections, true) && trim(implode('', array_map(fn($k) => (string)($enfant[$k] ?? ''), ['infos_sante','allergies','traitements','regime_alimentaire','medecin_nom','medecin_tel','urgence_nom']))) . implode('', array_diff(array_intersect_key($json, array_flip([...$JSONK['sante'], ...FICHE_YN_JSON])), ['0'])) !== ''
            || in_array($act, ['doc_carnet', 'doc_fiche', 'generer_fiche'], true);
        if ($santeEnJeu && empty($enfant['sante_consent_at'])) {
            if (empty($_POST['consent_sante'])) throw new Exception("Cochez le consentement au traitement des données de santé pour enregistrer ces informations.");
            $put('sante_consent_at', date('Y-m-d H:i:s'));
            consent_log($pdo, $parent_id, 'sante_enfant', null, true);
            $enfant['sante_consent_at'] = date('Y-m-d H:i:s');
        }

        $ok = "Modifications enregistrées.";
        $newFiles = []; $oldFiles = [];   // [dossier, nom]

        // --- photo ---
        if ($act === 'photo_up') {
            $n = sante_save_upload($_FILES['photo'] ?? [], 'photos', 'photo');
            $newFiles[] = ['photos', $n]; $oldFiles[] = ['photos', $enfant['photo_token'] ?? null];
            $put('photo_token', $n); $ok = "Photo mise à jour."; $sectionOuverte = 'enfant';
        } elseif ($act === 'photo_del') {
            $oldFiles[] = ['photos', $enfant['photo_token'] ?? null]; $put('photo_token', null); $ok = "Photo supprimée."; $sectionOuverte = 'enfant';
        }
        // --- documents ---
        elseif ($act === 'doc_carnet') {
            $n = sante_save_as_pdf($_FILES['carnet'] ?? [], 'sante');
            $newFiles[] = ['sante', $n]; $oldFiles[] = ['sante', $enfant['carnet_sante_token'] ?? null];
            $put('carnet_sante_token', $n); $put('carnet_at', date('Y-m-d H:i:s')); $ok = "Carnet de santé enregistré."; $sectionOuverte = 'documents';
        } elseif ($act === 'doc_fiche') {
            $n = sante_save_as_pdf($_FILES['fiche'] ?? [], 'sanitaire');
            $newFiles[] = ['sanitaire', $n]; $oldFiles[] = ['sanitaire', $enfant['fiche_sanitaire_token'] ?? null];
            $put('fiche_sanitaire_token', $n); $put('fiche_origine', 'importee'); $put('fiche_at', date('Y-m-d H:i:s')); $ok = "Fiche sanitaire enregistrée."; $sectionOuverte = 'documents';
        } elseif ($act === 'del_carnet') {
            $oldFiles[] = ['sante', $enfant['carnet_sante_token'] ?? null]; $put('carnet_sante_token', null); $put('carnet_at', null); $ok = "Carnet de santé supprimé."; $sectionOuverte = 'documents';
        } elseif ($act === 'del_fiche') {
            $oldFiles[] = ['sanitaire', $enfant['fiche_sanitaire_token'] ?? null]; $put('fiche_sanitaire_token', null); $put('fiche_origine', null); $put('fiche_at', null); $ok = "Fiche sanitaire supprimée."; $sectionOuverte = 'documents';
        } elseif ($act === 'generer_fiche') {
            if (empty($_POST['certifie'])) throw new Exception("Cochez « Je certifie l'exactitude des informations » pour générer la fiche.");
            $sig = mb_substr(trim((string)($_POST['signataire'] ?? '')), 0, 120);
            if ($sig === '') $sig = trim(($enfant['resp1_prenom'] ?? '') . ' ' . ($enfant['resp1_nom'] ?? ''));
            if ($sig === '') throw new Exception("Indiquez le nom du signataire (responsable légal).");
            if (empty($enfant['date_naissance'])) throw new Exception("Renseignez d'abord la date de naissance (rubrique Informations de l'enfant).");
            // signature manuscrite (tracé JPEG envoyé par le canvas) : obligatoire, vérifiée, stockée chiffrée
            if (!preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', (string)($_POST['signature_data'] ?? ''), $sm)) throw new Exception("Signez dans le cadre pour générer la fiche.");
            $sigBytes = base64_decode($sm[1], true);
            $si = $sigBytes ? @getimagesizefromstring($sigBytes) : false;
            if (!$si || $si[2] !== IMAGETYPE_JPEG || strlen($sigBytes) > 400000 || $si[0] > 1200 || $si[1] > 600) throw new Exception("Signature invalide : recommencez.");
            $sigName = sante_store('signatures', $sigBytes, 'jpg');
            $newFiles[] = ['signatures', $sigName]; $oldFiles[] = ['signatures', $enfant['signature_token'] ?? null];
            $put('signature_token', $sigName);
            require_once 'api/sante_pdf.php';
            $row = $enfant; foreach ($sets as $i => $sdef) { if (preg_match('/^`(\w+)`/', $sdef, $m)) $row[$m[1]] = $vals[$i]; }
            $n = sante_store('sanitaire', sante_pdf_fiche($row, $sig, $sigBytes), 'pdf');
            $newFiles[] = ['sanitaire', $n]; $oldFiles[] = ['sanitaire', $enfant['fiche_sanitaire_token'] ?? null];
            $put('fiche_sanitaire_token', $n); $put('fiche_origine', 'generee'); $put('fiche_at', date('Y-m-d H:i:s')); $ok = "Fiche sanitaire de liaison générée."; $sectionOuverte = 'documents';
        } elseif ($act === 'purge_sante') {
            sante_purge_enfant($pdo, $child_id);
            consent_log($pdo, $parent_id, 'sante_enfant', null, false);
            $_SESSION['flash_child'] = ['success', "Les données de santé et les documents de " . $enfant['prenom'] . " ont été effacés."];
            header('Location: ' . $self . '#sante'); exit;
        } elseif (strpos($act, 'save:') !== 0) throw new Exception('Action inconnue.');

        if ($sets) {
            $pdo->prepare("UPDATE enfants SET " . implode(', ', $sets) . " WHERE id = ? AND parent_id = ?")->execute([...$vals, $child_id, $parent_id]);
        }
        foreach ($oldFiles as [$d, $n]) sante_unlink($d, $n);   // seulement après l'écriture en base
        $_SESSION['flash_child'] = ['success', $ok];
        $dest = $sectionOuverte ?: (count($wantSections) === 1 ? $wantSections[0] : '');
        header('Location: ' . $self . ($dest ? '#' . $dest : '')); exit;
    } catch (Throwable $ex) {
        foreach ($newFiles ?? [] as [$d, $n]) sante_unlink($d, $n);   // le fichier écrit n'est pas resté orphelin
        $message = $ex instanceof PDOException ? "Erreur lors de l'enregistrement. Réessayez dans un instant." : $ex->getMessage();
        if ($ex instanceof PDOException) error_log('modif_child: ' . $ex->getMessage());
        $message_type = 'error';
    }
}

// Valeurs affichées : base (+ saisies conservées si erreur)
if ($message_type !== 'error') {
    $st = $pdo->prepare("SELECT * FROM enfants WHERE id = ? AND parent_id = ?");
    $st->execute([$child_id, $parent_id]);
    $enfant = $st->fetch(PDO::FETCH_ASSOC) ?: $enfant;
}
[$pct, $manque, $rubOk] = fiche_completude($enfant);

$json = fiche_json($enfant);
$P = '';   // préfixe des champs : '' pour les onglets, 'g' pour le formulaire de génération de la fiche sanitaire
$nm = function ($k) use (&$P) { return $P === '' ? $k : $P . '[' . $k . ']'; };
$id = function ($k) use (&$P) { return ($P === '' ? 'f-' : $P . '-') . $k; };
$e = fn($k) => htmlspecialchars((string)($enfant[$k] ?? $json[$k] ?? ''));
$in = function ($k, $label, $type = 'text', $extra = '') use ($e, $nm, $id) {
    echo '<div><label class="ds-label" for="' . $id($k) . '">' . $label . '</label><input id="' . $id($k) . '" type="' . $type . '" name="' . $nm($k) . '" value="' . $e($k) . '" class="ds-input" ' . $extra . '></div>';
};
$ta = function ($k, $label, $rows = 3, $ph = '') use ($e, $nm, $id) {
    echo '<div><label class="ds-label" for="' . $id($k) . '">' . $label . '</label><textarea id="' . $id($k) . '" name="' . $nm($k) . '" rows="' . $rows . '" maxlength="2000" class="ds-textarea" placeholder="' . $ph . '">' . $e($k) . '</textarea></div>';
};
$civ = function ($k, $label) use ($enfant, $nm, $id) {
    echo '<div><label class="ds-label" for="' . $id($k) . '">' . $label . '</label><select id="' . $id($k) . '" name="' . $nm($k) . '" class="ds-select"><option value="">—</option>';
    foreach (['M.', 'Mme'] as $o) echo '<option' . (($enfant[$k] ?? '') === $o ? ' selected' : '') . '>' . $o . '</option>';
    echo '</select></div>';
};
$sexe = function () use ($enfant, $nm, $id) {
    echo '<div><label class="ds-label" for="' . $id('sexe') . '">Sexe</label><select id="' . $id('sexe') . '" name="' . $nm('sexe') . '" class="ds-select">';
    foreach (['Homme' => 'Garçon', 'Femme' => 'Fille', 'Autre' => 'Autre'] as $v => $l) echo '<option value="' . $v . '"' . (($enfant['sexe'] ?? '') === $v ? ' selected' : '') . '>' . $l . '</option>';
    echo '</select></div>';
};
$seg = function ($k, $etat, $label) use ($nm) {   // boutons Oui / Non
    $h = '<span class="yn-seg" role="radiogroup" aria-label="' . htmlspecialchars($label) . '">';
    foreach (['oui' => 'Oui', 'non' => 'Non'] as $v => $l) $h .= '<label><input type="radio" name="' . $nm($k) . '" value="' . $v . '"' . ($etat === $v ? ' checked' : '') . '>' . $l . '</label>';
    return $h . '</span>';
};
$yn = function ($k) use ($enfant, $json, $nm, $id, $seg) {   // question oui / non : « Oui » déroule le champ à préciser
    [$q, , $aide] = FICHE_YN[$k];
    $val = (string)(in_array($k, FICHE_YN_JSON, true) ? ($json[$k] ?? '') : ($enfant[$k] ?? ''));
    $etat = fiche_yn_etat($val);
    echo '<div class="yn border border-[#e5e7eb] rounded-2xl p-4"><div class="flex flex-wrap items-center justify-between gap-3"><p class="font-bold text-[14.5px] text-[#26343b]">' . $q . '</p>' . $seg("yn_$k", $etat, $q) . '</div><div class="yn-body mt-3 space-y-3">';
    if ($k === 'allergies') {
        echo '<div class="flex flex-wrap gap-x-5 gap-y-2">';
        foreach (['all_alim' => 'Alimentaires', 'all_med' => 'Médicamenteuses', 'all_aut' => 'Autres (animaux, plantes, pollen)'] as $c => $l)
            echo '<label class="flex items-center gap-2 text-[14px] text-[#26343b] cursor-pointer"><input type="hidden" name="' . $nm($c) . '" value="0"><input type="checkbox" name="' . $nm($c) . '" value="1" class="w-4 h-4"' . (!empty($json[$c]) ? ' checked' : '') . '>' . $l . '</label>';
        echo '</div>';
    }
    echo '<label class="sr-only" for="' . $id($k) . '">Précisez</label><textarea id="' . $id($k) . '" name="' . $nm($k) . '" rows="2" maxlength="2000" class="ds-textarea" placeholder="Précisez : ' . htmlspecialchars($aide) . '">' . ($etat === 'oui' ? htmlspecialchars($val) : '') . '</textarea></div></div>';
};
$vaccins = function () use ($json, $nm, $seg) {   // tableau du CERFA : oui / non puis date du dernier rappel
    $old = array_filter(['DTP' => $json['dtp'] ?? '', 'BCG' => $json['bcg'] ?? '', 'Autres' => $json['autre'] ?? '']);
    if ($old) echo '<p class="text-[12.5px] text-[#6b7780] mb-2">Saisie précédente : ' . htmlspecialchars(implode(' ; ', array_map(fn($k, $v) => "$k $v", array_keys($old), $old))) . '</p>';
    echo '<div class="border border-[#e5e7eb] rounded-2xl divide-y divide-[#eef0f2]">';
    foreach (FICHE_VACCINS as $k => $lib) {
        $ob = in_array($k, FICHE_VACCINS_OBLIG, true);
        echo '<div class="yn flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3"><span class="flex-1 min-w-[160px] text-[14px] font-semibold text-[#26343b]">' . $lib . ($ob ? ' <span class="text-[11.5px] font-bold text-[#8a6d1f]">obligatoire</span>' : '') . '</span>' . $seg("v_$k", (string)($json["v_$k"] ?? ''), $lib . ' : vacciné ?') . '<span class="yn-body flex flex-wrap gap-2">';
        if ($k === 'autre') echo '<input name="' . $nm('autre_nom') . '" aria-label="Nom du vaccin" placeholder="Lequel ?" class="ds-input" style="width:150px" value="' . htmlspecialchars((string)($json['autre_nom'] ?? '')) . '">';
        echo '<input type="date" name="' . $nm("d_$k") . '" aria-label="' . $lib . ' : date du dernier rappel" title="Date du dernier rappel" max="' . date('Y-m-d') . '" class="ds-input" style="width:170px" value="' . htmlspecialchars((string)($json["d_$k"] ?? '')) . '"></span></div>';
    }
    echo '</div>';
};
$santeForm = function (bool $urgence) use ($in, $yn, $vaccins) {
    echo '<div class="mt-6 space-y-3">'; foreach (array_keys(FICHE_YN) as $k) $yn($k); echo '</div>';
    echo '<div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-5">'; $in('taille', 'Taille (cm)', 'number', 'min="30" max="230"'); $in('poids', 'Poids (kg)', 'number', 'min="5" max="200" step="0.1"'); echo '</div>';
    echo '<h3 class="ds-eyebrow mt-7 mb-1">Vaccinations</h3><p class="text-[13px] text-[#6b7780] mb-3">Reportez la date du dernier rappel inscrite dans le carnet de santé.</p>'; $vaccins();
    echo '<h3 class="ds-eyebrow mt-7 mb-4">Médecin traitant</h3><div class="grid grid-cols-1 sm:grid-cols-2 gap-5">'; $in('medecin_nom', 'Nom du médecin'); $in('medecin_tel', 'Téléphone', 'tel'); echo '</div>';
    if ($urgence) { echo '<h3 class="ds-eyebrow mt-7 mb-4">Personne à prévenir en cas d\'urgence</h3><div class="grid grid-cols-1 sm:grid-cols-3 gap-5">'; $in('urgence_nom', 'Nom et prénom'); $in('urgence_tel', 'Téléphone', 'tel'); $in('urgence_lien', 'Lien avec l\'enfant'); echo '</div>'; }
};
$chk = function ($k, $label, $hint = '') use ($enfant) {
    echo '<label class="flex items-start gap-3 cursor-pointer"><input type="checkbox" name="' . $k . '" value="1" class="mt-1 w-4 h-4"' . (!empty($enfant[$k]) ? ' checked' : '') . '><span><span class="block text-[14px] font-semibold text-[#26343b]">' . $label . '</span>' . ($hint ? '<span class="block text-[12.5px] text-[#6b7780]">' . $hint . '</span>' : '') . '</span></label>';
};
$save = function (string $sec, string $label = 'Enregistrer cette rubrique') {
    echo '<div class="mt-7 flex flex-wrap justify-end gap-3"><button type="submit" name="act" value="save:' . $sec . '" class="ds-btn">' . $label . '</button></div>';
};
$consentBloc = function () use ($enfant) {
    if (!empty($enfant['sante_consent_at'])) {
        echo '<p class="text-[13px] text-[#6b7780]">Consentement au traitement des données de santé enregistré le ' . htmlspecialchars(date('d/m/Y', strtotime($enfant['sante_consent_at']))) . '.</p>';
        return;
    }
    echo '<label class="flex items-start gap-3 cursor-pointer bg-[#f8f5ea] border border-[#ebe3c8] rounded-xl p-4"><input type="checkbox" name="consent_sante" value="1" class="mt-1 w-4 h-4"><span class="text-[13.5px] text-[#26343b]"><strong>Consentement (données de santé).</strong> J\'accepte que moncamp.fr enregistre, de façon chiffrée, les informations de santé de mon enfant et ses documents médicaux, uniquement pour les transmettre aux organisateurs des séjours auxquels je l\'inscris. Je peux les effacer à tout moment depuis cette page. <a href="legal#confidentialite" class="underline text-[#3d6a8e]" target="_blank">Politique de confidentialité</a></span></label>';
};
$fileUrl = fn($type, $name) => 'api/secure_file.php?type=' . $type . '&file=' . urlencode((string)$name);
$age = null;
if (!empty($enfant['date_naissance'])) { try { $age = (new DateTime($enfant['date_naissance']))->diff(new DateTime())->y; } catch (Throwable $x) {} }

$body_class = 'bg-white';
require_once 'partials/header.php';
?>
<title>Fiche de <?= htmlspecialchars($enfant['prenom']) ?> - moncamp.fr</title>
<style>
  .fc-nav a { display:flex; align-items:center; gap:10px; padding:11px 14px; border-radius:12px; font-size:14.5px; font-weight:600; color:#3b4a54; white-space:nowrap }
  .fc-nav a:hover { background:#f1f3f5 }
  .fc-nav a.on { background:#2f3d45; color:#fff }
  .fc-dot { width:9px; height:9px; border-radius:50%; background:#d6a85a; flex:none }
  .fc-dot.ok { background:#5f8a74 }
  .js .fc-panel { display:none } .js .fc-panel.on { display:block }
  [data-fm][hidden] { display:none !important }
  .yn:not(:has(input[value="oui"]:checked)) .yn-body { display:none }
  .yn-seg { display:inline-flex; flex:none; border:1.5px solid #d5dbe0; border-radius:12px; overflow:hidden }
  .yn-seg label { position:relative; padding:7px 18px; font-size:14px; font-weight:700; color:#3b4a54; cursor:pointer }
  .yn-seg label + label { border-left:1.5px solid #d5dbe0 }
  .yn-seg input { position:absolute; opacity:0; pointer-events:none }
  .yn-seg label:has(input:checked) { background:#2f3d45; color:#fff }
  .yn-seg label:has(input:focus-visible) { outline:2px solid #3d6a8e; outline-offset:-3px }
  @media (max-width: 1023px) { .fc-nav { display:flex; overflow-x:auto; gap:4px } }
</style>

<main class="ds-page" style="max-width:1100px">
    <a href="children" class="inline-flex items-center gap-2 text-[14px] font-semibold text-[#3b4a54] hover:text-[#26343b]">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 12H4m6-6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Mes enfants
    </a>

    <?php if ($message): ?>
        <div class="<?= $message_type === 'success' ? 'ds-alert-ok' : 'ds-alert-err' ?> mt-5 font-semibold" role="<?= $message_type === 'success' ? 'status' : 'alert' ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="fiche" class="mt-6 grid grid-cols-1 lg:grid-cols-[290px_1fr] gap-6 items-start">
        <?= csrf_field() ?>
        <input type="hidden" name="current" id="current" value="">
        <button type="submit" name="act" value="save:all" style="display:none" tabindex="-1" aria-hidden="true">Enregistrer</button>

        <!-- Navigation latérale -->
        <aside class="lg:sticky lg:top-[92px]">
            <div class="ds-card p-5">
                <div class="flex items-center gap-3">
                    <?php if (!empty($enfant['photo_token'])): ?>
                        <img src="<?= htmlspecialchars($fileUrl('photos', $enfant['photo_token'])) ?>" alt="" class="w-14 h-14 rounded-full object-cover flex-none">
                    <?php else: ?>
                        <span class="w-14 h-14 flex-none rounded-full bg-[#3a4852] text-white text-[22px] font-bold flex items-center justify-center"><?= htmlspecialchars(mb_strtoupper(mb_substr((string)$enfant['prenom'], 0, 1))) ?></span>
                    <?php endif; ?>
                    <div class="min-w-0">
                        <p class="font-extrabold text-[#26343b] truncate"><?= htmlspecialchars($enfant['prenom'] . ' ' . $enfant['nom']) ?></p>
                        <p class="text-[13px] text-[#6b7780]"><?= $age !== null ? $age . ' ans' : 'Âge non renseigné' ?></p>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="flex justify-between text-[13px] font-bold text-[#26343b]"><span>Fiche complète à <?= $pct ?> %</span></div>
                    <div class="mt-1.5 h-2 rounded-full bg-[#e5e7eb] overflow-hidden" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full bg-[#2f3d45]" style="width:<?= $pct ?>%"></div></div>
                </div>
            </div>

            <nav class="fc-nav ds-card p-2 mt-4 flex flex-col gap-0.5" aria-label="Rubriques de la fiche">
                <?php foreach (FICHE_RUBRIQUES as $key => $label): ?>
                    <a href="#<?= $key ?>" data-go="<?= $key ?>"><span class="fc-dot<?= !empty($rubOk[$key]) ? ' ok' : '' ?>"></span><?= htmlspecialchars($label) ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ($manque): ?>
            <div class="ds-card p-5 mt-4 hidden lg:block">
                <p class="ds-eyebrow mb-2">Il manque</p>
                <ul class="space-y-1.5 text-[13.5px]">
                    <?php foreach ($manque as [$lab, $rub]): ?><li><a href="#<?= $rub ?>" data-go="<?= $rub ?>" class="text-[#3d6a8e] font-semibold hover:underline"><?= htmlspecialchars($lab) ?></a></li><?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </aside>

        <!-- Contenu -->
        <div class="min-w-0 space-y-6">

            <?php if ($manque): ?>
            <div class="ds-alert-err lg:hidden"><p class="font-bold mb-1">Il manque :</p><ul class="list-disc pl-5 text-[13.5px]"><?php foreach ($manque as [$lab, $rub]): ?><li><a href="#<?= $rub ?>" data-go="<?= $rub ?>" class="underline"><?= htmlspecialchars($lab) ?></a></li><?php endforeach; ?></ul></div>
            <?php endif; ?>

            <section id="p-parents" class="fc-panel ds-card p-6 sm:p-8" data-panel="parents">
                <h2 class="ds-h1" style="font-size:24px">Informations parents</h2>
                <p class="ds-lead" style="font-size:14px">Les responsables légaux, joignables pendant le séjour.</p>
                <?php foreach ([1 => 'Responsable légal n°1', 2 => 'Responsable légal n°2 (facultatif)'] as $n => $titre): ?>
                    <h3 class="ds-eyebrow mt-7 mb-4"><?= $titre ?></h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <?php $civ("resp{$n}_civilite", 'Civilité'); $in("resp{$n}_statut", "Lien avec l'enfant", 'text', 'placeholder="Père, mère, tuteur…"');
                        $in("resp{$n}_nom", 'Nom'); $in("resp{$n}_prenom", 'Prénom'); $in("resp{$n}_email", 'E-mail', 'email'); $in("resp{$n}_tel", 'Téléphone portable', 'tel'); $in("resp{$n}_tel_dom", 'Téléphone domicile', 'tel'); $in("resp{$n}_tel_trav", 'Téléphone travail', 'tel'); $in("resp{$n}_profession", 'Profession');
                        if ($n === 2) { echo '<div class="sm:col-span-2">'; $in('resp2_adresse', 'Adresse (si différente de celle de l\'enfant)'); echo '</div>'; } ?>
                    </div>
                <?php endforeach; ?>
                <?php $save('parents'); ?>
            </section>

            <section id="p-enfant" class="fc-panel ds-card p-6 sm:p-8" data-panel="enfant">
                <h2 class="ds-h1" style="font-size:24px">Informations de l'enfant</h2>
                <div class="mt-5 flex flex-wrap items-center gap-5 pb-6 border-b border-[#e5e7eb]">
                    <?php if (!empty($enfant['photo_token'])): ?>
                        <img id="photo-prev" src="<?= htmlspecialchars($fileUrl('photos', $enfant['photo_token'])) ?>" alt="Photo de l'enfant" class="w-24 h-24 rounded-full object-cover flex-none">
                    <?php else: ?>
                        <span id="photo-init" class="w-24 h-24 flex-none rounded-full bg-[#3a4852] text-white text-[36px] font-extrabold flex items-center justify-center"><?= htmlspecialchars(mb_strtoupper(mb_substr((string)$enfant['prenom'], 0, 1))) ?></span>
                        <img id="photo-prev" src="" alt="" class="w-24 h-24 rounded-full object-cover flex-none hidden">
                    <?php endif; ?>
                    <div class="flex-1 min-w-[220px]">
                        <label class="ds-label" for="photo">Photo de l'enfant </label><details class="ds-help mb-2"><summary>?</summary><div>JPEG, PNG ou WebP, 5 Mo maximum. Recadrée en carré, stockée de façon privée et chiffrée ; visible uniquement par vous et les organisateurs des séjours de l'enfant.</div></details>
                        <input id="photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="ds-file">
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" name="act" value="photo_up" id="photo-go" class="ds-btn ds-btn-light" disabled>Enregistrer</button>
                        <?php if (!empty($enfant['photo_token'])): ?><button type="submit" name="act" value="photo_del" class="ds-btn ds-btn-light">Retirer</button><?php endif; ?>
                    </div>
                </div>
                <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <?php $civ('civilite', 'Civilité'); ?>
                    <?php $sexe(); ?>
                    <?php $in('prenom', 'Prénom', 'text', 'required'); $in('nom', 'Nom', 'text', 'required'); $in('date_naissance', 'Date de naissance', 'date', 'max="' . date('Y-m-d') . '"'); ?>
                    <div class="sm:col-span-2"><?php $in('adresse', 'Adresse'); ?></div>
                    <?php $in('code_postal', 'Code postal'); $in('ville', 'Ville'); $in('pays', 'Pays'); $in('email_enfant', 'E-mail de l\'enfant', 'email'); $in('tel_mobile_enfant', 'Mobile', 'tel'); $in('tel_fixe_enfant', 'Téléphone fixe', 'tel'); ?>
                </div>
                <?php $save('enfant'); ?>
            </section>

            <section id="p-sante" class="fc-panel ds-card p-6 sm:p-8" data-panel="sante">
                <h2 class="ds-h1" style="font-size:24px">Santé</h2>
                <p class="ds-lead" style="font-size:14px">Ces informations alimentent la fiche sanitaire de liaison. Seuls vous et le directeur d'un séjour où l'enfant est inscrit peuvent les voir.</p>
                <?php $santeForm(true); ?>
                <div class="mt-7"><?php $consentBloc(); ?></div>
                <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                    <?php if (!empty($enfant['sante_consent_at'])): ?><button type="submit" name="act" value="purge_sante" class="text-[13.5px] font-bold text-[#5b4a1c] underline" onclick="return confirm('Effacer définitivement toutes les données de santé et les documents de cet enfant ?')">Effacer les données de santé</button><?php else: ?><span></span><?php endif; ?>
                    <button type="submit" name="act" value="save:sante" class="ds-btn">Enregistrer la santé</button>
                </div>
            </section>

            <section id="p-autorisations" class="fc-panel ds-card p-6 sm:p-8" data-panel="autorisations">
                <h2 class="ds-h1" style="font-size:24px">Autorisations et droit à l'image</h2>
                <div class="mt-6 space-y-4">
                    <?php $chk('accord_parental', 'Accord parental', "Je certifie avoir l'autorité parentale et j'autorise mon enfant à participer aux séjours.");
                    $chk('droit_image', "Droit à l'image", "Photos et vidéos du séjour utilisables par l'organisateur.");
                    $chk('autorisation_contact', 'Autorisation de contact', "L'organisateur peut joindre l'enfant directement.");
                    $chk('cgv_accepte', 'Conditions générales de vente acceptées'); ?>
                </div>
                <div class="mt-6"><?php $ta('commentaires', 'Commentaires pour les organisateurs', 3); ?></div>
                <?php $save('autorisations'); ?>
            </section>

            <section id="p-newsletter" class="fc-panel ds-card p-6 sm:p-8" data-panel="newsletter">
                <h2 class="ds-h1" style="font-size:24px">Newsletter et communications</h2>
                <div class="mt-6"><?php $chk('newsletter_accepte', 'Actualités et nouveaux séjours', 'Recevoir par e-mail les nouveautés adaptées à l\'âge de cet enfant. Modifiable à tout moment.'); ?></div>
                <?php $save('newsletter'); ?>
            </section>

            <section id="p-documents" class="fc-panel ds-card p-6 sm:p-8" data-panel="documents">
                <h2 class="ds-h1" style="font-size:24px">Documents <details class="ds-help"><summary>?</summary><div>Stockés chiffrés, hors accès direct. Chaque ouverture par un tiers autorisé (organisateur du séjour) est journalisée. Vous pouvez les supprimer à tout moment.</div></details></h2>
                <?php
                $dt = fn($d) => $d ? ' le ' . date('d/m/Y', strtotime($d)) : '';
                $ficheOk = !empty($enfant['fiche_sanitaire_token']); $carnetOk = !empty($enfant['carnet_sante_token']);
                $ficheTxt = !$ficheOk ? 'Non fournie' : (($enfant['fiche_origine'] ?? '') === 'generee' ? 'Générée' : 'Importée') . $dt($enfant['fiche_at'] ?? null);
                $carnetTxt = !$carnetOk ? 'Non fourni' : 'Importé' . $dt($enfant['carnet_at'] ?? null);
                $statut = function (bool $ok, string $txt, string $type, ?string $tok, string $act, string $conf) use ($fileUrl) {
                    echo '<div class="flex flex-wrap items-center gap-2"><span class="ds-badge" style="' . ($ok ? '' : 'background:#f8f5ea;color:#5b4a1c') . '">' . htmlspecialchars($txt) . '</span>';
                    if ($ok) echo '<a href="' . htmlspecialchars($fileUrl($type, $tok)) . '" target="_blank" rel="noopener" class="ds-btn ds-btn-light ds-btn-sm">Voir</a><button type="submit" name="act" value="' . $act . '" class="ds-btn ds-btn-light ds-btn-sm" onclick="return confirm(\'' . $conf . '\')">Supprimer</button>';
                    echo '</div>';
                };
                ?>

                <div class="mt-6 border border-[#e5e7eb] rounded-2xl p-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 class="font-extrabold text-[#26343b]">Fiche sanitaire de liaison</h3>
                        <?php $statut($ficheOk, $ficheTxt, 'sanitaire', $enfant['fiche_sanitaire_token'] ?? null, 'del_fiche', 'Supprimer la fiche sanitaire ?'); ?>
                    </div>
                    <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label class="ds-opt"><input type="radio" name="fm" value="import"><span class="ds-tick"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12l5 5 9-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span><span class="ds-opt-title block">Importer ma fiche</span><span class="ds-opt-sub block">PDF ou photo</span></span></label>
                        <label class="ds-opt"><input type="radio" name="fm" value="gen" checked><span class="ds-tick"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12l5 5 9-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span><span class="ds-opt-title block">La générer</span><span class="ds-opt-sub block">À partir des informations saisies</span></span></label>
                    </div>
                    <div data-fm="import" class="mt-4 flex flex-wrap items-center gap-3" hidden>
                        <input type="file" name="fiche[]" multiple accept="application/pdf,image/*,.heic,.heif" class="ds-file flex-1 min-w-[220px]" style="width:auto">
                        <button type="submit" name="act" value="doc_fiche" class="ds-btn"><?= $ficheOk ? 'Remplacer' : 'Importer' ?></button>
                        <details class="ds-help"><summary>?</summary><div>Un PDF, ou une ou plusieurs photos / scans (JPG, PNG, WebP, HEIC), convertis en PDF. 8 Mo maximum par fichier.</div></details>
                    </div>
                    <div data-fm="gen" class="mt-4">
                        <?php $P = 'g'; ?>
                        <p class="text-[13.5px] text-[#6b7780]">Toutes les rubriques de la fiche officielle (CERFA 10008*02). Vos réponses mettent aussi à jour la fiche de l'enfant.</p>
                        <h4 class="ds-eyebrow mt-6 mb-4">1. Le mineur</h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5"><?php $in('nom', 'Nom', 'text', 'required'); $in('prenom', 'Prénom', 'text', 'required'); $in('date_naissance', 'Date de naissance', 'date', 'max="' . date('Y-m-d') . '"'); $sexe(); ?></div>
                        <h4 class="ds-eyebrow mt-7">2. Santé et vaccinations</h4>
                        <?php $santeForm(false); ?>
                        <h4 class="ds-eyebrow mt-7 mb-4">3. Responsables du mineur</h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <?php foreach ([1, 2] as $n) { $in("resp{$n}_nom", "Responsable $n : nom"); $in("resp{$n}_prenom", 'Prénom'); $in("resp{$n}_tel", 'Téléphone portable', 'tel'); $in("resp{$n}_tel_dom", 'Téléphone domicile', 'tel'); $in("resp{$n}_tel_trav", 'Téléphone travail', 'tel');
                                if ($n === 1) { $in('adresse', 'Adresse'); $in('code_postal', 'Code postal'); $in('ville', 'Ville'); echo '<div class="sm:col-span-2 border-t border-[#e5e7eb]"></div>'; } else $in('resp2_adresse', 'Adresse (si différente)'); } ?>
                        </div>
                        <?php $P = ''; ?>
                        <h4 class="ds-eyebrow mt-7 mb-4">4. Signature</h4>
                        <div class="flex flex-wrap items-center gap-3">
                        <div class="w-full">
                            <p class="ds-label">Signature du responsable légal</p>
                            <canvas id="sig" width="600" height="200" class="w-full max-w-[340px] h-[113px] rounded-xl border-[1.5px] border-dashed border-[#c9d1d8] bg-white touch-none cursor-crosshair"></canvas>
                            <input type="hidden" name="signature_data" id="sig-data">
                            <button type="button" id="sig-clear" class="block mt-1 text-[12.5px] font-bold text-[#6b7780] underline">Effacer</button>
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer flex-1 min-w-[220px]"><input type="checkbox" name="certifie" value="1" class="w-4 h-4"><span class="text-[13.5px] text-[#26343b]">Je certifie l'exactitude des informations</span></label>
                        <button type="submit" name="act" value="generer_fiche" id="gen-go" class="ds-btn"><?= $ficheOk ? 'Régénérer' : 'Générer la fiche' ?></button>
                        <details class="ds-help"><summary>?</summary><div>La fiche officielle (CERFA 10008*02) est remplie avec les réponses ci-dessus, puis signée par vous. La signature est conservée chiffrée.</div></details>
                        </div>
                    </div>
                </div>

                <div class="mt-5 border border-[#e5e7eb] rounded-2xl p-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 class="font-extrabold text-[#26343b]">Carnet de santé <span class="font-semibold text-[#6b7780]">(pages vaccins)</span></h3>
                        <?php $statut($carnetOk, $carnetTxt, 'sante', $enfant['carnet_sante_token'] ?? null, 'del_carnet', 'Supprimer le carnet de santé ?'); ?>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <input type="file" name="carnet[]" multiple accept="application/pdf,image/*,.heic,.heif" class="ds-file flex-1 min-w-[220px]" style="width:auto">
                        <button type="submit" name="act" value="doc_carnet" class="ds-btn"><?= $carnetOk ? 'Remplacer' : 'Importer' ?></button>
                        <details class="ds-help"><summary>?</summary><div>PDF, ou photos / scans des pages vaccins (JPG, PNG, WebP, HEIC), convertis en un seul PDF. 8 Mo maximum par fichier.</div></details>
                    </div>
                </div>
                <div class="mt-7"><?php $consentBloc(); ?></div>
            </section>

            <?php if (sec_turnstile_enabled()): ?><div class="ds-card p-4"><?= sec_turnstile_widget() ?></div><?php endif; ?>

            <div class="flex flex-wrap justify-end gap-3">
                <a href="children" class="ds-btn ds-btn-light">Fermer</a>
                <button type="submit" name="act" value="save:all" class="ds-btn">Tout enregistrer</button>
            </div>
        </div>
    </form>
</main>

<script>
(function () {
    var panels = [].slice.call(document.querySelectorAll('[data-panel]'));
    var links = [].slice.call(document.querySelectorAll('[data-go]'));
    var cur = document.getElementById('current');
    var order = panels.map(function (p) { return p.dataset.panel; });
    function show(name) {
        if (order.indexOf(name) < 0) name = order[0];
        panels.forEach(function (p) { p.classList.toggle('on', p.dataset.panel === name); });
        links.forEach(function (a) { if (a.closest('.fc-nav')) a.classList.toggle('on', a.dataset.go === name); });
        cur.value = name;
    }
    var ph = document.getElementById('photo');   // aperçu rond immédiat
    if (ph) ph.addEventListener('change', function () {
        var f = ph.files[0], img = document.getElementById('photo-prev'), ini = document.getElementById('photo-init');
        document.getElementById('photo-go').disabled = !f;
        if (!f || !/^image\//.test(f.type)) return;
        img.src = URL.createObjectURL(f); img.classList.remove('hidden'); if (ini) ini.classList.add('hidden');
    });
    var fm = [].slice.call(document.querySelectorAll('[name=fm]'));   // choix importer / générer
    function fmShow() { var r = fm.filter(function (x) { return x.checked; })[0]; [].forEach.call(document.querySelectorAll('[data-fm]'), function (d) { d.hidden = !r || d.dataset.fm !== r.value; }); }
    fm.forEach(function (r) { r.addEventListener('change', fmShow); });
    var cv = document.getElementById('sig');   // pad de signature (souris, doigt, stylet)
    if (cv) {
        var cx = cv.getContext('2d'), dr = false, drawn = false;
        function clr() { cx.fillStyle = '#fff'; cx.fillRect(0, 0, cv.width, cv.height); cx.strokeStyle = '#14205a'; cx.lineWidth = 3.5; cx.lineCap = cx.lineJoin = 'round'; drawn = false; }
        function pt(e) { var r = cv.getBoundingClientRect(); return [(e.clientX - r.left) * cv.width / r.width, (e.clientY - r.top) * cv.height / r.height]; }
        cv.addEventListener('pointerdown', function (e) { dr = true; drawn = true; cv.setPointerCapture(e.pointerId); var p = pt(e); cx.beginPath(); cx.moveTo(p[0], p[1]); cx.lineTo(p[0] + .1, p[1] + .1); cx.stroke(); e.preventDefault(); });
        cv.addEventListener('pointermove', function (e) { if (!dr) return; var p = pt(e); cx.lineTo(p[0], p[1]); cx.stroke(); });
        ['pointerup', 'pointercancel'].forEach(function (n) { cv.addEventListener(n, function () { dr = false; }); });
        document.getElementById('sig-clear').addEventListener('click', clr);
        document.getElementById('gen-go').addEventListener('click', function () { document.getElementById('sig-data').value = drawn ? cv.toDataURL('image/jpeg', 0.9) : ''; });
        clr();
    }
    document.documentElement.classList.add('js');
    links.forEach(function (a) { a.addEventListener('click', function (ev) { ev.preventDefault(); history.replaceState(null, '', '#' + a.dataset.go); show(a.dataset.go); window.scrollTo({ top: 0 }); }); });
    window.addEventListener('hashchange', function () { show(location.hash.slice(1)); });
    show(location.hash.slice(1) || <?= json_encode($sectionOuverte ?: '') ?>);
})();
</script>

<?php require_once 'partials/footer.php'; ?>
