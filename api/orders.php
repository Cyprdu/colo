<?php
// api/orders.php : « commandes » du parent. Une commande = les inscriptions payées ensemble
// (même session de paiement Stripe), souvent plusieurs enfants pour un même séjour/session.
// Les adresses utilisent le JETON de réservation (secret), jamais un identifiant numérique.

require_once __DIR__ . '/sessions.php';
require_once __DIR__ . '/attente.php';

const ORDER_SELECT = "SELECT i.id AS inscription_id, i.reservation_token, i.stripe_session_id, i.stripe_payment_intent_id,
        i.statut, i.statut_paiement, i.mode_paiement, i.prix_final, i.montant_paye, i.date_inscription,
        t.nom AS tarif_nom, t.prix AS tarif_prix, t.montant_libre AS tarif_libre,
        c.id AS camp_id, c.token AS camp_token, c.nom AS camp_nom, c.ville, c.adresse, c.code_postal, c.image_url,
        c.date_debut, c.date_fin, c.inscription_en_ligne, c.valide, c.refuse, c.supprime, c.remise_fratrie, c.attente_paiement, c.attente_jours,
        o.nom AS orga_nom, o.logo_url AS orga_logo, o.user_id AS orga_user_id, o.email AS orga_email, o.tel AS orga_tel,
        e.id AS enfant_id, e.prenom AS enfant_prenom, e.nom AS enfant_nom, e.photo_token AS enfant_photo, e.date_naissance
    FROM inscriptions i
    JOIN enfants e ON e.id = i.enfant_id
    JOIN camps c ON c.id = i.camp_id
    LEFT JOIN organisateurs o ON o.id = c.organisateur_id
    LEFT JOIN tarifs t ON t.id = i.tarif_id";

/** Clé de commande d'une ligne : session de paiement, sinon paiement, sinon la ligne seule */
function orderKey(array $r): string {
    $k = $r['stripe_session_id'] ?: $r['stripe_payment_intent_id'];
    return $r['camp_id'] . '|' . ($k ? 'p' . $k : 'i' . $r['inscription_id']);
}

function isCancelled(array $r): bool { return in_array($r['statut'], ['Annulé', 'annule', 'Annule'], true); }

/** Toutes les inscriptions du parent, enrichies des dates corrigées */
function loadParentRows(PDO $pdo, int $userId): array {
    attente_ensure($pdo);
    $st = $pdo->prepare(ORDER_SELECT . " WHERE e.parent_id = ? ORDER BY c.date_debut ASC, i.date_inscription ASC, e.prenom ASC");
    $st->execute([$userId]);
    return array_map('enrichOrderRow', $st->fetchAll(PDO::FETCH_ASSOC));
}

function enrichOrderRow(array $r): array {
    [$d1, $d2] = normalizeSessionDates($r['date_debut'], $r['date_fin']);
    $r['_start'] = $d1; $r['_end'] = $d2;
    $r['_nights'] = max(0, (int)$d1->diff($d2)->days);
    return $r;
}

/** Les membres de la commande qui contient ce jeton (uniquement si elle appartient au parent) */
function loadOrderByToken(PDO $pdo, string $token, int $userId): array {
    attente_ensure($pdo);
    if (!preg_match('/^[A-Za-z0-9]{16,64}$/', $token)) return [];
    $st = $pdo->prepare(ORDER_SELECT . " WHERE i.reservation_token = ? AND e.parent_id = ?");
    $st->execute([$token, $userId]);
    $ref = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ref) return [];
    $key = $ref['stripe_session_id'] ?: $ref['stripe_payment_intent_id'];
    if (!$key) return [enrichOrderRow($ref)];
    $st = $pdo->prepare(ORDER_SELECT . " WHERE e.parent_id = ? AND i.camp_id = ? AND (i.stripe_session_id = ? OR i.stripe_payment_intent_id = ?) ORDER BY e.prenom ASC");
    $st->execute([$userId, $ref['camp_id'], $key, $key]);
    return array_map('enrichOrderRow', $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Regroupe des lignes en commandes (ordre conservé) */
function groupOrders(array $rows): array {
    $orders = [];
    foreach ($rows as $r) $orders[orderKey($r)][] = $r;
    return array_values($orders);
}

/** Statut global d'une commande : [code, libellé, classes CSS du badge] */
function orderStatus(array $members): array {
    $live = array_values(array_filter($members, function ($m) { return !isCancelled($m); }));
    if (!$live) return ['cancelled', 'Annulée', 'bg-gray-100 text-gray-600'];
    $total = 0; $paid = 0; $allPaid = true;
    foreach ($live as $m) {
        $total += (float)$m['prix_final'];
        $paid += (float)$m['montant_paye'];
        if ($m['statut_paiement'] !== 'PAYE') $allPaid = false;
    }
    // Rien de payé et le délai d'attente du directeur est dépassé : la place a été libérée
    $unpaidLive = array_filter($live, function ($m) { return $m['statut_paiement'] !== 'PAYE'; });
    $now = new DateTime();
    $allExpired = $unpaidLive && $paid <= 0.0;
    foreach ($unpaidLive as $m) { $l = attente_limite($m); if (!$l || $l > $now) { $allExpired = false; break; } }
    if ($allExpired) return ['expired', 'Expirée', 'bg-gray-100 text-gray-600'];
    if ($allPaid) return ['paid', 'Confirmée', 'bg-emerald-50 text-emerald-700'];
    if ($paid > 0.0 && $paid < $total) return ['partial', 'Paiement partiel', 'bg-amber-50 text-amber-700'];
    foreach ($live as $m) if ($m['statut_paiement'] === 'A_REGLER') return ['pending', 'À régler à l\'organisateur', 'bg-amber-50 text-amber-700'];
    return ['pending', 'À régler', 'bg-amber-50 text-amber-700'];
}

function orderTotals(array $members): array {
    $due = 0.0; $paid = 0.0;
    foreach ($members as $m) {
        if (isCancelled($m)) continue;
        $due += (float)$m['prix_final'];
        $paid += ($m['statut_paiement'] === 'PAYE') ? (float)$m['montant_paye'] : 0.0;
    }
    return ['due' => $due, 'paid' => $paid, 'discount' => max(0.0, $due - $paid)];
}

/** Référence lisible d'une commande (dérivée du jeton, sans identifiant numérique) */
function orderRef(array $members): string {
    return 'COL-' . strtoupper(substr($members[0]['reservation_token'], 0, 8));
}

function moisCourt(): array { return ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.']; }
function fmtJour(DateTime $d): string { return $d->format('j') . ' ' . moisCourt()[(int)$d->format('n') - 1]; }
function fmtPlage(DateTime $a, DateTime $b): string {
    return fmtJour($a) . ($a->format('Y') !== $b->format('Y') ? ' ' . $a->format('Y') : '') . ' – ' . fmtJour($b) . ' ' . $b->format('Y');
}
function fmtEuro(float $v): string { return number_format($v, 2, ',', ' ') . ' €'; }
function ageAnnees(?string $naissance): ?int {
    if (!$naissance) return null;
    try { return (new DateTime($naissance))->diff(new DateTime())->y; } catch (Throwable $e) { return null; }
}

/**
 * Détail du prix de chaque inscription : tarif choisi, remise fratrie, prix payé.
 * Le prix de base est celui du tarif enregistré ; pour une ancienne commande sans tarif enregistré,
 * la base est reconstituée à partir de la remise fratrie du séjour (2e enfant et suivants).
 * @return array [inscription_id => ['label','base','remise','pct','price']]
 */
function orderLines(array $members): array {
    $live = array_values(array_filter($members, function ($m) { return !isCancelled($m); }));
    usort($live, function ($a, $b) { return (int)$a['inscription_id'] <=> (int)$b['inscription_id']; });
    $rate = $live ? (float)($live[0]['remise_fratrie'] ?? 0) : 0.0;
    $lines = [];
    foreach ($live as $idx => $m) {
        $price = (float)$m['prix_final'];
        $base = $price;
        if ($m['tarif_prix'] !== null && empty($m['tarif_libre'])) {
            $base = max($price, (float)$m['tarif_prix']);
        } elseif ($idx > 0 && $rate > 0 && $rate < 100) {
            $guess = round($price / (1 - $rate / 100), 2);
            if ($guess > $price) $base = $guess;
        }
        $remise = round(max(0.0, $base - $price), 2);
        $lines[$m['inscription_id']] = [
            'label'  => $m['tarif_nom'] ?: 'Prix du séjour',
            'base'   => $base,
            'remise' => $remise,
            'pct'    => ($remise > 0.005 && $base > 0) ? (int)round($remise / $base * 100) : 0,
            'price'  => $price,
        ];
    }
    return $lines;
}

/** Totaux du détail : sous-total, remise fratrie, code promo (écart entre prix et montant réglé) */
function orderBreakdown(array $members): array {
    $lines = orderLines($members);
    $sub = 0.0; $fratrie = 0.0;
    foreach ($lines as $l) { $sub += $l['base']; $fratrie += $l['remise']; }
    $t = orderTotals($members);
    $promo = ($t['paid'] > 0 && $t['due'] - $t['paid'] > 0.005 && orderStatus($members)[0] === 'paid') ? round($t['due'] - $t['paid'], 2) : 0.0;
    return ['lines' => $lines, 'subtotal' => $sub, 'fratrie' => $fratrie, 'promo' => $promo, 'due' => $t['due'], 'paid' => $t['paid']];
}
