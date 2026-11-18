<?php
// api/errors.php : enregistre les erreurs fatales / exceptions non rattrapées dans la table `app_erreurs`
// (consultable par l'administrateur : /admin_erreurs). Affiche une page propre au lieu d'un « HTTP 500 » brut.
// Chargé à la fin de api/config.php. Ne doit jamais lui-même provoquer d'erreur.

function err_log_db($type, $msg, $file, $line): void {
    global $pdo;
    try {
        if (!($pdo instanceof PDO)) return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_erreurs (
            id INT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            type VARCHAR(30) NOT NULL, message TEXT NOT NULL, fichier VARCHAR(255) NULL, ligne INT NULL,
            url VARCHAR(300) NULL, methode VARCHAR(8) NULL, user_id INT NULL, KEY idx_date (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $root = str_replace(chr(92), '/', dirname(__DIR__)) . '/';
        $f = str_replace(chr(92), '/', (string)$file);
        $f = strpos($f, $root) === 0 ? substr($f, strlen($root)) : $f;
        $url = preg_replace('/([?&](t|r|token|key)=)[^&]*/i', '$1…', (string)($_SERVER['REQUEST_URI'] ?? '')); // pas de jeton dans le journal
        $pdo->prepare("INSERT INTO app_erreurs (type, message, fichier, ligne, url, methode, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([substr((string)$type, 0, 30), mb_substr((string)$msg, 0, 2000), substr($f, 0, 255), (int)$line,
                       substr($url, 0, 300), substr((string)($_SERVER['REQUEST_METHOD'] ?? ''), 0, 8), $_SESSION['user']['id'] ?? null]);
        if (mt_rand(1, 50) === 1) $pdo->exec("DELETE FROM app_erreurs WHERE created_at < (NOW() - INTERVAL 30 DAY)");
    } catch (Throwable $e) { /* on ne fait jamais échouer la requête à cause du journal */ }
}

function err_page(): void {
    if (headers_sent()) return;
    http_response_code(500);
    $wantsJson = (strpos((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/') !== false) || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') === 0;
    if ($wantsJson) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'Une erreur est survenue. Réessayez dans un instant.']); return; }
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Petit souci - moncamp.fr</title>'
       . '<style>body{font-family:Montserrat,system-ui,sans-serif;background:#f8f5ea;color:#26343b;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}'
       . '.c{max-width:440px;padding:32px;text-align:center}h1{font-size:24px;margin:0 0 8px}p{color:#4b5964;line-height:1.5}a,button{display:inline-block;margin-top:16px;padding:12px 22px;border-radius:12px;background:#2f3d45;color:#fff;font-weight:700;text-decoration:none;border:0;font:inherit;cursor:pointer}</style></head>'
       . '<body><div class="c"><h1>Petit souci de notre côté</h1><p>La page n\'a pas pu s\'afficher. C\'est enregistré et nous allons regarder. Réessayez dans un instant.</p><button onclick="location.reload()">Actualiser</button> <a href="/">Accueil</a></div></body></html>';
}

set_exception_handler(function (Throwable $e) {
    err_log_db(get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
    error_log('Exception non rattrapée: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    err_page();
});

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        err_log_db('FATAL', $e['message'], $e['file'], $e['line']);
        if (!headers_sent() && ob_get_level() === 0) err_page();
    }
});
