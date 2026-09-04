<?php
/**
 * 聊天记录 JSON 文件存储管理
 * 同步 MySQL 与 chat.json / update/*.json
 */

declare(strict_types=1);

namespace App\Core;

use App\Models\Message;

class ChatStorage
{
    private string $ltPath;

    public function __construct()
    {
        $config = require ROOT_PATH . '/config/app.php';
        $this->ltPath = $config['paths']['lt'];
    }

    /**
     * 获取房间目录路径
     */
    public function getRoomPath(string $roomCode): string
    {
        return $this->ltPath . '/' . $roomCode;
    }

    /**
     * 初始化房间文件存储目录
     */
    public function initRoom(string $roomCode): bool
    {
        $roomPath = $this->getRoomPath($roomCode);
        $updatePath = $roomPath . '/update';

        if (!is_dir($roomPath)) {
            mkdir($roomPath, 0755, true);
        }
        if (!is_dir($updatePath)) {
            mkdir($updatePath, 0755, true);
        }

        $chatFile = $roomPath . '/chat.json';
        if (!file_exists($chatFile)) {
            $data = [
                'room' => $roomCode,
                'created' => date('Y-m-d H:i:s'),
                'messages' => [],
            ];
            return $this->writeJson($chatFile, $data);
        }

        return true;
    }

    /**
     * 添加消息到 chat.json 并创建 update 文件
     */
    public function addMessage(string $roomCode, array $message): bool
    {
        try {
            $roomPath = $this->getRoomPath($roomCode);
            $chatFile = $roomPath . '/chat.json';

            // 确保目录存在
            $this->initRoom($roomCode);

            // 读取现有 chat.json
            $chatData = $this->readJson($chatFile);
            if ($chatData === null) {
                $chatData = [
                    'room' => $roomCode,
                    'created' => date('Y-m-d H:i:s'),
                    'messages' => [],
                ];
            }

            // 格式化消息（媒体文件写入可访问 URL）
            $content = $message['content'];
            $thumbPath = $message['thumb_path'] ?? null;
            if (in_array($message['type'] ?? '', ['image', 'video', 'file', 'flash'], true)) {
                if (!Message::isVideoPending($content) && !Message::isVideoFailed($content)) {
                    $content = MediaUrl::resolve($content);
                } else {
                    $content = '';
                }
            }
            if ($thumbPath) {
                $thumbPath = MediaUrl::resolve($thumbPath);
            }

            $jsonMessage = [
                'id' => (int) $message['id'],
                'time' => $message['created_at'] ?? date('Y-m-d H:i:s'),
                'user' => $message['sender'],
                'type' => $message['type'],
                'content' => $content,
            ];

            if (!empty($message['file_name'])) {
                $jsonMessage['file_name'] = $message['file_name'];
            }
            if (!empty($message['file_size'])) {
                $jsonMessage['file_size'] = (int) $message['file_size'];
            }
            if (!empty($message['thumb_path'])) {
                $jsonMessage['thumb_path'] = $thumbPath;
            }

            // 追加到 chat.json
            $chatData['messages'][] = $jsonMessage;
            $this->writeJson($chatFile, $chatData);

            // 创建 update 增量文件
            $updateFile = $roomPath . '/update/' . $message['id'] . '.json';
            $this->writeJson($updateFile, $jsonMessage);

            return true;
        } catch (\Exception $e) {
            Logger::error('ChatStorage addMessage failed: ' . $e->getMessage(), [
                'room' => $roomCode,
                'message_id' => $message['id'] ?? null,
            ]);
            return false;
        }
    }

    /**
     * 更新 chat.json 中媒体消息（视频异步处理完成后）
     */
    public function updateMessageMedia(
        string $roomCode,
        int $messageId,
        string $objectKey,
        int $fileSize,
        ?string $thumbPath
    ): bool {
        try {
            $roomPath = $this->getRoomPath($roomCode);
            $chatFile = $roomPath . '/chat.json';
            if (!file_exists($chatFile)) {
                return true;
            }

            $chatData = $this->readJson($chatFile);
            if ($chatData === null) {
                return false;
            }

            $resolvedContent = MediaUrl::resolve($objectKey);
            $resolvedThumb = $thumbPath ? MediaUrl::resolve($thumbPath) : null;

            foreach ($chatData['messages'] as &$entry) {
                if ((int) ($entry['id'] ?? 0) !== $messageId) {
                    continue;
                }
                $entry['content'] = $resolvedContent;
                $entry['file_size'] = $fileSize;
                if ($resolvedThumb) {
                    $entry['thumb_path'] = $resolvedThumb;
                }
                break;
            }
            unset($entry);

            $this->writeJson($chatFile, $chatData);

            $updateFile = $roomPath . '/update/' . $messageId . '.json';
            if (file_exists($updateFile)) {
                $updateData = $this->readJson($updateFile);
                if ($updateData !== null) {
                    $updateData['content'] = $resolvedContent;
                    $updateData['file_size'] = $fileSize;
                    if ($resolvedThumb) {
                        $updateData['thumb_path'] = $resolvedThumb;
                    }
                    $this->writeJson($updateFile, $updateData);
                }
            }

            return true;
        } catch (\Exception $e) {
            Logger::error('ChatStorage updateMessageMedia failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 从 chat.json 删除消息并删除 update 文件
     */
    public function deleteMessage(string $roomCode, int $messageId): bool
    {
        try {
            $roomPath = $this->getRoomPath($roomCode);
            $chatFile = $roomPath . '/chat.json';

            if (!file_exists($chatFile)) {
                return true;
            }

            $chatData = $this->readJson($chatFile);
            if ($chatData === null) {
                return false;
            }

            // 从 messages 数组中移除
            $chatData['messages'] = array_values(array_filter(
                $chatData['messages'],
                fn($m) => ($m['id'] ?? 0) !== $messageId
            ));

            $this->writeJson($chatFile, $chatData);

            // 删除 update 文件
            $updateFile = $roomPath . '/update/' . $messageId . '.json';
            if (file_exists($updateFile)) {
                unlink($updateFile);
            }

            return true;
        } catch (\Exception $e) {
            Logger::error('ChatStorage deleteMessage failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取增量消息（轮询用）
     */
    public function getUpdates(string $roomCode, int $lastId): array
    {
        $updatePath = $this->getRoomPath($roomCode) . '/update';
        if (!is_dir($updatePath)) {
            return [];
        }

        $messages = [];
        $files = glob($updatePath . '/*.json');
        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            $id = (int) basename($file, '.json');
            if ($id > $lastId) {
                $data = $this->readJson($file);
                if ($data !== null) {
                    $messages[] = $data;
                }
            }
        }

        // 按 ID 排序
        usort($messages, fn($a, $b) => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));

        return $messages;
    }

    /**
     * 读取完整 chat.json
     */
    public function getChatLog(string $roomCode): ?array
    {
        $chatFile = $this->getRoomPath($roomCode) . '/chat.json';
        return $this->readJson($chatFile);
    }

    /**
     * 删除房间所有文件
     */
    public function deleteRoom(string $roomCode): bool
    {
        $roomPath = $this->getRoomPath($roomCode);
        if (!is_dir($roomPath)) {
            return true;
        }

        return $this->deleteDirectory($roomPath);
    }

    /**
     * 清空房间消息文件
     */
    public function clearMessages(string $roomCode): bool
    {
        try {
            $roomPath = $this->getRoomPath($roomCode);
            $chatFile = $roomPath . '/chat.json';
            $updatePath = $roomPath . '/update';

            // 重置 chat.json
            $data = [
                'room' => $roomCode,
                'created' => date('Y-m-d H:i:s'),
                'messages' => [],
            ];
            $this->writeJson($chatFile, $data);

            // 删除 update 目录下所有文件
            if (is_dir($updatePath)) {
                $files = glob($updatePath . '/*.json');
                if ($files) {
                    foreach ($files as $file) {
                        unlink($file);
                    }
                }
            }

            return true;
        } catch (\Exception $e) {
            Logger::error('ChatStorage clearMessages failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 读取 JSON 文件
     */
    private function readJson(string $file): ?array
    {
        if (!file_exists($file)) {
            return null;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    /**
     * 写入 JSON 文件
     */
    private function writeJson(string $file, array $data): bool
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return file_put_contents($file, $json, LOCK_EX) !== false;
    }

    /**
     * 递归删除目录
     */
    private function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $items = scandir($dir);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        return rmdir($dir);
    }
}
