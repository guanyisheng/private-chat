# Private Chat Room

私密双人聊天系统，基于 PHP + MySQL + 文件存储实现。无需注册，输入房间号即可开始聊天。

## 功能特性

- **房间机制**：输入数字房间号自动创建/加入房间，长期保存
- **双人聊天**：每个房间最多 2 人同时在线
- **实时消息**：AJAX 轮询，支持文本、表情、已读/已发送状态
- **文件传输**：图片（20MB）、视频（原片最大 200MB，服务端 FFmpeg 压缩至约 8–10MB 后存 R2）、文档文件上传
- **闪图**：长按查看 10 秒后聊天内自动销毁，管理后台仍可查看原图
- **JSON 存储**：聊天记录同步写入 MySQL 和 `/lt/{房间号}/chat.json`
- **管理后台**：房间管理、消息查看、内容审核
- **安全防护**：PDO 防注入、XSS/CSRF 防护、文件白名单

## 环境要求

- PHP 8.0+
- MySQL 8.0+
- Apache/Nginx Web 服务器
- PHP 扩展：PDO、GD（缩略图）、fileinfo
- **视频压缩（推荐）**：服务器安装 `ffmpeg` 与 `ffprobe`（如 `apt install ffmpeg`），用于发送前压缩与生成视频封面

**服务器配置步骤（FFmpeg、PHP 超时 900 秒、数据库迁移）见：** [docs/DEPLOY_SERVER.md](docs/DEPLOY_SERVER.md)

## 快速安装

### 方式一：安装向导（推荐）

1. 将项目文件上传到 Web 服务器目录
2. 确保 `uploads/`、`lt/`、`logs/` 目录可写（755 或 775）
3. 访问 `http://your-domain/install.php`
4. 填写数据库信息，点击安装
5. 安装完成后删除 `install.php`

### 方式二：手动安装

1. 创建 MySQL 数据库并导入 SQL：

```bash
mysql -u root -p < sql/schema.sql
```

2. 修改数据库配置 `config/database.php`：

```php
return [
    'host' => '127.0.0.1',
    'port' => 3306,
    'dbname' => 'private_chat',
    'username' => 'your_user',
    'password' => 'your_password',
    // ...
];
```

3. 设置目录权限：

```bash
chmod -R 755 uploads lt logs
chown -R www-data:www-data uploads lt logs
```

4. 访问网站首页

## 目录结构

```
├── admin/              # 管理后台
│   ├── index.php       # 登录页
│   ├── dashboard.php   # 仪表盘
│   ├── rooms.php       # 房间管理
│   ├── messages.php    # 聊天记录
│   └── moderation.php  # 内容审核
├── api/                # API 接口
│   ├── join.php        # 加入房间
│   ├── send.php        # 发送消息
│   ├── poll.php        # 轮询新消息
│   ├── upload.php      # 文件上传
│   ├── read.php        # 标记已读
│   ├── leave.php       # 离开房间
│   └── history.php     # 历史消息
├── assets/             # 静态资源
│   ├── css/
│   └── js/
├── config/             # 配置文件
│   ├── app.php
│   └── database.php
├── includes/           # 核心类库
│   ├── Core/           # 基础组件
│   ├── Models/         # 数据模型
│   └── Services/       # 业务服务
├── lt/                 # 聊天记录 JSON 存储
│   └── {房间号}/
│       ├── chat.json
│       └── update/
├── uploads/            # 上传文件存储
├── logs/               # 错误日志
├── sql/                # 数据库脚本
├── cron/               # 定时任务
├── index.php           # 首页
├── chat.php            # 聊天页
└── install.php         # 安装向导
```

## 使用说明

### 用户端

1. 打开网站首页
2. 输入 4-12 位数字房间号（如 `123456`）
3. 点击「进入房间」开始聊天
4. 分享相同房间号给另一人即可双人聊天

### 管理后台

- 访问地址：`/admin`
- 默认账号：`admin` / `admin123`
- **请首次登录后立即修改密码**

功能包括：
- 仪表盘：用户数、在线人数、房间数、消息统计
- 房间管理：查看/删除/封禁房间，清空消息
- 聊天记录：按房间号、关键词、日期搜索
- 内容审核：删除违规消息，封禁违规房间

## 聊天记录存储

每条消息会同步写入三个位置：

1. **MySQL** `messages` 表
2. **完整记录** `/lt/{房间号}/chat.json`
3. **增量文件** `/lt/{房间号}/update/{消息ID}.json`（用于轮询）

chat.json 示例：

```json
{
  "room": "123456",
  "created": "2026-06-01 18:00:00",
  "messages": [
    {
      "id": 1,
      "time": "2026-06-01 18:01:02",
      "user": "A",
      "type": "text",
      "content": "你好"
    }
  ]
}
```

## 定时任务

建议配置 crontab 清理过期数据：

```bash
# 每天凌晨 3 点执行
0 3 * * * php /path/to/project/cron/cleanup.php
```

功能：
- 清理超时离线用户
- 删除 30 天无人访问的房间

## 安全配置

### Apache

项目已包含 `.htaccess` 配置：
- 禁止访问 `config/`、`includes/`、`logs/` 目录
- 禁止 `uploads/` 目录执行 PHP
- 设置安全响应头

### Nginx 配置示例

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/project;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ ^/(config|includes|logs|sql)/ {
        deny all;
    }

    location ^~ /uploads/ {
        location ~ \.php$ { deny all; }
    }

    client_max_body_size 210M;
}
```

### PHP 配置

```ini
upload_max_filesize = 200M
post_max_size = 210M
max_execution_time = 900
```

## API 接口

| 接口 | 方法 | 说明 |
|------|------|------|
| `/api/join.php` | POST | 加入房间 |
| `/api/send.php` | POST | 发送文本消息 |
| `/api/poll.php` | GET | 轮询新消息 |
| `/api/upload.php` | POST | 上传文件 |
| `/api/read.php` | POST | 标记已读 |
| `/api/leave.php` | POST | 离开房间 |
| `/api/history.php` | GET | 获取历史消息 |

所有 POST 请求需携带 CSRF Token（`_csrf_token` 字段）。

## 文件上传限制

| 类型 | 格式 | 大小限制 | 存储位置 |
|------|------|----------|----------|
| 图片 | jpg, jpeg, png, gif, webp | 20MB | Cloudflare R2 |
| 视频 | mp4, webm, mov | 原片 200MB，压缩后约 8–10MB | Cloudflare R2 |
| 文件 | zip, rar, pdf, docx, xlsx, txt | 50MB | 本地 uploads/ |

## Cloudflare R2 配置

图片和视频上传至 Cloudflare R2，配置文件位于 `config/r2.php`（勿提交到 Git）。

1. 复制配置模板：

```bash
cp config/r2.example.php config/r2.php
```

2. 填入 R2 凭证：

```php
return [
    'enabled' => true,
    'endpoint' => 'https://YOUR_ACCOUNT_ID.r2.cloudflarestorage.com',
    'bucket' => 'your-bucket',
    'access_key_id' => 'YOUR_ACCESS_KEY',
    'secret_access_key' => 'YOUR_SECRET_KEY',
    'region' => 'auto',
    'public_url' => '',  // 可选：R2 公开访问域名
    'prefix' => 'chat',
];
```

3. **公开访问（推荐）**：在 Cloudflare R2 控制台为桶 `18sese` 开启 Public Access，将生成的 `https://pub-xxxxx.r2.dev` 填入 `public_url`，图片/视频将直接通过 CDN 访问。

4. **私有访问（默认）**：`public_url` 留空时，通过 `/api/media.php` 代理读取 R2 对象（需服务器安装 curl 扩展）。

对象存储路径示例：`chat/2026/06/{随机文件名}.jpg`

## 常见问题

**Q: 提示「房间人数已满」？**  
A: 每个房间最多 2 人同时在线。等待对方离开，或使用新房间号。

**Q: 上传失败？**  
A: 检查 PHP `upload_max_filesize` 和 `post_max_size` 配置，以及 `uploads/` 目录权限。

**Q: 视频没有压缩？**  
A: 需在服务器安装 `ffmpeg` 和 `ffprobe`，并在 `config/app.php` 中保持 `video_compress.enabled` 为 `true`。未安装时仍会上传原片。

**Q: 消息不同步？**  
A: 检查 `lt/` 目录写入权限，查看 `logs/error.log` 错误日志。

**Q: 如何修改管理员密码？**  
A: 在数据库中更新 `admins` 表的 `password_hash` 字段，使用 PHP `password_hash('新密码', PASSWORD_DEFAULT)` 生成。

## 技术栈

- PHP 8+ (PDO, GD)
- MySQL 8+
- HTML5 / CSS3 / JavaScript (Vanilla)
- MVC 架构

## License

MIT License
