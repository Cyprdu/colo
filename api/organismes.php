<?php
// api/organismes.php : adresse publique d'un organisme : /organisme/<nom-de-l-organisme>
// Le « slug » (nom dans l'adresse) est unique : si deux organismes ont le même nom, le second reçoit -2, puis -3, etc.
// Il reste stable même si l'organisme change de nom (les liens déjà partagés continuent de marcher).
// Colonne ajoutée automatiquement et remplie pour les organismes existants (idempotent).

function orga_slugify(string $nom): string {
    $s = mb_strtolower(trim($nom));
    $s = strtr($s, ['à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ã'=>'a','å'=>'a','ç'=>'c','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ñ'=>'n','ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y','œ'=>'oe','æ'=>'ae','ß'=>'ss','\''=>' ','’'=>' ']);
    $s = trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    $s = trim(substr($s, 0, 70), '-');
    return $s !== '' ? $s : 'organisme';
}

function orga_unique_slug(PDO $pdo, string $nom, int $exceptId = 0): string {
    $base = orga_slugify($nom);
    $slug = $base; $i = 1;
    $st = $pdo->prepare("SELECT 1 FROM organisateurs WHERE slug = ? AND id <> ? LIMIT 1");
    while (true) {
        $st->execute([$slug, $exceptId]);
        if (!$st->fetchColumn()) return $slug;
        $i++; $slug = $base . '-' . $i;
        if ($i > 500) return $base . '-' . bin2hex(random_bytes(3));   // garde-fou
    }
}

/** Donne une adresse à un organisme (à appeler après sa création) */
function orga_assign_slug(PDO $pdo, int $id): void {
    orga_ensure($pdo);
    $st = $pdo->prepare("SELECT nom, slug FROM organisateurs WHERE id = ?"); $st->execute([$id]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o || !empty($o['slug'])) return;
    for ($try = 0; $try < 3; $try++) {   // en cas de création simultanée, l'index unique refuse le doublon : on réessaie
        try { $pdo->prepare("UPDATE organisateurs SET slug = ? WHERE id = ?")->execute([orga_unique_slug($pdo, (string)$o['nom'], $id), $id]); return; }
        catch (Throwable $e) { if ($try === 2) error_log('orga_assign_slug: ' . $e->getMessage()); }
    }
}

function orga_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM organisateurs")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('slug', $cols, true)) $pdo->exec("ALTER TABLE organisateurs ADD COLUMN slug VARCHAR(120) NULL DEFAULT NULL, ADD UNIQUE KEY uq_orga_slug (slug)");
        $rows = $pdo->query("SELECT id, nom FROM organisateurs WHERE slug IS NULL OR slug = '' ORDER BY id LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
        $up = $pdo->prepare("UPDATE organisateurs SET slug = ? WHERE id = ?");
        foreach ($rows as $r) { try { $up->execute([orga_unique_slug($pdo, (string)$r['nom'], (int)$r['id']), (int)$r['id']]); } catch (Throwable $e) {} }
    } catch (Throwable $e) { error_log('orga_ensure: ' . $e->getMessage()); }
}
