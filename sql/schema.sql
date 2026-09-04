-- Private Chat Room - Database Schema
-- MySQL 8.0+

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `private_chat` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `private_chat`;

-- 管理员表
DROP TABLE IF EXISTS `logs`;
DROP TABLE IF EXISTS `uploads`;
DROP TABLE IF EXISTS `messages`;
DROP TABLE IF EXISTS `online_users`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `rooms`;
DROP TABLE IF EXISTS `admins`;

CREATE TABLE `admins` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 默认管理员: admin / admin123 (首次登录后请修改密码)
INSERT INTO `admins` (`username`, `password_hash`) VALUES
('admin', '$2y$10$FMgcyQXQ4sNgqn/GFd.8kOgqVHQsC1EtcCyQ4I7r8W4B0D8C18Zh6');

CREATE TABLE `rooms` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_code` VARCHAR(20) NOT NULL COMMENT '房间号',
  `room_name` VARCHAR(50) NOT NULL COMMENT 'Room_123456',
  `is_banned` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否封禁',
  `ended_at` DATETIME DEFAULT NULL COMMENT '聊天结束时间',
  `ended_by_user_id` INT UNSIGNED DEFAULT NULL COMMENT '发起退出者',
  `allow_screenshot` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否允许截屏 0禁止 1允许',
  `password_hash` VARCHAR(255) DEFAULT NULL COMMENT '房间密码哈希，空则无密码',
  `last_accessed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_room_code` (`room_code`),
  KEY `idx_last_accessed` (`last_accessed_at`),
  KEY `idx_banned` (`is_banned`),
  KEY `idx_ended_at` (`ended_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_token` VARCHAR(64) NOT NULL COMMENT '会话标识',
  `nickname` VARCHAR(32) NOT NULL COMMENT '显示昵称',
  `avatar` VARCHAR(500) DEFAULT NULL COMMENT '头像：color:hex 或 uploads 路径',
  `room_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_session_token` (`session_token`),
  UNIQUE KEY `uk_room_nickname` (`room_id`, `nickname`),
  KEY `idx_room_id` (`room_id`),
  CONSTRAINT `fk_users_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `online_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `room_id` INT UNSIGNED NOT NULL,
  `session_token` VARCHAR(64) NOT NULL,
  `last_heartbeat` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `typing_at` DATETIME DEFAULT NULL COMMENT '正在输入时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_id` (`user_id`),
  KEY `idx_room_id` (`room_id`),
  KEY `idx_heartbeat` (`last_heartbeat`),
  CONSTRAINT `fk_online_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_online_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `sender` VARCHAR(32) NOT NULL COMMENT '发送时昵称快照',
  `type` ENUM('text','image','video','file','flash') NOT NULL DEFAULT 'text',
  `content` TEXT NOT NULL,
  `file_name` VARCHAR(255) DEFAULT NULL,
  `file_size` BIGINT UNSIGNED DEFAULT NULL,
  `status` ENUM('sent','delivered','read') NOT NULL DEFAULT 'sent',
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `flash_destroyed_at` DATETIME DEFAULT NULL COMMENT '闪图销毁时间',
  `reply_to_id` INT UNSIGNED DEFAULT NULL COMMENT '引用的消息ID',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_room_id` (`room_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_room_created` (`room_id`, `created_at`),
  KEY `idx_flash_destroyed` (`flash_destroyed_at`),
  KEY `idx_reply_to` (`reply_to_id`),
  CONSTRAINT `fk_messages_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `uploads` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` INT UNSIGNED NOT NULL,
  `room_id` INT UNSIGNED NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `thumb_path` VARCHAR(500) DEFAULT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `file_size` BIGINT UNSIGNED NOT NULL,
  `file_type` ENUM('image','video','file') NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_message_id` (`message_id`),
  KEY `idx_room_id` (`room_id`),
  CONSTRAINT `fk_uploads_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_uploads_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `push_subscriptions` (
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

CREATE TABLE `video_jobs` (
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

CREATE TABLE `logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `level` ENUM('info','warning','error') NOT NULL DEFAULT 'info',
  `message` TEXT NOT NULL,
  `context` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_level` (`level`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
