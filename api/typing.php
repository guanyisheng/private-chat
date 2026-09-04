<?php
/**
 * API: 正在输入状态
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Security;
use App\Models\User;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Security::jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

requireCsrf();
$user = requireChatUser();

$typing = !empty($_POST['typing']);
User::setTyping($user['user_id'], $typing);

Security::jsonResponse(['success' => true]);
