<?php
$root = dirname(__DIR__);
chdir($root);
$page = $argv[1]; $uid = (int)$argv[2]; $qs = $argv[3] ?? '';
parse_str($qs, $_GET);
$_SERVER += ['REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/' . $page, 'SCRIPT_FILENAME' => $root . '/' . $page, 'HTTP_USER_AGENT' => 'test', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost', 'REQUEST_URI' => '/' . $page . ($qs ? '?' . $qs : '')];
$_SERVER['SCRIPT_NAME'] = '/' . $page; $_SERVER['SCRIPT_FILENAME'] = $root . '/' . $page;
ini_set('error_log', (string)getenv('ADM_ERRLOG'));
error_reporting(E_ALL);
ini_set('display_errors', '1');
session_id('t' . bin2hex(random_bytes(6)));
session_start();
require $root . '/api/config.php';
if ($uid) {
    $_SESSION['user'] = $pdo->query("SELECT * FROM users WHERE id = $uid")->fetch();
    $_SESSION['adm_fp'] = hash('sha256', 'test');
    $_SESSION['adm_last'] = time();
    $_SESSION['adm_reauth'] = time();
}
register_shutdown_function(function () { echo "\n@@END@@"; });
include $root . '/' . $page;
