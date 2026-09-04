<?php
/**
 * API: Web Push VAPID 公钥
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Security;
use App\Services\PushNotificationService;

Security::jsonResponse([
    'success' => true,
    'enabled' => PushNotificationService::isEnabled(),
    'public_key' => PushNotificationService::getVapidPublicKey(),
]);
