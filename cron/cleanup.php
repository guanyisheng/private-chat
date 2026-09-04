<?php
/**
 * 定时任务 - 清理过期房间和离线用户
 * 
 * 建议通过 crontab 每天执行一次:
 * 0 3 * * * php /path/to/project/cron/cleanup.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\Database;
use App\Core\Logger;
use App\Models\Room;

echo "=== Private Chat Room Cleanup ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

// 清理过期离线用户
$config = require ROOT_PATH . '/config/app.php';
$timeout = $config['heartbeat_timeout'];

$deleted = Database::execute(
    'DELETE FROM online_users WHERE last_heartbeat < DATE_SUB(NOW(), INTERVAL ? SECOND)',
    [$timeout * 2]
);
echo "Cleaned offline users: {$deleted}\n";

// 清理过期房间
$roomCount = Room::cleanupInactive();
echo "Cleaned inactive rooms: {$roomCount}\n";

Logger::info('Cleanup completed', [
    'offline_users' => $deleted,
    'inactive_rooms' => $roomCount,
]);

echo "\nCompleted at: " . date('Y-m-d H:i:s') . "\n";
