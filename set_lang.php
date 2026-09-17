<?php
require_once __DIR__ . '/functions.php';

auth_start_session();

$code = $_GET['lang'] ?? '';
if ($code !== '') {
    lang_set($code, true);
}

// Redireciona de volta para onde o usuário estava
$ref = $_SERVER['HTTP_REFERER'] ?? 'index.php';
header('Location: ' . $ref);
exit;
