<?php
/**
 * Web Push 通知（对方离线/暂时离开时推送）
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Models\PushSubscription;
use App\Models\User;

class PushNotificationService
{
    /**
     * 新消息时通知房间内离线对方
     */
    public static function notifyPartner(int $roomId, int $fromUserId, string $preview): void
    {
        try {
            $config = self::getConfig();
            if (empty($config['enabled'])) {
                return;
            }

            $partnerIds = self::getOfflinePartnerIds($roomId, $fromUserId);
            if ($partnerIds === []) {
                return;
            }

            $subs = PushSubscription::getByUserIds($partnerIds);
            if ($subs === []) {
                return;
            }

            if (!class_exists(\Minishlink\WebPush\WebPush::class, false)) {
                Logger::warning('Web Push: run composer install to enable push notifications');
                return;
            }

            $publicKey = $config['vapid_public_key'] ?? '';
            $privateKey = $config['vapid_private_key'] ?? '';
            $subject = $config['vapid_subject'] ?? 'mailto:admin@localhost';

            if ($publicKey === '' || $privateKey === '') {
                return;
            }

            $auth = [
                'VAPID' => [
                    'subject' => $subject,
                    'publicKey' => $publicKey,
                    'privateKey' => $privateKey,
                ],
            ];

            $webPush = new \Minishlink\WebPush\WebPush($auth);
            $appConfig = require ROOT_PATH . '/config/app.php';
            $appName = $appConfig['app_name'] ?? 'Private Chat';

            $payload = json_encode([
                'title' => $appName,
                'body' => $preview,
                'url' => '/chat.php',
                'tag' => 'room-' . $roomId,
            ], JSON_UNESCAPED_UNICODE);

            foreach ($subs as $row) {
                $subscription = \Minishlink\WebPush\Subscription::create([
                    'endpoint' => $row['endpoint'],
                    'keys' => [
                        'p256dh' => $row['p256dh'],
                        'auth' => $row['auth'],
                    ],
                ]);
                $webPush->queueNotification($subscription, $payload);
            }

            foreach ($webPush->flush() as $report) {
                if (!$report->isSuccess()) {
                    Logger::warning('Web Push failed: ' . $report->getReason());
                }
            }
        } catch (\Throwable $e) {
            Logger::warning('Push notify skipped: ' . $e->getMessage());
        }
    }

    public static function getVapidPublicKey(): string
    {
        $config = self::getConfig();
        return (string) ($config['vapid_public_key'] ?? '');
    }

    public static function isEnabled(): bool
    {
        $config = self::getConfig();
        return !empty($config['enabled']) && self::getVapidPublicKey() !== '';
    }

    /**
     * @return int[]
     */
    private static function getOfflinePartnerIds(int $roomId, int $fromUserId): array
    {
        $users = User::getUsersInRoom($roomId);
        $ids = [];

        foreach ($users as $user) {
            $uid = (int) $user['id'];
            if ($uid === $fromUserId) {
                continue;
            }
            if (!User::isOnline($uid)) {
                $ids[] = $uid;
            }
        }

        return $ids;
    }

    private static function getConfig(): array
    {
        $pushFile = ROOT_PATH . '/config/push.php';
        if (file_exists($pushFile)) {
            return require $pushFile;
        }

        $appConfig = require ROOT_PATH . '/config/app.php';
        return $appConfig['push'] ?? ['enabled' => false];
    }

    public static function buildPreview(string $type, ?string $content, ?string $fileName = null): string
    {
        return match ($type) {
            'text' => '用户发来消息：' . mb_substr($content ?? '', 0, 80),
            'image' => '用户发来一张图片',
            'flash' => '用户发来一张闪图',
            'video' => '用户发来一段视频',
            'file' => '用户发来文件：' . ($fileName ?? '附件'),
            default => '您有一条新消息',
        };
    }
}
