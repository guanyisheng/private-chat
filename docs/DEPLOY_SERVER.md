# 服务器部署说明（FFmpeg / PHP 超时）

## 一、确认 FFmpeg 是否可用

SSH 登录服务器后执行：

```bash
ffmpeg -version
ffprobe -version
```

若提示 `command not found`，请安装：

**Debian / Ubuntu：**

```bash
sudo apt update
sudo apt install -y ffmpeg
```

**CentOS / AlmaLinux：**

```bash
sudo yum install -y epel-release
sudo yum install -y ffmpeg ffmpeg-devel
```

安装后再次执行 `ffmpeg -version`，能看到版本号即表示成功。

---

## 二、把 PHP 执行时间调到 900 秒

视频压缩可能耗时较长，建议 `max_execution_time = 900`。

### 方法 A：宝塔面板

1. 登录宝塔 → **软件商店** → 已安装的 **PHP**（如 8.2）→ **设置**
2. 打开 **配置文件**（php.ini）
3. 搜索 `max_execution_time`，改为：

```ini
max_execution_time = 900
```

4. 同时建议：

```ini
upload_max_filesize = 200M
post_max_size = 210M
memory_limit = 512M
```

5. 保存后点击 **重载配置** 或 **重启 PHP**

### 方法 B：命令行改 php.ini

```bash
# 查看当前 PHP 配置文件路径
php --ini

# 编辑（路径以实际为准，常见如下）
sudo nano /etc/php/8.2/fpm/php.ini
# 或 Apache：
sudo nano /etc/php/8.2/apache2/php.ini
```

修改 `max_execution_time = 900` 后重启：

```bash
sudo systemctl restart php8.2-fpm
# 若用 Apache：
sudo systemctl restart apache2
```

### 方法 C：仅本项目目录（.user.ini）

若主机支持 PHP-FPM 的 `.user.ini`，可在网站根目录创建或编辑：

```ini
max_execution_time = 900
upload_max_filesize = 200M
post_max_size = 210M
```

保存后等待约 5 分钟生效，或重启 PHP-FPM。

### 验证是否生效

```bash
php -r "echo ini_get('max_execution_time');"
```

输出 `900` 即表示已生效。

> 说明：`api/upload.php` 内已调用 `set_time_limit(900)`，在允许的环境下会覆盖单次请求超时；仍建议在 php.ini 中设置，避免部分主机禁用 `set_time_limit`。

---

## 三、数据库迁移（闪图等新功能）

在 phpMyAdmin 或命令行执行（**只需这一个文件**）：

```bash
mysql -u用户名 -p 数据库名 < sql/migration_upgrade_all.sql
```

本文件已合并：房间结束/截屏/密码、闪图消息、Web Push 订阅表等全部升级项。若某字段已存在会自动跳过，可重复执行。

---

## 五、消息推送通知（可选）

暂时离开或浏览器在后台时，对方发消息会弹出系统通知（Android / Windows / Mac / iOS 16.4+）。

### 1. 安装 PHP 依赖

在项目根目录执行：

```bash
cd /www/wwwroot/你的站点目录
composer install
```

### 2. 生成 VAPID 密钥

```bash
php tools/generate_vapid.php
```

将输出内容保存为 `config/push.php`（可复制 `config/push.example.php` 再填入密钥）。

### 3. 首次进入聊天时允许通知

浏览器会弹出「允许通知」，点允许即可。  
**iOS**：需用 Safari **添加到主屏幕** 后，锁屏/切后台才能收到 Web Push。

### 4. 通知方式说明

| 场景 | 方式 |
|------|------|
| 暂时离开，首页停留 | 轮询 + 浏览器通知 |
| 聊天页切到后台 | 轮询 + 浏览器通知 |
| 完全关闭浏览器 | Web Push（需 composer + push.php 配置） |

---

## 四、部署文件后自检清单

| 项目 | 命令 / 操作 |
|------|-------------|
| FFmpeg | `ffmpeg -version` |
| PHP 超时 | `php -r "echo ini_get('max_execution_time');"` |
| 闪图表字段 | 执行 `migration_upgrade_all.sql` |
| R2 配置 | `config/r2.php` 存在且 `enabled => true` |
| 目录权限 | `uploads/`、`lt/`、`logs/` 可写 |
