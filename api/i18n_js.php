<?php
require_once __DIR__ . '/i18n.php';
$l = in_array($_GET['l'] ?? '', MC_LANGS, true) ? $_GET['l'] : 'fr';
header('Content-Type: text/javascript; charset=utf-8');
header('Cache-Control: public, max-age=31536000, immutable');
echo 'window.MC_T=', json_encode($l === 'fr' ? new stdClass() : mc_dict($l), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG), ';';
