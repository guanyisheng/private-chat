-- 修复 #1406 Data too long for column 'sender'
-- 原因：messages.sender / users.nickname 仍为 CHAR(1)，无法存中文昵称
-- 在 phpMyAdmin 或 mysql 客户端执行本文件即可

SET NAMES utf8mb4;

-- 1. 先扩展字段长度（最重要）
ALTER TABLE `users`
  MODIFY COLUMN `nickname` VARCHAR(32) NOT NULL COMMENT '显示昵称';

ALTER TABLE `messages`
  MODIFY COLUMN `sender` VARCHAR(32) NOT NULL COMMENT '发送时昵称快照';

-- 2. 旧 A/B 数据迁移（可选，已有长昵称可跳过）
UPDATE `users` SET `nickname` = CASE `nickname`
    WHEN 'A' THEN '用户A'
    WHEN 'B' THEN '用户B'
    ELSE `nickname`
END WHERE `nickname` IN ('A', 'B');

UPDATE `messages` SET `sender` = CASE `sender`
    WHEN 'A' THEN '用户A'
    WHEN 'B' THEN '用户B'
    ELSE `sender`
END WHERE `sender` IN ('A', 'B');

SELECT 'fix_sender_nickname.sql 执行完成' AS result;
