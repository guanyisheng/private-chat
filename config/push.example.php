<?php
/**
 * 推送配置示例 - 复制为 config/push.php 并填入 VAPID 密钥
 * 生成密钥：composer install && php tools/generate_vapid.php
 */

declare(strict_types=1);

return [
    'enabled' => true,
    'vapid_public_key' => 'YOUR_VAPID_PUBLIC_KEY',
    'vapid_private_key' => 'YOUR_VAPID_PRIVATE_KEY',
    'vapid_subject' => 'mailto:admin@example.com',
];
