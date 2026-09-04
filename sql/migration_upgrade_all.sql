-- ============================================================
-- Private Chat Room - 数据库一次性升级（合并版）
-- 适用于已有旧库，请执行一次即可
-- 全新安装请直接用 schema.sql，不必执行本文件
-- ============================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------
-- 1. 房间表 rooms：结束状态 / 截屏 / 密码
-- ----------------------------------------------------------

SET @db = DATABASE();

-- ended_at
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'rooms' AND COLUMN_NAME = 'ended_at'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `rooms` ADD COLUMN `ended_at` DATETIME DEFAULT NULL COMMENT ''聊天结束时间'' AFTER `is_banned`',
    'SELECT ''skip: rooms.ended_at'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ended_by_user_id
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'rooms' AND COLUMN_NAME = 'ended_by_user_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `rooms` ADD COLUMN `ended_by_user_id` INT UNSIGNED DEFAULT NULL COMMENT ''发起退出者'' AFTER `ended_at`',
    'SELECT ''skip: rooms.ended_by_user_id'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- idx_ended_at
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'rooms' AND INDEX_NAME = 'idx_ended_at'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `rooms` ADD KEY `idx_ended_at` (`ended_at`)',
    'SELECT ''skip: idx_ended_at'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- allow_screenshot
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'rooms' AND COLUMN_NAME = 'allow_screenshot'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `rooms` ADD COLUMN `allow_screenshot` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''是否允许截屏'' AFTER `ended_by_user_id`',
    'SELECT ''skip: rooms.allow_screenshot'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- password_hash
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'rooms' AND COLUMN_NAME = 'password_hash'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `rooms` ADD COLUMN `password_hash` VARCHAR(255) DEFAULT NULL COMMENT ''房间密码哈希'' AFTER `allow_screenshot`',
    'SELECT ''skip: rooms.password_hash'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------
-- 2. 消息表 messages：闪图类型 + 销毁时间
-- ----------------------------------------------------------

-- 扩展 type 枚举（含 flash）
ALTER TABLE `messages`
  MODIFY COLUMN `type` ENUM('text','image','video','file','flash') NOT NULL DEFAULT 'text';

-- flash_destroyed_at
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'flash_destroyed_at'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `messages` ADD COLUMN `flash_destroyed_at` DATETIME DEFAULT NULL COMMENT ''闪图销毁时间（聊天内不可见）'' AFTER `is_deleted`',
    'SELECT ''skip: messages.flash_destroyed_at'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- idx_flash_destroyed
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'messages' AND INDEX_NAME = 'idx_flash_destroyed'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `messages` ADD KEY `idx_flash_destroyed` (`flash_destroyed_at`)',
    'SELECT ''skip: idx_flash_destroyed'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- reply_to_id（消息引用）
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'reply_to_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `messages` ADD COLUMN `reply_to_id` INT UNSIGNED DEFAULT NULL COMMENT ''引用的消息ID'' AFTER `flash_destroyed_at`',
    'SELECT ''skip: messages.reply_to_id'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'messages' AND INDEX_NAME = 'idx_reply_to'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `messages` ADD KEY `idx_reply_to` (`reply_to_id`)',
    'SELECT ''skip: idx_reply_to'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- online_users.typing_at（正在输入）
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'online_users' AND COLUMN_NAME = 'typing_at'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `online_users` ADD COLUMN `typing_at` DATETIME DEFAULT NULL COMMENT ''正在输入时间'' AFTER `last_heartbeat`',
    'SELECT ''skip: online_users.typing_at'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------
-- 3. Web Push 订阅表
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `room_id` INT UNSIGNED NOT NULL,
  `endpoint` VARCHAR(500) NOT NULL,
  `p256dh` VARCHAR(255) NOT NULL,
  `auth` VARCHAR(255) NOT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_endpoint` (`endpoint`(191)),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_room_id` (`room_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 4. 视频异步处理任务表
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `video_jobs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` INT UNSIGNED NOT NULL,
  `room_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `local_path` VARCHAR(500) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `original_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
  `error_message` VARCHAR(500) DEFAULT NULL,
  `compressed` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_message_id` (`message_id`),
  KEY `idx_status` (`status`),
  KEY `idx_room_id` (`room_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 5. 多人昵称 / 头像
-- ----------------------------------------------------------

-- 必须先扩展长度，否则写入中文昵称会报 #1406
ALTER TABLE `users`
  MODIFY COLUMN `nickname` VARCHAR(32) NOT NULL COMMENT '显示昵称';

ALTER TABLE `messages`
  MODIFY COLUMN `sender` VARCHAR(32) NOT NULL COMMENT '发送时昵称快照';

-- users.avatar
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'avatar'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `avatar` VARCHAR(500) DEFAULT NULL COMMENT ''头像：color:hex 或 uploads 路径'' AFTER `nickname`',
    'SELECT ''skip: users.avatar'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 旧 A/B 昵称迁移
UPDATE `users` SET `nickname` = CASE `nickname`
    WHEN 'A' THEN '用户A'
    WHEN 'B' THEN '用户B'
    ELSE `nickname`
END WHERE CHAR_LENGTH(`nickname`) <= 1;

UPDATE `messages` SET `sender` = CASE `sender`
    WHEN 'A' THEN '用户A'
    WHEN 'B' THEN '用户B'
    ELSE `sender`
END WHERE `sender` IN ('A', 'B');

-- 为无头像用户补默认颜色
UPDATE `users` SET `avatar` = CONCAT('color:', SUBSTRING(MD5(nickname), 1, 6))
WHERE `avatar` IS NULL OR `avatar` = '';

-- 房间昵称唯一
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND INDEX_NAME = 'uk_room_nickname'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `users` ADD UNIQUE KEY `uk_room_nickname` (`room_id`, `nickname`)',
    'SELECT ''skip: uk_room_nickname'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 完成
SELECT 'migration_upgrade_all.sql 执行完成' AS result;
