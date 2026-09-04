<?php
/**
 * 用户头像 - 文字首字 / 预制 SVG / 本地上传
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Core\MediaUrl;
use App\Core\Security;

class AvatarService
{
    /** @var list<string> 预制 SVG 编号 */
    public const SVG_PRESETS = [
        '01', '02', '03', '04', '05', '06',
        '07', '08', '09', '10', '11', '12',
    ];

    /** @var list<string> 文字头像背景色（无 #） */
    public const COLORS = [
        '6c5ce7', '0984e3', '00b894', 'fdcb6e',
        'e17055', 'd63031', 'a29bfe', '74b9ff',
    ];

    /**
     * 预制 SVG 列表（供前端）
     *
     * @return list<array{id: string, url: string, label: string}>
     */
    public static function listPresetsForClient(): array
    {
        $labels = [
            '01' => '机器人', '02' => '战士', '03' => '兔子', '04' => '忍者',
            '05' => '机甲', '06' => '小丑', '07' => '骑士', '08' => '猫咪',
            '09' => '法师', '10' => '海盗', '11' => '电视', '12' => '精灵',
        ];

        $list = [];
        foreach (self::SVG_PRESETS as $id) {
            $list[] = [
                'id' => $id,
                'url' => self::presetUrl($id),
                'label' => $labels[$id] ?? ('头像' . $id),
            ];
        }

        return $list;
    }

    public static function presetUrl(string $id): string
    {
        return '/assets/avatars/presets/' . $id . '.svg';
    }

    /**
     * 格式化头像供前端渲染
     *
     * @return array{type: string, url?: string, color: string, letter: string, preset_id?: string}
     */
    public static function formatForClient(?string $avatar, string $nickname): array
    {
        $letter = self::firstLetter($nickname);
        $color = self::defaultColor($nickname);

        if ($avatar !== null && $avatar !== '') {
            if (str_starts_with($avatar, 'svg:')) {
                $presetId = substr($avatar, 4);
                if (self::isValidPresetId($presetId)) {
                    return [
                        'type' => 'svg',
                        'url' => self::presetUrl($presetId),
                        'preset_id' => $presetId,
                        'color' => '#' . $color,
                        'letter' => $letter,
                    ];
                }
            }

            if (str_starts_with($avatar, 'color:')) {
                $hex = substr($avatar, 6);
                if (self::isValidColor($hex)) {
                    $color = $hex;
                }
            } elseif (!str_starts_with($avatar, 'letter')) {
                $url = MediaUrl::resolve($avatar);

                return [
                    'type' => 'image',
                    'url' => $url,
                    'color' => '#' . $color,
                    'letter' => $letter,
                ];
            }
        }

        return [
            'type' => 'letter',
            'color' => '#' . $color,
            'letter' => $letter,
        ];
    }

    /**
     * 规范化预制 SVG 编号
     */
    public static function normalizePreset(?string $presetId): string
    {
        if ($presetId === null || $presetId === '') {
            return '';
        }

        $presetId = trim($presetId);
        if (preg_match('/^\d{1,2}$/', $presetId)) {
            $presetId = str_pad($presetId, 2, '0', STR_PAD_LEFT);
        }

        return self::isValidPresetId($presetId) ? 'svg:' . $presetId : '';
    }

    /**
     * 文字头像（跟随昵称首字）
     */
    public static function normalizeLetter(): string
    {
        return 'letter:auto';
    }

    /**
     * 规范化存储值（颜色头像，兼容旧数据）
     */
    public static function normalizeColor(?string $color): string
    {
        if ($color === null || $color === '') {
            return '';
        }

        $hex = ltrim(trim($color), '#');
        if (!self::isValidColor($hex)) {
            return '';
        }

        return 'color:' . strtolower($hex);
    }

    /**
     * 根据昵称生成默认颜色
     */
    public static function defaultColor(string $nickname): string
    {
        $hash = crc32($nickname);

        return self::COLORS[abs($hash) % count(self::COLORS)];
    }

    /**
     * 上传头像图片（存本地 uploads/avatars/）
     */
    public static function handleUpload(array $file, int $userId): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }

        $maxSize = 2 * 1024 * 1024;
        if (($file['size'] ?? 0) > $maxSize) {
            return null;
        }

        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            return null;
        }

        if (!Security::validateMimeType($file['tmp_name'], [
            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        ])) {
            return null;
        }

        $dir = ROOT_PATH . '/uploads/avatars/' . date('Y/m');
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            return null;
        }

        $stored = 'avatar_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        $dest = $dir . '/' . $stored;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            Logger::error('Avatar upload failed', ['user_id' => $userId]);

            return null;
        }

        return 'uploads/avatars/' . date('Y/m') . '/' . $stored;
    }

    /**
     * 删除旧头像文件（仅本地上传）
     */
    public static function deleteLocalFile(?string $avatar): void
    {
        if ($avatar === null || $avatar === ''
            || str_starts_with($avatar, 'color:')
            || str_starts_with($avatar, 'svg:')
            || str_starts_with($avatar, 'letter')) {
            return;
        }

        if (!str_starts_with($avatar, 'uploads/avatars/')) {
            return;
        }

        $path = ROOT_PATH . '/' . ltrim($avatar, '/');
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * 昵称首字（中文取第一个字，如「路南」→「路」）
     */
    public static function firstLetter(string $nickname): string
    {
        $nickname = trim($nickname);
        if ($nickname === '') {
            return '?';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($nickname, 0, 1, 'UTF-8');
        }

        return substr($nickname, 0, 1);
    }

    public static function isValidPresetId(string $id): bool
    {
        return in_array($id, self::SVG_PRESETS, true);
    }

    private static function isValidColor(string $hex): bool
    {
        return (bool) preg_match('/^[0-9a-fA-F]{6}$/', $hex);
    }
}
