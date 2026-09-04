<?php
/**
 * CLI：处理视频压缩与上传任务
 *
 * 用法:
 *   php tools/process_video.php 123        # 处理指定 job
 *   php tools/process_video.php --pending  # 处理队列中 pending 任务（可放 cron）
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

use App\Models\VideoJob;
use App\Services\VideoProcessService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$arg = $argv[1] ?? '';

if ($arg === '--pending') {
    $jobs = VideoJob::findPending(20);
    foreach ($jobs as $job) {
        VideoProcessService::processJob((int) $job['id']);
    }
    exit(0);
}

$jobId = (int) $arg;
if ($jobId > 0) {
    VideoProcessService::processJob($jobId);
}
