<?php
/**
 * 安装向导 - 初始化数据库和配置
 * 安装完成后请删除此文件
 */

declare(strict_types=1);

// 检查是否已安装
$lockFile = __DIR__ . '/config/installed.lock';
if (file_exists($lockFile)) {
    die('系统已安装。如需重新安装，请删除 config/installed.lock 文件。');
}

$step = (int) ($_GET['step'] ?? 1);
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 1) {
        // 保存数据库配置
        $dbHost = trim($_POST['db_host'] ?? '127.0.0.1');
        $dbPort = (int) ($_POST['db_port'] ?? 3306);
        $dbName = trim($_POST['db_name'] ?? 'private_chat');
        $dbUser = trim($_POST['db_user'] ?? 'root');
        $dbPass = $_POST['db_pass'] ?? '';

        try {
            $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            // 创建数据库
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbName}`");

            // 导入 SQL
            $sql = file_get_contents(__DIR__ . '/sql/schema.sql');
            // 移除 CREATE DATABASE 和 USE 语句（已处理）
            $sql = preg_replace('/CREATE DATABASE.*?;/s', '', $sql);
            $sql = preg_replace('/USE.*?;/s', '', $sql);

            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($statements as $statement) {
                if ($statement !== '') {
                    $pdo->exec($statement);
                }
            }

            // 写入配置文件
            $configContent = "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n";
            $configContent .= "    'host' => " . var_export($dbHost, true) . ",\n";
            $configContent .= "    'port' => {$dbPort},\n";
            $configContent .= "    'dbname' => " . var_export($dbName, true) . ",\n";
            $configContent .= "    'username' => " . var_export($dbUser, true) . ",\n";
            $configContent .= "    'password' => " . var_export($dbPass, true) . ",\n";
            $configContent .= "    'charset' => 'utf8mb4',\n";
            $configContent .= "    'options' => [\n";
            $configContent .= "        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\n";
            $configContent .= "        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n";
            $configContent .= "        PDO::ATTR_EMULATE_PREPARES => false,\n";
            $configContent .= "    ],\n];\n";

            file_put_contents(__DIR__ . '/config/database.php', $configContent);

            // 创建目录
            foreach (['uploads', 'lt', 'logs'] as $dir) {
                $path = __DIR__ . '/' . $dir;
                if (!is_dir($path)) {
                    mkdir($path, 0755, true);
                }
            }

            // 创建锁定文件
            file_put_contents($lockFile, date('Y-m-d H:i:s'));

            $success = '安装成功！';
            $step = 2;
        } catch (PDOException $e) {
            $error = '数据库连接失败: ' . $e->getMessage();
        } catch (Exception $e) {
            $error = '安装失败: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>安装向导 - Private Chat Room</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, sans-serif; background: #0f0f13; color: #e8e8f0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .install-card { background: #1a1a24; border-radius: 12px; padding: 40px; max-width: 500px; width: 100%; border: 1px solid #2a2a3a; }
        h1 { font-size: 1.5rem; margin-bottom: 8px; }
        .subtitle { color: #9898a8; margin-bottom: 24px; }
        .form-group { margin-bottom: 16px; }
        label { display: block; margin-bottom: 6px; font-size: 0.9rem; color: #9898a8; }
        input { width: 100%; padding: 10px 14px; background: #252532; border: 1px solid #2a2a3a; border-radius: 8px; color: #e8e8f0; font-size: 1rem; }
        input:focus { outline: none; border-color: #6c5ce7; }
        .btn { width: 100%; padding: 12px; background: #6c5ce7; color: #fff; border: none; border-radius: 8px; font-size: 1rem; cursor: pointer; margin-top: 8px; }
        .btn:hover { background: #5a4bd1; }
        .error { background: rgba(225,112,85,0.15); color: #e17055; padding: 12px; border-radius: 8px; margin-bottom: 16px; }
        .success { background: rgba(0,184,148,0.15); color: #00b894; padding: 12px; border-radius: 8px; margin-bottom: 16px; }
        .info { background: #252532; padding: 16px; border-radius: 8px; margin-top: 16px; font-size: 0.9rem; }
        .info a { color: #6c5ce7; }
        ul { margin: 12px 0 0 20px; }
    </style>
</head>
<body>
    <div class="install-card">
        <h1>Private Chat Room</h1>
        <p class="subtitle">安装向导</p>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <form method="POST">
                <div class="form-group">
                    <label>数据库主机</label>
                    <input type="text" name="db_host" value="127.0.0.1" required>
                </div>
                <div class="form-group">
                    <label>端口</label>
                    <input type="number" name="db_port" value="3306" required>
                </div>
                <div class="form-group">
                    <label>数据库名</label>
                    <input type="text" name="db_name" value="private_chat" required>
                </div>
                <div class="form-group">
                    <label>用户名</label>
                    <input type="text" name="db_user" value="root" required>
                </div>
                <div class="form-group">
                    <label>密码</label>
                    <input type="password" name="db_pass" value="">
                </div>
                <button type="submit" class="btn">开始安装</button>
            </form>
        <?php else: ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
            <div class="info">
                <strong>安装完成，请进行以下操作：</strong>
                <ul>
                    <li>删除 <code>install.php</code> 文件</li>
                    <li>访问 <a href="/">首页</a> 开始使用</li>
                    <li>访问 <a href="/admin/">管理后台</a>（账号: admin / admin123）</li>
                    <li>请立即修改管理员默认密码</li>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
