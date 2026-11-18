<?php
// Fichier : inscription.php
// Objectif : Inscription complète avec Vérification exhaustive (Étape 2).

require_once 'api/config.php';
require_once 'partials/header.php';

// Sécurité
if (!isset($_SESSION['user'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

$camp_token = $_GET['camp_t'] ?? '';
if (empty($camp_token)) {
    echo "<script>window.location.href = 'reservations.php';</script>";
    exit;
}

try {
    // 1. Infos Camp
    require_once 'api/attente.php';
    require_once 'api/tarifs.php';
    require_once 'api/options.php';
    options_ensure($pdo);
    attente_ensure($pdo);
    require_once 'api/enfants.php';
    require_once 'api/sante.php';
    enfants_ensure($pdo);
    sante_ensure($pdo);
    tarifs_ensure($pdo);
    $stmtCamp = $pdo->prepare("SELECT * FROM camps WHERE token = ?");
    $stmtCamp->execute([$camp_token]);
    $camp = $stmtCamp->fetch(PDO::FETCH_ASSOC);

    if (!$camp) die('<div class="py-20 text-center text-red-600 font-bold">Séjour introuvable.</div>');

    // 2. Infos Enfants (Tous les champs nécessaires)
    $stmtEnfants = $pdo->prepare("SELECT * FROM enfants WHERE parent_id = ? ORDER BY prenom ASC");
    $stmtEnfants->execute([$_SESSION['user']['id']]);
    $enfants = $stmtEnfants->fetchAll(PDO::FETCH_ASSOC);
    // Règles du directeur : séjour réservé à un sexe, fiche enfant à compléter
    $bloques = []; // [id enfant => ['sexe' => bool, 'manque' => [libellés]]]
    foreach ($enfants as $e) {
        $b = ['sexe' => !options_sexe_admis($camp, $e['sexe'] ?? null), 'manque' => options_enfant_manquants($camp, $e), 'age' => options_age_probleme($camp, $e)];
        if ($b['sexe'] || $b['manque'] || $b['age']) $bloques[(int)$e['id']] = $b;
    }

    // Tranche (collège, lycée…) déclarée pour chaque enfant : dernière déclaration, sinon déduite de l'âge
    require_once 'api/regles.php';
    require_once 'api/security.php';
    regles_ensure($pdo);
    $reglesT = regles_tarifs_du_camp($pdo, (int)$camp['id']);
    $besoinTranche = regles_besoin_tranche($camp, $reglesT);
    $trancheDe = [];
    if ($enfants) {
        $st = $pdo->prepare("SELECT enfant_id, tranche FROM inscriptions WHERE tranche IS NOT NULL AND enfant_id IN (" . implode(',', array_fill(0, count($enfants), '?')) . ") ORDER BY id");
        $st->execute(array_map(fn($e) => (int)$e['id'], $enfants));
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $trancheDe[(int)$k] = $v;
        foreach ($enfants as $e) $trancheDe[(int)$e['id']] ??= tranche_par_age(age_au($e['date_naissance'] ?? null, $camp['date_debut']));
    }

    // 3. Enfants déjà inscrits
    $stmtDeja = $pdo->prepare("SELECT enfant_id FROM inscriptions WHERE camp_id = ? AND " . attente_clause($camp));
    $stmtDeja->execute([$camp['id']]);
    $dejaInscrits = $stmtDeja->fetchAll(PDO::FETCH_COLUMN);

    // 4. Tarifs
    $stmtTarifs = $pdo->prepare("
        SELECT t.* FROM tarifs t
        JOIN camps_tarifs ct ON t.id = ct.tarif_id
        WHERE ct.camp_id = ?
        ORDER BY t.prix ASC
    ");
    $stmtTarifs->execute([$camp['id']]);
    $tarifs = $stmtTarifs->fetchAll(PDO::FETCH_ASSOC);

    if (empty($tarifs)) {
        $tarifs[] = ['id' => 'default', 'nom' => 'Tarif Standard', 'prix' => $camp['prix'], 'montant_libre' => 0];
    }

    // 5. Questions personnalisées du directeur
    require_once 'api/champs.php';
    $champs = champs_du_camp($pdo, (int)$camp['id']);

    $occupees = places_occupees($pdo, $camp);
    foreach ($tarifs as &$t) {
        $t['plancher'] = tarif_plancher($t);
        $t['regles'] = (object)($reglesT[(int)$t['id']] ?? []);
        $t['restant'] = is_numeric($t['id']) ? places_restantes($camp, $reglesT, $occupees, (int)$t['id']) : null;
    }
    unset($t);
    // Frères et sœurs déjà inscrits à ce séjour (la remise fratrie s'applique aussi si on réserve à un autre moment)
    $stmtFr = $pdo->prepare("SELECT COUNT(*) FROM inscriptions WHERE camp_id = ? AND enfant_id IN (SELECT id FROM enfants WHERE parent_id = ?) AND statut_paiement = 'PAYE' AND COALESCE(statut, '') NOT IN ('Annulé', 'annule', 'Annule')");
    $stmtFr->execute([$camp['id'], $_SESSION['user']['id']]);
    $dejaInscritsNb = (int)$stmtFr->fetchColumn();
    // JSON injecté dans <script> : champs utiles seulement (id, prénom, nom : jamais de colonnes santé/binaires, qui feraient échouer json_encode et casser toute la page), balises échappées
    $jf = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP;
    $enfantsJson = json_encode(array_map(fn($e) => ['id' => $e['id'], 'prenom' => $e['prenom'] ?? '', 'nom' => $e['nom'] ?? '', 'sexe' => $e['sexe'] ?? '', 'age' => age_au($e['date_naissance'] ?? null, $camp['date_debut']), 'tranche' => $trancheDe[(int)$e['id']] ?? ''], $enfants), $jf) ?: '[]';
    $tarifsJson = json_encode($tarifs, $jf) ?: '[]';
    $retourInscr = urlencode('inscription?camp_t=' . $camp_token);
    require_once 'api/annulation_lib.php';
    $moyensOk = fin_moyens_du_camp($camp);
    $annulTexte = fin_annul_texte((int)($camp['annul_jours_total'] ?? FIN_ANNUL_DEFAUT[0]), (int)($camp['annul_jours_moitie'] ?? FIN_ANNUL_DEFAUT[1]));

} catch (Exception $e) {
    die("Erreur : " . $e->getMessage());
}

$remise_pourcent = floatval($camp['remise_fratrie'] ?? 0);

// Étape 2 : fiche de chaque enfant rendue côté serveur (lecture directe des colonnes et du JSON de la fiche, valeurs échappées)
$hv = fn($v, $vide = 'Non renseigné') => trim((string)$v) === '' ? '<span class="verif-vide">' . $vide . '</span>' : htmlspecialchars(trim((string)$v));
$ouiNon = fn($v) => !empty($v) ? '<span class="verif-oui">Oui</span>' : '<span class="verif-non">Non</span>';
$vaccTxt = function (array $e): string {
    $j = fiche_json($e); $out = [];
    foreach (FICHE_VACCINS as $k => $l) {
        $st = (string)($j["v_$k"] ?? ''); $d = (string)($j["d_$k"] ?? '');
        if ($st === 'oui') $out[] = $l . ($d !== '' ? ' (' . fiche_date_fr($d) . ')' : '');
        elseif ($st === 'non' && in_array($k, FICHE_VACCINS_OBLIG, true)) $out[] = $l . ' : non';
    }
    if (!$out && !empty($j['dtp'])) $out[] = 'DTP ' . $j['dtp'];
    return implode(', ', $out);
};
?>

<main class="flex-1 py-10">
    <div class="container mx-auto max-w-5xl px-4">

        <div class="text-center mb-8">
            <h1 class="text-[32px] font-extrabold text-[#26343b] mb-1">Inscription</h1>
            <p class="text-gray-600">Séjour : <span class="font-bold text-[#3d6a8e]"><?= htmlspecialchars($camp['nom']) ?></span></p>
        </div>

        <ol class="ins-steps" aria-label="Étapes de l'inscription">
            <?php foreach (['Enfants', 'Vérification', 'Tarifs', 'Paiement'] as $i0 => $lab): $i = $i0 + 1; ?>
                <li id="step-icon-<?= $i ?>" class="ins-step" data-state="<?= $i === 1 ? 'on' : 'todo' ?>"<?= $i === 1 ? ' aria-current="step"' : '' ?>><span class="ins-num"><?= $i ?></span><span><?= $lab ?></span></li>
            <?php endforeach; ?>
        </ol>

        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="mb-6 mx-auto max-w-2xl rounded-xl border border-[#ebe3c8] bg-[#f8f5ea] px-5 py-4 text-sm font-semibold text-[#5b4a1c]" role="alert"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <form id="mainForm" action="api/create_stripe_session_multi.php" method="POST" class="bg-white rounded-3xl border border-[#e5e7eb] shadow-[0_8px_30px_rgba(38,52,59,.07)] overflow-hidden min-h-[400px]">
            <input type="hidden" name="camp_t" value="<?= htmlspecialchars($camp_token) ?>">
            <?= csrf_field() ?>

            <div id="step-1" class="p-6 md:p-8 fade-in">
                <h2 class="text-xl font-bold text-gray-800 mb-6">1. Qui participe au séjour ?</h2>

                <?php if (empty($enfants)): ?>
                    <div class="text-center py-12 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
                        <p class="text-gray-500 mb-4">Aucun enfant enregistré.</p>
                        <a href="add_child?retour=<?= $retourInscr ?>" class="px-5 py-2 bg-[#2f3d45] text-white rounded-lg">Ajouter un enfant</a>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php foreach ($enfants as $enfant): 
                            $isInscrit = in_array($enfant['id'], $dejaInscrits);
                            $blq = $bloques[(int)$enfant['id']] ?? null;
                            if ($blq && ($blq['sexe'] || $blq['age'])) $isInscrit = true;
                        ?>
                            <label class="relative flex items-start p-4 rounded-xl border-2 transition cursor-pointer group <?= $isInscrit ? 'border-gray-100 bg-gray-50 opacity-60 cursor-not-allowed' : 'border-gray-100 hover:border-blue-200 hover:bg-[#f1f5f9]' ?>">
                                <div class="flex items-center h-5 mt-1">
                                    <input type="checkbox" name="child_ids[]" value="<?= $enfant['id'] ?>" 
                                           class="w-5 h-5 text-[#3d6a8e] border-gray-300 rounded focus:ring-[#2f3d45] child-check"
                                           <?= ($isInscrit || $blq) ? 'disabled' : '' ?>>
                                </div>
                                <div class="ml-3">
                                    <span class="block font-bold text-gray-800"><?= htmlspecialchars($enfant['prenom'] . ' ' . $enfant['nom']) ?></span>
                                    <span class="block text-sm text-gray-500">Né(e) le <?= date('d/m/Y', strtotime($enfant['date_naissance'])) ?></span>
                                    <?php if ($blq && $blq['sexe']): ?>
                                        <span class="inline-block mt-1 text-xs font-bold text-[#5b4a1c] bg-[#f8f5ea] px-2 py-0.5 rounded">Séjour réservé aux <?= $camp['reserve_sexe'] === SEXE_FILLE ? 'filles' : 'garçons' ?></span>
                                    <?php elseif ($blq && $blq['age']): ?>
                                        <span class="block mt-1 text-xs font-semibold text-[#5b4a1c]"><?= htmlspecialchars($blq['age']) ?></span>
                                    <?php elseif($isInscrit): ?>
                                        <span class="inline-block mt-1 text-xs font-bold text-[#3b4a54] bg-[#eef2f4] px-2 py-0.5 rounded">Déjà inscrit<?= ($enfant['sexe'] ?? '') === 'Femme' ? 'e' : '' ?></span>
                                    <?php elseif ($blq && $blq['manque']): ?>
                                        <span class="block mt-1 text-xs font-semibold text-[#5b4a1c]">Fiche à compléter : <?= htmlspecialchars(implode(', ', $blq['manque'])) ?>. <a href="modif_child?id=<?= urlencode($enfant['public_id']) ?>&retour=<?= $retourInscr ?>#<?= fiche_rubrique_critere($blq['manque'][0]) ?>" class="underline text-[#3d6a8e]" onclick="event.stopPropagation()">Compléter la fiche</a></span>
                                    <?php endif; ?>
                                    <?php if (!$blq || !$blq['manque']): $sg = options_enfant_souhaites($camp, $enfant); if ($sg): ?>
                                        <span class="block mt-1 text-xs text-[#6b7780]">Souhaité (facultatif) : <?= htmlspecialchars(implode(', ', $sg)) ?>. <a href="modif_child?id=<?= urlencode($enfant['public_id']) ?>&retour=<?= $retourInscr ?>#<?= fiche_rubrique_critere($sg[0]) ?>" class="underline text-[#3d6a8e]" onclick="event.stopPropagation()">Compléter</a></span>
                                    <?php endif; endif; ?>
                                    <?php if ($besoinTranche): ?>
                                        <select name="tranche[<?= (int)$enfant['id'] ?>]" class="tranche-sel mt-2 border border-gray-300 rounded-lg p-1.5 text-sm bg-white" data-child="<?= (int)$enfant['id'] ?>" aria-label="Classe ou situation de <?= htmlspecialchars($enfant['prenom']) ?> pendant le séjour">
                                            <option value="">Classe / situation pendant le séjour…</option>
                                            <?php foreach (TRANCHES as $k => [$t]): ?><option value="<?= $k ?>" <?= ($trancheDe[(int)$enfant['id']] ?? '') === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <a href="add_child?retour=<?= $retourInscr ?>" class="inline-block mt-4 text-sm font-semibold text-[#3d6a8e] hover:underline">+ Ajouter un enfant</a>
                <?php endif; ?>

                <div class="mt-8 flex justify-end">
                    <button type="button" onclick="goToStep(2)" class="px-6 py-3 bg-[#2f3d45] text-white font-bold rounded-lg hover:bg-[#1f2a30] transition">
                        Suivant →
                    </button>
                </div>
            </div>

            <div id="step-2" class="hidden p-6 md:p-8 fade-in">
                <h2 class="text-xl font-bold text-[#26343b]">2. Vérification des informations</h2>
                <p class="text-sm text-[#6b7780] mt-1 mb-5">Ces informations seront transmises à l'organisateur. Vérifiez-les avant de continuer ; « Modifier » vous ramène ici ensuite.</p>

                <div id="children-details-container" class="space-y-5">
                <?php foreach ($enfants as $enfant):
                    $eid = (int)$enfant['id']; $ej = fiche_json($enfant);
                    $lien = 'modif_child?id=' . urlencode((string)$enfant['public_id']) . '&retour=' . $retourInscr;
                    $ageE = !empty($enfant['date_naissance']) ? (new DateTime($enfant['date_naissance']))->diff(new DateTime())->y : null;
                    $doc = fn($tok, $type, $rub) => !empty($enfant[$tok]) ? '<a href="api/secure_file.php?type=' . $type . '&file=' . urlencode($enfant[$tok]) . '" target="_blank" rel="noopener" class="verif-lien">Voir</a>' : '<a href="' . htmlspecialchars($lien) . '#' . $rub . '" class="verif-manque">Manquant : ajouter</a>';
                    $resp = function (int $n) use ($enfant, $hv) {
                        $nom = trim(($enfant["resp{$n}_civilite"] ?? '') . ' ' . ($enfant["resp{$n}_prenom"] ?? '') . ' ' . ($enfant["resp{$n}_nom"] ?? ''));
                        $st = trim((string)($enfant["resp{$n}_statut"] ?? ''));
                        return $hv($nom . ($st !== '' ? " ($st)" : '')) . '<br><span class="text-[#6b7780]">' . htmlspecialchars(implode(' · ', array_filter([trim((string)($enfant["resp{$n}_email"] ?? '')), trim((string)($enfant["resp{$n}_tel"] ?? ''))]))) . '</span>';
                    };
                ?>
                    <article class="verif-card hidden border border-[#e5e7eb] rounded-2xl overflow-hidden" data-child="<?= $eid ?>">
                        <header class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 bg-[#f8fafb] border-b border-[#e5e7eb]">
                            <div class="flex items-center gap-3">
                                <span class="w-11 h-11 rounded-full bg-[#2f3d45] text-white flex items-center justify-center font-bold text-lg"><?= htmlspecialchars(mb_strtoupper(mb_substr((string)$enfant['prenom'], 0, 1))) ?></span>
                                <div><h3 class="font-extrabold text-[#26343b] text-[17px]"><?= htmlspecialchars($enfant['prenom'] . ' ' . $enfant['nom']) ?></h3>
                                <p class="text-[13px] text-[#6b7780]"><?= !empty($enfant['date_naissance']) ? 'Né(e) le ' . date('d/m/Y', strtotime($enfant['date_naissance'])) . ' · ' . $ageE . ' ans' : 'Date de naissance non renseignée' ?></p></div>
                            </div>
                            <a href="<?= htmlspecialchars($lien) ?>" class="ds-btn ds-btn-light ds-btn-sm">Modifier</a>
                        </header>
                        <div class="p-5 grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
                            <section><h4 class="ds-eyebrow mb-2">Identité et contact</h4><dl class="verif-dl">
                                <div><dt>Sexe</dt><dd><?= $hv(['Homme' => 'Garçon', 'Femme' => 'Fille', 'Autre' => 'Autre'][$enfant['sexe'] ?? ''] ?? '', 'Non renseigné') ?></dd></div>
                                <div><dt>Civilité</dt><dd><?= $hv($enfant['civilite'] ?? '', 'Non renseignée') ?></dd></div>
                                <div><dt>Adresse</dt><dd><?= $hv(trim(($enfant['adresse'] ?? '') . ' ' . ($enfant['code_postal'] ?? '') . ' ' . ($enfant['ville'] ?? '') . ' ' . ($enfant['pays'] ?? ''))) ?></dd></div>
                                <div><dt>Contact de l'enfant</dt><dd><?= $hv(implode(' · ', array_filter([trim((string)($enfant['email_enfant'] ?? '')), trim((string)($enfant['tel_mobile_enfant'] ?? '')), trim((string)($enfant['tel_fixe_enfant'] ?? ''))])), '—') ?></dd></div>
                            </dl></section>
                            <section><h4 class="ds-eyebrow mb-2">Responsables légaux</h4><dl class="verif-dl">
                                <div><dt>Responsable 1</dt><dd><?= $resp(1) ?></dd></div>
                                <div><dt>Responsable 2</dt><dd><?= trim(($enfant['resp2_nom'] ?? '') . ($enfant['resp2_prenom'] ?? '')) !== '' ? $resp(2) : '<span class="verif-vide">—</span>' ?></dd></div>
                                <div><dt>Urgence</dt><dd><?= $hv(trim(($enfant['urgence_nom'] ?? '') . ' ' . ($enfant['urgence_tel'] ?? ''))) ?></dd></div>
                            </dl></section>
                            <section><h4 class="ds-eyebrow mb-2">Santé</h4><dl class="verif-dl">
                                <div><dt>Allergies</dt><dd><?= $hv($enfant['allergies'] ?? '') ?></dd></div>
                                <div><dt>Problème de santé</dt><dd><?= $hv($enfant['infos_sante'] ?? '') ?></dd></div>
                                <div><dt>Traitement</dt><dd><?= $hv($enfant['traitements'] ?? '') ?></dd></div>
                                <div><dt>Régime</dt><dd><?= $hv($enfant['regime_alimentaire'] ?? '') ?></dd></div>
                                <div><dt>Taille / poids</dt><dd><?= $hv(trim((!empty($enfant['taille']) ? $enfant['taille'] . ' cm' : '') . (!empty($enfant['poids']) ? ' / ' . $enfant['poids'] . ' kg' : ''), ' /')) ?></dd></div>
                                <div><dt>Médecin</dt><dd><?= $hv(trim(($enfant['medecin_nom'] ?? '') . ' ' . ($enfant['medecin_tel'] ?? ''))) ?></dd></div>
                                <div><dt>Vaccins</dt><dd><?= $hv($vaccTxt($enfant)) ?></dd></div>
                            </dl></section>
                            <section><h4 class="ds-eyebrow mb-2">Documents et autorisations</h4><dl class="verif-dl">
                                <div><dt>Fiche sanitaire</dt><dd><?= $doc('fiche_sanitaire_token', 'sanitaire', 'documents') ?></dd></div>
                                <div><dt>Carnet de santé</dt><dd><?= $doc('carnet_sante_token', 'sante', 'documents') ?></dd></div>
                                <div><dt>Accord parental</dt><dd><?= $ouiNon($enfant['accord_parental'] ?? 0) ?></dd></div>
                                <div><dt>Droit à l'image</dt><dd><?= $ouiNon($enfant['droit_image'] ?? 0) ?></dd></div>
                                <div><dt>Contact direct</dt><dd><?= $ouiNon($enfant['autorisation_contact'] ?? 0) ?></dd></div>
                            </dl></section>
                            <?php if (trim((string)($enfant['commentaires'] ?? '')) !== ''): ?><section class="md:col-span-2"><h4 class="ds-eyebrow mb-2">Commentaires</h4><p class="text-sm text-[#26343b]"><?= nl2br(htmlspecialchars($enfant['commentaires'])) ?></p></section><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                </div>

                <div class="mt-8 pt-6 border-t flex flex-wrap justify-between items-center gap-3">
                    <button type="button" onclick="goToStep(1)" class="text-gray-500 hover:text-gray-800">← Retour</button>
                    <button type="button" id="btn-validate-step2" onclick="goToStep(3)" disabled class="ds-btn" aria-describedby="timer-note">Valider et continuer</button>
                </div>
                <p id="timer-note" class="text-right text-xs text-[#6b7780] mt-2">Validation possible dans <span id="timer-display">5</span> s.</p>
            </div>

            <div id="step-3" class="hidden p-6 md:p-8 fade-in">
                <h2 class="text-xl font-bold text-gray-800 mb-2">3. Choix des Tarifs</h2>
                <p class="text-gray-500 text-sm mb-6">Sélectionnez un tarif pour chaque participant.</p>

                <div id="tariffs-container" class="space-y-8"></div>

                <?php if ($champs): ?>
                <div id="custom-fields" class="mt-8 space-y-6">
                    <?php foreach ($enfants as $enfant): ?>
                    <fieldset class="bg-white border rounded-xl p-5 shadow-sm hidden" data-child="<?= (int)$enfant['id'] ?>" disabled>
                        <legend class="sr-only">Questions de l'organisateur pour <?= htmlspecialchars($enfant['prenom']) ?></legend>
                        <h3 class="font-bold text-lg mb-1">Questions de l'organisateur pour <?= htmlspecialchars($enfant['prenom']) ?></h3>
                        <p class="text-xs text-gray-500 mb-4">Les champs marqués d'une étoile sont obligatoires.</p>
                        <div class="space-y-4">
                        <?php foreach ($champs as $c):
                            $name = 'reponses[' . (int)$enfant['id'] . '][' . $c['id'] . ']';
                            $fid = 'rep-' . (int)$enfant['id'] . '-' . $c['id'];
                            $star = $c['obligatoire'] ? ' <span class="text-red-600" aria-hidden="true">*</span>' : '';
                            $cls = 'w-full border border-gray-300 rounded-lg p-2.5 text-sm focus:border-[#2f3d45] focus:outline-none';
                            $req = $c['obligatoire'] ? ' data-required="1"' : '';
                        ?>
                            <div>
                            <?php if ($c['type'] === 'case'): ?>
                                <label class="flex items-start gap-3 cursor-pointer text-sm"><input type="checkbox" name="<?= $name ?>" value="1" class="mt-0.5 w-4 h-4"<?= $req ?>><span class="font-medium"><?= htmlspecialchars($c['libelle']) . $star ?></span></label>
                            <?php else: ?>
                                <label for="<?= $fid ?>" class="block text-sm font-semibold mb-1"><?= htmlspecialchars($c['libelle']) . $star ?></label>
                                <?php if ($c['type'] === 'paragraphe'): ?>
                                    <textarea id="<?= $fid ?>" name="<?= $name ?>" rows="3" maxlength="2000" class="<?= $cls ?>"<?= $req ?>></textarea>
                                <?php elseif ($c['type'] === 'liste'): ?>
                                    <select id="<?= $fid ?>" name="<?= $name ?>" class="<?= $cls ?> bg-white"<?= $req ?>><option value="">— Choisir —</option>
                                        <?php foreach ($c['choix'] as $o): ?><option><?= htmlspecialchars($o) ?></option><?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input id="<?= $fid ?>" name="<?= $name ?>" type="<?= $c['type'] === 'nombre' ? 'number' : ($c['type'] === 'date' ? 'date' : 'text') ?>"<?= $c['type'] === 'nombre' ? ' step="any"' : '' ?> maxlength="255" class="<?= $cls ?>"<?= $req ?>>
                                <?php endif; ?>
                            <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="mt-8 pt-6 border-t flex justify-between items-center">
                    <button type="button" onclick="goToStep(2)" class="text-gray-500 hover:text-gray-800">← Retour</button>
                    <button type="button" onclick="goToStep(4)" class="px-6 py-3 bg-[#2f3d45] text-white font-bold rounded-lg hover:bg-[#1f2a30] transition">
                        Voir le récapitulatif →
                    </button>
                </div>
            </div>

            <div id="step-4" class="hidden p-6 md:p-8 fade-in">
                <h2 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
                    <svg class="w-6 h-6 mr-2 text-[#3b4a54]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    4. Récapitulatif Financier
                </h2>

                <div class="bg-gray-50 rounded-xl p-6 border border-gray-200">
                    <ul id="billing-lines" class="space-y-3 text-sm text-gray-700"></ul>
                    <p class="mt-4 text-xs text-gray-600 bg-white border border-gray-200 rounded-lg p-3"><b>Conditions d'annulation.</b> <?= htmlspecialchars($annulTexte) ?></p>
                    <hr class="my-4 border-gray-300">
                    <div class="flex justify-between items-end">
                        <span class="text-gray-600 font-medium">Total à régler</span>
                        <span class="text-3xl font-extrabold text-[#3d6a8e]" id="final-total">0,00 €</span>
                    </div>
                </div>

                <div class="mt-6" id="moyens-bloc">
                    <h3 class="text-sm font-bold text-gray-800 mb-2">Comment souhaitez-vous régler ?</h3>
                    <div class="grid gap-2">
                    <?php foreach ($moyensOk as $k => $code): ?>
                        <label class="flex items-center gap-3 p-3 rounded-xl border border-gray-200 bg-white cursor-pointer">
                            <input type="radio" name="moyen" value="<?= htmlspecialchars($code) ?>" <?= $k === 0 ? 'checked' : '' ?> class="moyen-radio" style="accent-color:#2f3d45">
                            <span class="w-10 flex-none"><?= options_moyen_affiche($camp, $code)[1] ?></span>
                            <span class="text-sm"><b class="text-gray-800"><?= htmlspecialchars(options_moyen_affiche($camp, $code)[0]) ?></b><span class="block text-xs text-gray-500"><?= $code === 'carte' ? 'Paiement en ligne immédiat, place confirmée' : 'Votre place est réservée ; vous réglez directement l\'organisateur' ?></span></span>
                        </label>
                    <?php endforeach; ?>
                    </div>
                    <p id="moyen-info" class="hidden mt-3 text-xs text-gray-600 bg-white border border-gray-200 rounded-lg p-3"><?= htmlspecialchars(trim((string)($camp['instructions_paiement'] ?? ''))) ?: 'Les consignes de règlement vous seront données à l\'étape suivante.' ?></p>
                </div>

                <div class="mt-8">
                    <button type="submit" id="btn-payer" class="w-full py-4 bg-[#2f3d45] hover:bg-[#1f2a30] text-white font-bold text-lg rounded-xl shadow-md transition transform hover:-translate-y-0.5 flex justify-center items-center">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                        <span id="btn-payer-txt">Payer en ligne</span>
                    </button>
                    
<div class="mt-6 flex justify-center">
    <img src="https://moncamp.fr/uploads/pay.png" alt="Moyens de paiement sécurisés acceptés" class="w-48 h-auto object-contain">
</div>
                </div>
                <div class="mt-4 text-center">
                    <button type="button" onclick="goToStep(3)" class="text-sm text-gray-500 hover:underline">Modifier les tarifs</button>
                </div>
            </div>

        </form>
    </div>
</main>

<script>
    // Données PHP
    const allChildren = <?= $enfantsJson ?>;
    const tariffs = <?= $tarifsJson ?>;
    const remisePercent = <?= $remise_pourcent ?>;
    const alreadyRegistered = <?= (int)$dejaInscritsNb ?>;
    const CAMP_CRIT = <?= json_encode(regles_crit_camp($camp) + ['libelle' => tranches_libelles(regles_crit_camp($camp)['tranches'])], $jf) ?>;

    let selectedChildIds = [];
    let selections = {}; 
    let timerInterval = null;

    // Envoie au serveur le tarif (et le montant libre éventuel) choisi pour chaque enfant.
    // Le serveur recalcule TOUS les prix à partir de la base : ces champs ne contiennent que des identifiants.
    function syncHiddenInputs() {
        const form = document.getElementById('mainForm');
        form.querySelectorAll('.sel-hidden').forEach(e => e.remove());
        selectedChildIds.forEach(childId => {
            const sel = selections[childId] || { tariffId: tariffs[0].id, customPrice: 0 };
            const add = (name, value) => {
                const i = document.createElement('input');
                i.type = 'hidden'; i.name = name; i.value = value; i.className = 'sel-hidden';
                form.appendChild(i);
            };
            add(`tarifs[${childId}]`, sel.tariffId);
            const tt = tariffs.find(x => x.id == sel.tariffId);
            if (tt && tt.montant_libre == 1 && sel.customPrice > 0 && sel.customFor == sel.tariffId) add(`custom_prices[${childId}]`, sel.customPrice);
        });
    }
    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('mainForm').addEventListener('submit', syncHiddenInputs);
        // Libellé du bouton selon le moyen choisi : carte = Stripe, autres = réservation avec règlement à l'organisateur
        const refresh = () => {
            const r = document.querySelector('.moyen-radio:checked');
            const carte = !r || r.value === 'carte';
            document.getElementById('btn-payer-txt').textContent = carte ? 'Payer en ligne' : 'Réserver et régler à l\'organisateur';
            document.getElementById('moyen-info').classList.toggle('hidden', carte);
        };
        document.querySelectorAll('.moyen-radio').forEach(r => r.addEventListener('change', refresh));
        refresh();
    });

    // --- NAVIGATION ---
    function goToStep(step) {
        if (step === 2) {
            const checkboxes = document.querySelectorAll('.child-check:checked');
            if (checkboxes.length === 0) { ColoUI.alert("Sélectionnez au moins un enfant pour continuer."); return; }
            selectedChildIds = Array.from(checkboxes).map(cb => cb.value);
            if (CAMP_CRIT.mode !== 'age') for (const id of selectedChildIds) {
                const s = document.querySelector(`.tranche-sel[data-child="${id}"]`), c = allChildren.find(x => x.id == id);
                if (!s || !CAMP_CRIT.tranches.includes(s.value)) { ColoUI.alert('Ce séjour accueille : ' + CAMP_CRIT.libelle + '. Vérifiez la classe ou la situation de ' + c.prenom + '.'); return; }
            }
            initStep2();
        }
        if (step === 3) { initStep3(); showCustomFields(); }
        if (step === 4) {
            if (!validateTarifs() || !validateFreeAmounts() || !validateCustomFields()) return;
            updateSummary(); syncHiddenInputs();
        }

        [1, 2, 3, 4].forEach(i => {
            document.getElementById(`step-${i}`).classList.add('hidden');
            const icon = document.getElementById(`step-icon-${i}`);
            icon.dataset.state = i === step ? 'on' : (i < step ? 'done' : 'todo');
            icon.querySelector('.ins-num').textContent = i < step ? '✓' : i;
            if (i === step) icon.setAttribute('aria-current', 'step'); else icon.removeAttribute('aria-current');
        });
        document.getElementById(`step-${step}`).classList.remove('hidden');
    }

    // --- ÉTAPE 2 : VÉRIFICATION COMPLÈTE & TIMER ---
    function initStep2() {
        document.querySelectorAll('.verif-card').forEach(c => c.classList.toggle('hidden', !selectedChildIds.includes(c.dataset.child)));

        // Gestion du Timer (Reset complet)
        const btn = document.getElementById('btn-validate-step2');
        const timerDisplay = document.getElementById('timer-display');
        let timeLeft = 5;
        
        btn.disabled = true;
        timerDisplay.textContent = timeLeft;
        document.getElementById('timer-note').classList.remove('hidden');
        if (timerInterval) clearInterval(timerInterval);
        
        timerInterval = setInterval(() => {
            timeLeft--;
            if(timerDisplay) timerDisplay.textContent = timeLeft;
            btn.textContent = `Valider (${timeLeft}s)`;
            if (timeLeft <= 0) {
                clearInterval(timerInterval);
                btn.disabled = false;
                btn.textContent = "Valider et continuer";
                document.getElementById('timer-note').classList.add('hidden');
            }
        }, 1000);
    }

    // --- ÉTAPE 3 : TARIFS PAR ENFANT ---
    const TR_LIB = <?= json_encode(array_map(fn($t) => $t[0], TRANCHES), $jf) ?>;
    const escH = s => String(s ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    const trancheOf = id => { const s = document.querySelector(`.tranche-sel[data-child="${id}"]`); return s ? s.value : ''; };
    // Tarif inaccessible pour cet enfant (âge, tranche, places) : la raison, sinon '' ; le serveur refait tous ces contrôles
    function tarifKo(t, child) {
        const r = t.regles || {}, rs = t.restant || {};
        if (r.age_max != null && child.age != null && (child.age < r.age_min || child.age > r.age_max)) return 'Réservé aux ' + r.age_min + ' à ' + r.age_max + ' ans';
        if ((r.tranches || []).length && !r.tranches.includes(trancheOf(child.id))) return 'Réservé : ' + r.tranches.map(k => TR_LIB[k]).join(', ');
        if (rs.total === 0) return 'Complet';
        if (child.sexe === 'Femme' && rs.f === 0) return 'Complet pour les filles';
        if (child.sexe === 'Homme' && rs.g === 0) return 'Complet pour les garçons';
        return '';
    }
    function validateTarifs() {
        for (const id of selectedChildIds) {
            const c = allChildren.find(x => x.id == id), t = tariffs.find(x => x.id == (selections[id] || {}).tariffId);
            if (!t || tarifKo(t, c)) { ColoUI.alert('Aucun tarif disponible pour ' + c.prenom + ' sur cette session.'); return false; }
        }
        return true;
    }
    function initStep3() {
        const container = document.getElementById('tariffs-container');
        container.innerHTML = '';

        selectedChildIds.forEach((childId) => {
            const child = allChildren.find(c => c.id == childId);
            if (!child) return;

            const cur = selections[childId] && tariffs.find(t => t.id == selections[childId].tariffId);
            if (!cur || tarifKo(cur, child)) selections[childId] = { tariffId: (tariffs.find(t => !tarifKo(t, child)) || tariffs[0]).id, customPrice: 0 };

            let options = '';
            tariffs.forEach(t => {
                const ko = tarifKo(t, child);
                if (ko) { options += `<div class="border rounded-lg p-3 mb-2 border-gray-100 bg-gray-50 opacity-60 cursor-not-allowed"><div class="flex justify-between items-center gap-3"><span class="font-medium">${escH(t.nom)}</span><span class="text-xs font-semibold text-[#5b4a1c]">${escH(ko)}</span></div></div>`; return; }
                const isSelected = selections[childId].tariffId == t.id;
                const borderClass = isSelected ? 'border-[#2f3d45] bg-[#f1f5f9]' : 'border-gray-200 hover:border-[#a9b8c6]';
                
                let inputHtml = '';
                if(t.montant_libre == 1) {
                    const fl = parseFloat(t.plancher) || 0;
                    const mine = selections[childId].customFor == t.id && selections[childId].customPrice > 0;
                    const val = mine ? selections[childId].customPrice : Math.max(parseFloat(t.prix) || 0, fl);
                    inputHtml = `<div class="mt-2 pl-6 flex flex-wrap items-center gap-2 text-sm"><span class="text-gray-600">Montant libre :</span><input type="number" step="0.01" min="${fl}" value="${val}" class="w-24 border rounded p-1 text-right" data-custom="${childId}-${t.id}" oninput="updateSelection('${childId}', '${t.id}', this.value, true)" onclick="event.stopPropagation()"> € <span class="text-xs text-gray-500">(minimum ${fl.toFixed(2).replace('.', ',')} €)</span></div>`;
                }

                options += `<div class="border rounded-lg p-3 mb-2 cursor-pointer ${borderClass}" onclick="updateSelection('${childId}', '${t.id}', null)">
                    <div class="flex justify-between items-center">
                        <div class="flex items-center"><input type="radio" ${isSelected ? 'checked' : ''} class="mr-2"> <span class="font-medium">${escH(t.nom)}</span></div>
                        <span class="font-bold">${t.prix} €</span>
                    </div>
                    ${inputHtml}
                </div>`;
            });

            container.innerHTML += `<div class="bg-white border rounded-xl p-5 shadow-sm"><h3 class="font-bold text-lg mb-3 flex items-center"><div class="w-8 h-8 rounded-full bg-[#e5eaef] text-[#3d6a8e] flex items-center justify-center mr-2">${child.prenom.charAt(0)}</div> Tarif pour ${child.prenom}</h3>${options}</div>`;
        });
    }

    // Questions du directeur : seuls les enfants sélectionnés sont affichés (les autres sont désactivés, donc non envoyés)
    function showCustomFields() {
        document.querySelectorAll('#custom-fields fieldset').forEach(fs => {
            const on = selectedChildIds.includes(fs.dataset.child);
            fs.disabled = !on;
            fs.classList.toggle('hidden', !on);
        });
    }
    // Un montant libre ne peut pas être inférieur au minimum du directeur
    function validateFreeAmounts() {
        for (const id of selectedChildIds) {
            const sel = selections[id]; const t = sel && tariffs.find(x => x.id == sel.tariffId);
            if (!t || t.montant_libre != 1 || !(sel.customPrice > 0) || sel.customFor != t.id) continue;
            const fl = parseFloat(t.plancher) || 0;
            if (sel.customPrice < fl) { ColoUI.alert('Le montant minimum pour « ' + t.nom + ' » est de ' + fl.toFixed(2).replace('.', ',') + ' €.'); return false; }
        }
        return true;
    }
    function validateCustomFields() {
        for (const fs of document.querySelectorAll('#custom-fields fieldset:not([disabled])')) {
            for (const el of fs.querySelectorAll('[data-required]')) {
                const empty = el.type === 'checkbox' ? !el.checked : el.value.trim() === '';
                if (empty) {
                    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
                    el.focus();
                    ColoUI.alert('Merci de répondre à toutes les questions obligatoires (*).');
                    return false;
                }
            }
        }
        return true;
    }

    function updateSelection(childId, tariffId, customPrice, typing) {
        if (!selections[childId]) selections[childId] = {};
        const changed = selections[childId].tariffId != tariffId;
        selections[childId].tariffId = tariffId;
        if (customPrice !== null) { selections[childId].customPrice = parseFloat(customPrice) || 0; selections[childId].customFor = tariffId; }
        else if (tariffs.find(t => t.id == tariffId).montant_libre != 1) selections[childId].customPrice = 0;
        // En cours de saisie on ne reconstruit pas la liste (sinon le champ perd le focus à chaque chiffre)
        if (typing && !changed) return;
        initStep3();
        if (typing) {
            const el = document.querySelector(`input[data-custom="${childId}-${tariffId}"]`);
            if (el) el.focus();
        }
    }

    // --- ÉTAPE 4 : RÉSUMÉ ---
    function updateSummary() {
        const container = document.getElementById('billing-lines');
        container.innerHTML = '';
        let total = 0;

        selectedChildIds.forEach((childId, index) => {
            const child = allChildren.find(c => c.id == childId);
            const sel = selections[childId];
            const t = tariffs.find(x => x.id == sel.tariffId);
            
            const fl = parseFloat(t.plancher) || 0;
            let price = (t.montant_libre == 1 && sel.customPrice > 0 && sel.customFor == t.id) ? Math.max(sel.customPrice, fl) : parseFloat(t.prix);
            let detail = `(${t.nom})`;

            if ((index + alreadyRegistered) > 0 && remisePercent > 0) {
                const discount = price * (remisePercent / 100);
                price -= discount;
                detail += ` <span class="text-[#3b4a54] text-xs bg-[#eef2f4] px-1 rounded">Fratrie -${remisePercent}%</span>`;
            }

            total += price;
            container.innerHTML += `<li class="flex justify-between py-2 border-b border-gray-100"><span>${child.prenom} ${child.nom} <small class="text-gray-500">${detail}</small></span><span class="font-bold">${price.toFixed(2)} €</span></li>`;
        });

        document.getElementById('final-total').textContent = total.toFixed(2) + ' €';
    }

</script>

<style>
    .fade-in { animation: fadeIn 0.3s ease-out; }
    .ins-steps { display: flex; max-width: 620px; margin: 0 auto 32px; padding: 0; list-style: none; }
    .ins-step { flex: 1; position: relative; display: flex; flex-direction: column; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: var(--ds-muted); }
    .ins-step + .ins-step::before { content: ''; position: absolute; top: 17px; left: calc(-50% + 24px); right: calc(50% + 24px); height: 2px; border-radius: 2px; background: var(--ds-line); transition: background .25s; }
    .ins-step:is([data-state=on], [data-state=done])::before { background: var(--ds-btn); }
    .ins-num { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px; background: #fff; border: 1.5px solid var(--ds-line); color: var(--ds-muted); transition: all .25s; }
    .ins-step[data-state=on] { color: var(--ds-ink); }
    .ins-step[data-state=on] .ins-num { background: var(--ds-btn); border-color: var(--ds-btn); color: #fff; box-shadow: 0 0 0 4px rgba(47, 61, 69, .12); }
    .ins-step[data-state=done] .ins-num { background: #eef2f4; border-color: #d3dbe0; color: var(--ds-btn); }
    .verif-dl > div { display: grid; grid-template-columns: 130px 1fr; gap: 10px; padding: 6px 0; border-bottom: 1px solid #f1f3f5; font-size: 14px; }
    .verif-dl dt { color: var(--ds-muted); }
    .verif-dl dd { color: var(--ds-ink); font-weight: 600; min-width: 0; overflow-wrap: anywhere; }
    .verif-vide { color: #9aa4ab; font-weight: 500; font-style: italic; }
    .verif-oui { color: var(--ds-btn); font-weight: 700; } .verif-non { color: #8a6d1f; font-weight: 700; }
    .verif-lien { color: var(--ds-accent); text-decoration: underline; }
    .verif-manque { color: #8a6d1f; font-weight: 700; text-decoration: underline; }
    #btn-validate-step2:disabled { opacity: .45; cursor: not-allowed; transform: none; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
</style>

<?php require_once 'partials/footer.php'; ?>
