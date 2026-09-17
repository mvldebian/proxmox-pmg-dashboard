<?php
require_once __DIR__ . '/functions.php';
auth_start_session();

if (!auth_is_logged_in()) { header('Location: login.php'); exit; }
if (!auth_is_admin())     { header('Location: index.php'); exit; }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$me = auth_current_user();

if ($id === 0 || $id === (int)$me['id']) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Cannot delete own account.'];
    header('Location: users.php'); exit;
}

$user = db_find_user_by_id($id);
if (!$user) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'User not found.'];
    header('Location: users.php'); exit;
}

if ($user['role'] === 'admin' && db_count_admins() <= 1) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Cannot delete the only admin.'];
    header('Location: users.php'); exit;
}

db_delete_user($id);
$_SESSION['flash'] = ['type' => 'ok', 'msg' => "Deleted: {$user['username']}"];
header('Location: users.php');
exit;
