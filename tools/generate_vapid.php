<?php
/**
 * 生成 Web Push VAPID 密钥对
 * 用法：composer install && php tools/generate_vapid.php
 */

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "请先运行: composer install\n");
    exit(1);
}

require $autoload;

use Minishlink\WebPush\VAPID;

$keys = VAPID::createVapidKeys();

echo "将以下内容写入 config/push.php：\n\n";
echo "<?php\nreturn [\n";
echo "    'enabled' => true,\n";
echo "    'vapid_public_key' => '" . $keys['publicKey'] . "',\n";
echo "    'vapid_private_key' => '" . $keys['privateKey'] . "',\n";
echo "    'vapid_subject' => 'mailto:admin@example.com',\n";
echo "];\n";
