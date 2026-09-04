<?php
/**
 * 应用引导文件 - 加载配置、自动加载、初始化会话
 */

declare(strict_types=1);

// 项目根目录
define('ROOT_PATH', dirname(__DIR__));

// 加载配置
$appConfig = require ROOT_PATH . '/config/app.php';
$dbConfig = require ROOT_PATH . '/config/database.php';

// 时区设置
date_default_timezone_set($appConfig['timezone']);

// 错误报告（生产环境关闭）
if ($appConfig['debug']) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
}

// 确保必要目录存在
foreach (['uploads', 'uploads/pending/videos', 'lt', 'logs'] as $dir) {
    $path = ROOT_PATH . '/' . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
}

if (file_exists(ROOT_PATH . '/vendor/autoload.php')) {
    require_once ROOT_PATH . '/vendor/autoload.php';
}

// 自动加载
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = ROOT_PATH . '/includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// 初始化会话
App\Core\Session::init($appConfig);
