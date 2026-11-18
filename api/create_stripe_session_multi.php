<?php
// api/create_stripe_session_multi.php : crée la commande (inscriptions) et la session de paiement Stripe.
//
// RÈGLE D'OR : tout est recalculé ICI à partir de la base de données.
// Le navigateur n'envoie que des identifiants (séjour, enfants, tarifs) et, pour un tarif « montant libre »,
// la somme choisie (jamais inférieure au prix du tarif). Aucun prix reçu n'est cru tel quel.

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/sessions.php';
require_once __DIR__ . '/attente.php';
require_once __DIR__ . '/tarifs.php';
require_once __DIR__ . '/options.php';
require_once __DIR__ . '/finance_lib.php';
require_once __DIR__ . '/regles.php';
require_once __DIR__ . '/security.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$site = rtrim(defined('BASE_URL') ? BASE_URL : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'moncamp.fr'), '/');

if (!isset($_SESSION['user']['id'])) { header('Location: ' . $site . '/login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $site . '/'); exit; }
if (!defined('STRIPE_SECRET_KEY')) { http_response_code(500); exit('Paiement indisponible : configuration manquante.'); }
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

$userId = (int)$_SESSION['user']['id'];
$campToken = (string)($_POST['camp_t'] ?? '');

/** Retour à l'écran d'inscription avec un message clair (rien n'est facturé) */
function backWithError(string $msg, string $campToken, string $site): void {
    $_SESSION['flash_error'] = $msg;
    header('Location: ' . $site . '/inscription.php?camp_t=' . urlencode($campToken));
    exit;
}

if (!csrf_check()) backWithError('Session expirée : rechargez la page puis réessayez.', $campToken, $site);
$childIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['child_ids'] ?? [])))));
if ($campToken === '' || !$childIds) backWithError('Sélectionnez au moins un enfant.', $campToken, $site);
if (count($childIds) > 10) backWithError('Maximum 10 enfants par commande.', $campToken, $site);

$selectedTarifs = (array)($_POST['tarifs'] ?? []);        // [id enfant => id tarif]
$customPrices   = (array)($_POST['custom_prices'] ?? []); // [id enfant => montant libre]

$createdIds = []; // inscriptions créées, supprimées si le paiement ne peut pas démarrer
try {
    // 1. Le séjour : validé, visible, inscriptions en ligne ouvertes
    attente_ensure($pdo);
    tarifs_ensure($pdo);
    options_ensure($pdo);
    $stmt = $pdo->prepare("SELECT * FROM camps WHERE token = ?");
    $stmt->execute([$campToken]);
    $camp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$camp || empty($camp['valide']) || !empty($camp['refuse']) || !empty($camp['supprime'])) {
        backWithError('Ce séjour n\'est plus disponible.', $campToken, $site);
    }
    if (empty($camp['inscription_en_ligne'])) backWithError('Les inscriptions à ce séjour se font auprès de l\'organisateur.', $campToken, $site);
    $limite = $camp['date_limite_inscription'] ?: $camp['date_debut'];
    if ($limite && $limite < date('Y-m-d')) backWithError('Les inscriptions à ce séjour sont closes.', $campToken, $site);

    // 2. Les enfants : ce sont bien ceux du parent connecté
    $in = implode(',', array_fill(0, count($childIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM enfants WHERE parent_id = ? AND id IN ($in)");
    $stmt->execute(array_merge([$userId], $childIds));
    $children = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) $children[(int)$c['id']] = $c;
    $ordered = [];
    foreach ($childIds as $cid) if (isset($children[$cid])) $ordered[] = $children[$cid];
    if (!$ordered) backWithError('Aucun enfant valide sélectionné.', $campToken, $site);

    // 2 bis. Règles du directeur (appliquées ici, jamais seulement dans le navigateur) : séjour réservé à un sexe, âge ou tranche, fiche enfant complète
    regles_ensure($pdo);
    $trancheDe = [];
    foreach ($ordered as $c) $trancheDe[(int)$c['id']] = tranches_valides([(string)($_POST['tranche'][$c['id']] ?? '')])[0] ?? null;
    foreach ($ordered as $c) {
        if (!options_sexe_admis($camp, $c['sexe'] ?? null)) {
            backWithError('Ce séjour est réservé aux ' . ($camp['reserve_sexe'] === SEXE_FILLE ? 'filles' : 'garçons') . ' : ' . $c['prenom'] . ' ne peut pas y être inscrit(e).', $campToken, $site);
        }
        $ageKo = admission_probleme(regles_crit_camp($camp), age_au($c['date_naissance'] ?? null, $camp['date_debut']), $trancheDe[(int)$c['id']], (string)$c['prenom']);
        if ($ageKo) backWithError($ageKo, $campToken, $site);
        $manque = options_enfant_manquants($camp, $c);
        if ($manque) backWithError('La fiche de ' . $c['prenom'] . ' est incomplète. Champs exigés par l’organisateur : ' . implode(', ', $manque) . '.', $campToken, $site);
    }

    // 3. Places : une inscription « active » est payée, confirmée, ou en cours de paiement depuis moins de 30 min
    $occupees = places_occupees($pdo, $camp);
    $alreadyIds = array_map('intval', array_column($occupees, 'enfant_id'));
    foreach ($ordered as $c) {
        if (in_array((int)$c['id'], $alreadyIds, true)) {
            backWithError($c['prenom'] . ' est déjà inscrit(e) à ce séjour (ou son paiement est en cours).', $campToken, $site);
        }
    }

    // 4. Tarifs du séjour (seuls ceux rattachés à CE séjour sont acceptés)
    $stmt = $pdo->prepare("SELECT t.* FROM tarifs t JOIN camps_tarifs ct ON t.id = ct.tarif_id WHERE ct.camp_id = ?");
    $stmt->execute([$camp['id']]);
    $tarifs = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) $tarifs[(int)$t['id']] = $t;

    // 4 bis. Conditions de chaque tarif (âge, tranche) puis places : séjour, tarif, filles / garçons
    $regles = regles_tarifs_du_camp($pdo, (int)$camp['id']);
    $demandes = []; $lots = []; // [id enfant => [[personne, rôle, tranche], …]] pour un tarif « à plusieurs »
    $pris = array_fill_keys(array_map(fn($c) => (int)$c['id'], $ordered), true);
    $age = fn($p) => age_au($p['date_naissance'] ?? null, $camp['date_debut']);
    foreach ($ordered as $c) {
        $cid = (int)$c['id'];
        $tid = (int)($selectedTarifs[$cid] ?? 0);
        $tid = isset($tarifs[$tid]) ? $tid : null;
        $ko = $tid ? admission_probleme(regles_crit($regles[$tid] ?? []), $age($c), $trancheDe[$cid], (string)$c['prenom'], 'Le tarif « ' . $tarifs[$tid]['nom'] . ' »') : null;
        if ($ko) backWithError($ko, $campToken, $site);
        $roles = $tid ? ($regles[$tid]['roles'] ?? []) : [];
        if (!$roles) { $demandes[] = ['tarif_id' => $tid, 'sexe' => $c['sexe'] ?? null]; continue; }
        // Chaque rôle reçoit une personne du compte ; l'enfant choisi en occupe un ; personne deux fois, personne déjà inscrite
        $choix = array_map('intval', (array)($_POST['participants'][$cid] ?? []));
        $ids = array_map(fn($i) => $choix[$i] ?? 0, array_keys($roles));
        if (count(array_keys($ids, $cid, true)) !== 1) backWithError($c['prenom'] . ' doit occuper un (et un seul) des rôles du tarif « ' . $tarifs[$tid]['nom'] . ' ».', $campToken, $site);
        $autres = array_values(array_diff(array_filter($ids), [$cid]));
        $st = $pdo->prepare("SELECT * FROM enfants WHERE parent_id = ? AND id IN (" . implode(',', array_fill(0, max(1, count($autres)), '?')) . ")");
        $st->execute(array_merge([$userId], $autres ?: [0]));
        $gens = [$cid => $c] + array_column($st->fetchAll(PDO::FETCH_ASSOC), null, 'id');
        foreach ($roles as $i => $role) {
            $p = $gens[$ids[$i]] ?? null;
            if (!$p) backWithError('Choisissez qui participe en tant que « ' . $role['nom'] . ' » (tarif « ' . $tarifs[$tid]['nom'] . ' »).', $campToken, $site);
            $pid = (int)$p['id'];
            if ($pid !== $cid) {
                if (isset($pris[$pid]) || in_array($pid, $alreadyIds, true)) backWithError($p['prenom'] . ' est déjà inscrit(e) ou choisi(e) pour un autre rôle.', $campToken, $site);
                $pris[$pid] = true;
                $trancheDe[$pid] = tranches_valides([(string)($_POST['tranche'][$pid] ?? '')])[0] ?? null;
                if (($age($p) ?? 0) < 18 && ($manque = options_enfant_manquants($camp, $p))) backWithError('La fiche de ' . $p['prenom'] . ' est incomplète. Champs exigés par l’organisateur : ' . implode(', ', $manque) . '.', $campToken, $site);
            }
            if (!empty($role['sexe']) ? ($p['sexe'] ?? null) !== $role['sexe'] : !options_sexe_admis($camp, $p['sexe'] ?? null)) backWithError('Le rôle « ' . $role['nom'] . ' » ne convient pas à ' . $p['prenom'] . '.', $campToken, $site);
            if ($pid !== $cid || isset($role['age_min']) || !empty($role['tranches'])) {
                $ko = admission_probleme(regles_crit_role($role, $camp), $age($p), $trancheDe[$pid], (string)$p['prenom'], 'Le rôle « ' . $role['nom'] . ' »');
                if ($ko) backWithError($ko, $campToken, $site);
            }
            $lots[$cid][] = [$p, $role['nom'], $trancheDe[$pid]];
            $demandes[] = ['tarif_id' => $tid, 'sexe' => $p['sexe'] ?? null];
        }
    }
    $ko = places_probleme($camp, $regles, $occupees, $demandes, array_column($tarifs, 'nom', 'id'));
    if ($ko) backWithError($ko, $campToken, $site);

    // 5. Date lisible de la session (affichée sur le paiement)
    [$s1, $s2] = normalizeSessionDates($camp['date_debut'], $camp['date_fin']);
    $mois = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $fmt = function (DateTime $d) use ($mois) { return $d->format('j') . ' ' . $mois[(int)$d->format('n') - 1]; };
    $sessionLabel = 'Du ' . $fmt($s1) . ' au ' . $fmt($s2) . ' ' . $s2->format('Y');

    // 5 bis. Questions personnalisées du directeur : validées côté serveur pour chaque enfant
    require_once __DIR__ . '/champs.php';
    $champs = champs_du_camp($pdo, (int)$camp['id']);
    $reponses = []; // [id enfant => [[champ_id, libelle, valeur]]]
    if ($champs) {
        $posted = (array)($_POST['reponses'] ?? []);
        foreach ($ordered as $child) {
            [$ok, $err] = champs_valider($champs, (array)($posted[$child['id']] ?? []), $child['prenom']);
            if ($err) backWithError($err, $campToken, $site);
            $reponses[(int)$child['id']] = $ok;
            foreach ($lots[(int)$child['id']] ?? [] as [$p, $role]) {   // autres participants : leurs réponses rejoignent l'inscription, préfixées
                if ((int)$p['id'] === (int)$child['id']) continue;
                [$ok, $err] = champs_valider($champs, (array)($posted[$p['id']] ?? []), $p['prenom']);
                if ($err) backWithError($err, $campToken, $site);
                foreach ($ok as $x) $reponses[(int)$child['id']][] = ['libelle' => mb_substr($p['prenom'] . ' (' . $role . ') · ' . $x['libelle'], 0, 255)] + $x;
            }
        }
    }

    // 6. Prix de chaque enfant, calculé uniquement avec la base
    $remise = max(0.0, min(100.0, (float)($camp['remise_fratrie'] ?? 0)));
    // Remise fratrie : on compte aussi les frères et sœurs DÉJÀ inscrits ET PAYÉS à ce séjour, même réservés à un autre moment.
    // Une réservation seulement « en attente » ne compte pas : sinon on pourrait obtenir la remise puis ne jamais payer le premier enfant.
    $st = $pdo->prepare("SELECT COUNT(*) FROM inscriptions WHERE camp_id = ? AND enfant_id IN (SELECT id FROM enfants WHERE parent_id = ?) AND statut_paiement = 'PAYE' AND COALESCE(statut, '') NOT IN ('Annulé', 'annule', 'Annule')");
    $st->execute([$camp['id'], $userId]);
    $dejaInscrits = (int)$st->fetchColumn();
    $orders = []; // ['child','cents','tarif_id','desc']
    foreach ($ordered as $index => $child) {
        $cid = (int)$child['id'];
        $tarifId = null; $nomTarif = 'Tarif standard';
        if ($tarifs) {
            $tid = (int)($selectedTarifs[$cid] ?? 0);
            if (!isset($tarifs[$tid])) backWithError('Choisissez un tarif pour ' . $child['prenom'] . '.', $campToken, $site);
            $t = $tarifs[$tid];
            $tarifId = $tid; $nomTarif = $t['nom'];
            $base = (float)$t['prix'];
            if (!empty($t['montant_libre'])) {
                $libre = (float)str_replace(',', '.', (string)($customPrices[$cid] ?? 0));
                // montant libre : jamais sous le minimum fixé par le directeur (à défaut, le prix du tarif)
                if ($libre > 0) $base = min(10000.0, max($libre, tarif_plancher($t)));
                else $base = max($base, tarif_plancher($t));
            }
        } else {
            $base = (float)$camp['prix'];
        }
        $cents = (int)round($base * 100);
        $desc = $camp['nom'] . ' · ' . $sessionLabel . ' · ' . $nomTarif;
        if (!empty($lots[$cid])) $desc .= ' · ' . implode(', ', array_map(fn($x) => $x[0]['prenom'] . ' (' . $x[1] . ')', $lots[$cid]));
        if (($index + $dejaInscrits) > 0 && $remise > 0) {   // remise fratrie : à partir du 2e enfant de la famille sur ce séjour
            $cents = (int)round($cents * (1 - $remise / 100));
            $desc .= ' · remise fratrie -' . rtrim(rtrim(number_format($remise, 2, '.', ''), '0'), '.') . '%';
        }
        $orders[] = ['child' => $child, 'cents' => max(0, $cents), 'tarif_id' => $tarifId, 'desc' => $desc];
    }

    $totalCents = array_sum(array_column($orders, 'cents'));
    fin_ensure($pdo); // colonnes / tables (avant la transaction : DDL)
    // Moyen de paiement : uniquement parmi ceux acceptés par le séjour (la carte reste le défaut)
    $moyens = fin_moyens_du_camp($camp);
    $moyen = (string)($_POST['moyen'] ?? 'carte');
    if (!in_array($moyen, $moyens, true)) backWithError('Ce moyen de paiement n\'est pas accepté pour ce séjour.', $campToken, $site);
    $horsStripe = $moyen !== 'carte' && $totalCents > 0;
    if ($totalCents > 0 && $totalCents < 50 && !$horsStripe) backWithError('Le montant minimum de paiement par carte est de 0,50 €.', $campToken, $site);

    // 7. Enregistrement (commande « en attente » ; les séjours gratuits sont confirmés tout de suite)
    $pdo->beginTransaction();
    $lineItems = []; $payTokens = [];
    $ins = $pdo->prepare("INSERT INTO inscriptions (camp_id, enfant_id, tarif_id, statut, prix_final, statut_paiement, reservation_token, mode_paiement, montant_paye, moyen_paiement, tranche)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $horsTokens = [];
    foreach ($orders as $o) {
        $token = bin2hex(random_bytes(16));
        $price = $o['cents'] / 100;
        if ($o['cents'] === 0) {
            $ins->execute([$camp['id'], $o['child']['id'], $o['tarif_id'], 'Confirmé', 0, 'PAYE', $token, 'GRATUIT', 0, null, $trancheDe[(int)$o['child']['id']]]);
        } elseif ($horsStripe) { // règlement direct à l'organisateur : place réservée, aucun appel à Stripe
            $ins->execute([$camp['id'], $o['child']['id'], $o['tarif_id'], 'Confirmé', $price, 'A_REGLER', $token, strtoupper($moyen), 0, $moyen, $trancheDe[(int)$o['child']['id']]]);
            $horsTokens[] = $token;
        } else {
            $ins->execute([$camp['id'], $o['child']['id'], $o['tarif_id'], 'En attente', $price, 'EN_ATTENTE', $token, 'NON_DEFINI', 0, 'carte', $trancheDe[(int)$o['child']['id']]]);
            $payTokens[] = $token;
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => [
                        'name' => mb_substr('Inscription : ' . $o['child']['prenom'] . ' ' . $o['child']['nom'], 0, 250),
                        'description' => mb_substr($o['desc'], 0, 500),
                    ],
                    'unit_amount' => $o['cents'],
                ],
                'quantity' => 1,
            ];
        }
        $insId = (int)$pdo->lastInsertId();
        $createdIds[] = $insId;
        if ($o['cents'] !== 0 && $horsStripe) {   // après lecture de l'identifiant : cette écriture change lastInsertId()
            try { require_once __DIR__ . '/moyens_paiement_lib.php'; fin_hors_commission_due($pdo, $insId, 'reservation'); } catch (Throwable $e) { error_log('commission hors carte: ' . $e->getMessage()); }
        }
        foreach ($lots[(int)$o['child']['id']] ?? [] as $n => [$p, $role, $tr]) $pdo->prepare("INSERT INTO inscription_participants (inscription_id, enfant_id, role, tranche, position) VALUES (?, ?, ?, ?, ?)")->execute([$insId, $p['id'], $role, $tr, $n]);
        foreach ($reponses[(int)$o['child']['id']] ?? [] as $r) {
            $pdo->prepare("INSERT INTO inscription_reponses (inscription_id, champ_id, libelle, valeur) VALUES (?, ?, ?, ?)")
                ->execute([$insId, $r['champ_id'], $r['libelle'], $r['valeur']]);
        }
    }
    $pdo->commit();

    // Règlement hors carte : l'inscription est enregistrée « à régler à l'organisateur », on affiche les consignes
    if ($horsTokens) {
        fin_audit($pdo, 'inscription_hors_stripe', 'camp', $camp['id'], ['moyen' => $moyen, 'inscriptions' => count($horsTokens), 'total_cents' => $totalCents], 'user:' . $userId);
        header('Location: ' . $site . '/reservation.php?r=' . urlencode($horsTokens[0]) . '&nouveau=1');
        exit;
    }
    // Tout est gratuit : rien à payer
    if (!$lineItems) {
        header('Location: ' . $site . '/inscription_confirmation.php?success=1&camp_t=' . urlencode($campToken));
        exit;
    }

    // 8. Session de paiement Stripe (codes promo Stripe acceptés, expiration 30 min = durée de blocage des places)
    $tokensString = implode(',', $payTokens);
    $params = [
        // moyens de paiement dynamiques : Stripe affiche carte (CB/Visa/Mastercard), Apple Pay, Google Pay, Link... selon le Dashboard, l'appareil et le pays
        'mode' => 'payment',
        'locale' => 'fr',
        'line_items' => $lineItems,
        'allow_promotion_codes' => true,
        'expires_at' => time() + 1800 + 60,
        'client_reference_id' => (string)$userId,
        'success_url' => $site . '/inscription_confirmation.php?success=1&camp_t=' . urlencode($campToken),
        'cancel_url'  => $site . '/inscription_confirmation.php?cancel=1&camp_t=' . urlencode($campToken) . '&tokens=' . urlencode($tokensString),
        'metadata' => [
            'reservation_token' => $tokensString,
            'camp_id' => (string)$camp['id'],
            'user_id' => (string)$userId,
            'type' => 'multi_inscription',
        ],
        'payment_intent_data' => [
            'description' => 'moncamp.fr · ' . mb_substr($camp['nom'], 0, 120) . ' · ' . $sessionLabel,
            'metadata' => ['reservation_token' => $tokensString, 'camp_id' => (string)$camp['id']],
        ],
    ];
    if (!empty($_SESSION['user']['email'])) $params['customer_email'] = $_SESSION['user']['email'];
    // Direct charges : paiement créé sur le compte Stripe de l'organisme, commission prélevée par moncamp.fr (repli destination / legacy si compte non prêt)
    [$extra, $opts] = fin_checkout_params($pdo, (int)$camp['organisateur_id'], array_map(fn($li) => (int)$li['price_data']['unit_amount'], $lineItems));
    $extra = array_replace_recursive($extra, fin_lisible($pdo, $payTokens)); // lisible dans le Stripe de l'organisme
    $extra = fin_detail_frais($extra);

    $checkout = \Stripe\Checkout\Session::create(array_replace_recursive($params, $extra), $opts);

    // Rapprochement : on garde l'identifiant de la session Stripe sur les inscriptions concernées
    $in = implode(',', array_fill(0, count($payTokens), '?'));
    $pdo->prepare("UPDATE inscriptions SET stripe_session_id = ? WHERE reservation_token IN ($in)")
        ->execute(array_merge([$checkout->id], $payTokens));

    header('Location: ' . $checkout->url, true, 303);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // Le paiement n'a pas pu démarrer : on libère les places réservées
    if ($createdIds) {
        $in = implode(',', array_fill(0, count($createdIds), '?'));
        try { $pdo->prepare("DELETE FROM inscriptions WHERE id IN ($in) AND statut_paiement = 'EN_ATTENTE'")->execute($createdIds); } catch (Throwable $e2) {}
    }
    error_log('create_stripe_session_multi: ' . $e->getMessage());
    backWithError('Le paiement n\'a pas pu démarrer. Aucun montant n\'a été débité, veuillez réessayer.', $campToken, $site);
}
