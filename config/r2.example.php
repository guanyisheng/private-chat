<?php
/**
 * Cloudflare R2 配置示例
 * 复制为 r2.php 并填入真实凭证（r2.php 已在 .gitignore 中）
 */

declare(strict_types=1);

return [
    'enabled' => true,

    // S3 兼容 API 端点（不含 bucket 名）
    'endpoint' => 'https://YOUR_ACCOUNT_ID.r2.cloudflarestorage.com',

    // 桶名称
    'bucket' => 'your-bucket',

    // S3 访问密钥
    'access_key_id' => 'YOUR_ACCESS_KEY_ID',
    'secret_access_key' => 'YOUR_SECRET_ACCESS_KEY',

    // 区域固定为 auto（Cloudflare R2 要求）
    'region' => 'auto',

    /**
     * 公开访问 URL（可选）
     * 在 R2 控制台为桶开启 Public Access 或绑定自定义域名后填写
     * 例如: https://pub-xxxxx.r2.dev 或 https://cdn.example.com
     * 留空则通过 /api/media.php 代理访问
     */
    'public_url' => '',

    // 对象存储前缀
    'prefix' => 'chat',
];
