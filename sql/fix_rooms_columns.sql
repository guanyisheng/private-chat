-- 修复创建房间 500：rooms 表缺少新字段
-- 若 create_room.php 报 500，请先执行本文件

SET NAMES utf8mb4;
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

-- password_hash（房间密码，非管理员密码）
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'rooms' AND COLUMN_NAME = 'password_hash'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `rooms` ADD COLUMN `password_hash` VARCHAR(255) DEFAULT NULL COMMENT ''房间密码哈希'' AFTER `allow_screenshot`',
    'SELECT ''skip: rooms.password_hash'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'fix_rooms_columns.sql 执行完成' AS result;
