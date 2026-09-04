<?php
/**
 * 应用全局配置
 */

declare(strict_types=1);

return [
    'app_name' => 'Private Chat Room',
    'app_url' => 'https://chat.de7o.my', // 分享链接域名，留空则自动检测
    'timezone' => 'Asia/Shanghai',
    'debug' => false, // 排查上传问题时临时改为 true

    // 会话配置
    'session_name' => 'PCR_SESSION',
    'session_lifetime' => 86400, // 24小时

    // CSRF Token 名称
    'csrf_token_name' => '_csrf_token',

    // 房间配置
    'max_users_per_room' => 10,
    'nickname_min_length' => 2,
    'nickname_max_length' => 20,
    'room_inactive_days' => 30, // 房间无人访问自动清理天数

    // 在线心跳超时（秒）
    'heartbeat_timeout' => 60,
    'heartbeat_interval' => 15, // 前端心跳间隔

    // 消息轮询间隔（毫秒）
    'poll_interval' => 2000,

    // 闪图：长按查看时长（秒），看完后聊天内销毁，后台仍保留
    'flash_view_seconds' => 10,

    // Web Push（可选，详见 config/push.example.php）
    'push' => [
        'enabled' => false,
        'vapid_public_key' => '',
        'vapid_private_key' => '',
        'vapid_subject' => 'mailto:admin@example.com',
    ],

    // 文件上传限制
    'upload' => [
        'image' => [
            'max_size' => 20 * 1024 * 1024, // 20MB
            'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'mime_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        ],
        'video' => [
            'max_size' => 200 * 1024 * 1024, // 200MB 原片上传上限，服务端压缩后约 8–10MB 存 R2
            'extensions' => ['mp4', 'webm', 'mov'],
            'mime_types' => ['video/mp4', 'video/webm', 'video/quicktime'],
        ],
        'file' => [
            'max_size' => 50 * 1024 * 1024, // 50MB
            'extensions' => ['zip', 'rar', 'pdf', 'docx', 'xlsx', 'txt'],
            'mime_types' => [
                'application/zip',
                'application/x-rar-compressed',
                'application/vnd.rar',
                'application/pdf',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/plain',
            ],
        ],
    ],

    // 服务端视频压缩（已关闭，改由浏览器压缩后上传）
    'video_compress' => [
        'enabled' => false,
        'ffmpeg_path' => 'ffmpeg',
        'ffprobe_path' => 'ffprobe',
        'target_max_bytes' => 10 * 1024 * 1024,
        'min_size_to_compress' => 8 * 1024 * 1024,
        'max_height' => 720,
        'crf' => 28,
        'preset' => 'medium',
        'audio_bitrate_k' => 96,
        'poster_seek' => '0.5',
        'poster_width' => 480,
    ],

    // 浏览器端视频压缩（发送前）
    'client_video_compress' => [
        'enabled' => true,
        'target_max_bytes' => 10 * 1024 * 1024,
        'min_size_to_compress' => 8 * 1024 * 1024,
        'max_height' => 720,
        'audio_bitrate' => 96000,
    ],

    // 缩略图配置
    'thumbnail' => [
        'max_width' => 300,
        'max_height' => 300,
        'quality' => 85,
    ],

    // 路径配置（相对于项目根目录）
    'paths' => [
        'uploads' => __DIR__ . '/../uploads',
        'lt' => __DIR__ . '/../lt',
        'logs' => __DIR__ . '/../logs',
    ],

    // 图片、视频使用 Cloudflare R2，文档仍存本地 uploads/
    'storage' => [
        'image' => 'r2',
        'video' => 'r2',
        'file' => 'local',
    ],
];
