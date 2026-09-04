<?php
/**
 * 视频压缩服务（FFmpeg，类似微信发送前压缩）
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;

class VideoCompressionService
{
    private array $config;
    private ?string $ffmpeg;
    private ?string $ffprobe;

    public function __construct(?array $config = null)
    {
        $appConfig = require ROOT_PATH . '/config/app.php';
        $this->config = $config ?? ($appConfig['video_compress'] ?? []);
        $this->ffmpeg = $this->resolveBinary('ffmpeg_path', 'ffmpeg');
        $this->ffprobe = $this->resolveBinary('ffprobe_path', 'ffprobe');
    }

    public function isAvailable(): bool
    {
        return !empty($this->config['enabled']) && $this->ffmpeg !== null && $this->ffprobe !== null;
    }

    /**
     * 是否应对该文件执行压缩
     */
    public function shouldCompress(int $fileSize): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        $minSize = (int) ($this->config['min_size_to_compress'] ?? 10 * 1024 * 1024);
        return $fileSize >= $minSize;
    }

    /**
     * 压缩视频，返回临时文件信息；失败则返回 null（调用方使用原文件）
     *
     * @return array{path: string, size: int, mime: string, cleanup: bool}|null
     */
    public function compress(string $inputPath): ?array
    {
        if (!$this->isAvailable() || !is_readable($inputPath)) {
            return null;
        }

        $targetBytes = (int) ($this->config['target_max_bytes'] ?? 10 * 1024 * 1024);
        $maxHeight = (int) ($this->config['max_height'] ?? 720);
        $preset = (string) ($this->config['preset'] ?? 'medium');
        $crf = (int) ($this->config['crf'] ?? 28);

        $outputPath = sys_get_temp_dir() . '/pcr_vid_' . uniqid('', true) . '.mp4';

        if (!$this->runEncode($inputPath, $outputPath, $maxHeight, $crf, $preset)) {
            @unlink($outputPath);
            return null;
        }

        $size = filesize($outputPath);
        if ($size === false || $size === 0) {
            @unlink($outputPath);
            return null;
        }

        // 仍超过目标则二次压缩（提高 CRF、降低分辨率）
        if ($size > (int) ($targetBytes * 1.15)) {
            $retryPath = sys_get_temp_dir() . '/pcr_vid_' . uniqid('', true) . '.mp4';
            $retryHeight = min(480, $maxHeight);
            $retryCrf = min(34, $crf + 6);

            if ($this->runEncode($inputPath, $retryPath, $retryHeight, $retryCrf, 'fast')) {
                $retrySize = filesize($retryPath);
                if ($retrySize !== false && $retrySize > 0 && $retrySize < $size) {
                    @unlink($outputPath);
                    $outputPath = $retryPath;
                    $size = $retrySize;
                } else {
                    @unlink($retryPath);
                }
            } else {
                @unlink($retryPath);
            }
        }

        // 压缩后反而更大则放弃
        $originalSize = filesize($inputPath);
        if ($originalSize !== false && $size >= $originalSize) {
            @unlink($outputPath);
            Logger::info('Video compress skipped: output not smaller', [
                'original' => $originalSize,
                'output' => $size,
            ]);
            return null;
        }

        Logger::info('Video compressed', [
            'original_mb' => round($originalSize / 1024 / 1024, 2),
            'output_mb' => round($size / 1024 / 1024, 2),
        ]);

        return [
            'path' => $outputPath,
            'size' => $size,
            'mime' => 'video/mp4',
            'cleanup' => true,
        ];
    }

    /**
     * 提取视频封面图（JPEG）
     *
     * @return array{path: string, mime: string, cleanup: bool}|null
     */
    public function extractPoster(string $inputPath): ?array
    {
        if ($this->ffmpeg === null || !is_readable($inputPath)) {
            return null;
        }

        $posterPath = sys_get_temp_dir() . '/pcr_poster_' . uniqid('', true) . '.jpg';
        $seek = escapeshellarg((string) ($this->config['poster_seek'] ?? '0.5'));
        $scale = (int) ($this->config['poster_width'] ?? 480);

        $cmd = sprintf(
            '%s -y -ss %s -i %s -vframes 1 -vf %s -q:v 4 %s 2>&1',
            escapeshellcmd($this->ffmpeg),
            $seek,
            escapeshellarg($inputPath),
            escapeshellarg("scale={$scale}:-2"),
            escapeshellarg($posterPath)
        );

        exec($cmd, $output, $code);

        if ($code !== 0 || !is_file($posterPath)) {
            @unlink($posterPath);
            return null;
        }

        return [
            'path' => $posterPath,
            'mime' => 'image/jpeg',
            'cleanup' => true,
        ];
    }

    private function runEncode(
        string $inputPath,
        string $outputPath,
        int $maxHeight,
        int $crf,
        string $preset
    ): bool {
        $duration = $this->getDuration($inputPath);
        $targetBytes = (int) ($this->config['target_max_bytes'] ?? 10 * 1024 * 1024);

        $vf = sprintf("scale=-2:'min(%d,ih)'", $maxHeight);

        // 有时长则按目标体积估算码率，更接近微信 60MB→8MB 的效果
        if ($duration !== null && $duration > 0) {
            $audioK = (int) ($this->config['audio_bitrate_k'] ?? 96);
            $audioBits = $audioK * 1000;
            $videoBits = (int) max(
                400000,
                (($targetBytes * 8) / $duration) - $audioBits
            );
            $videoK = (int) min(2500, max(400, $videoBits / 1000));
            $bufK = $videoK * 2;

            $cmd = sprintf(
                '%s -y -i %s -vf %s -c:v libx264 -b:v %dk -maxrate %dk -bufsize %dk -preset %s -profile:v main -c:a aac -b:a %dk -movflags +faststart %s 2>&1',
                escapeshellcmd($this->ffmpeg),
                escapeshellarg($inputPath),
                escapeshellarg($vf),
                $videoK,
                $videoK,
                $bufK,
                escapeshellarg($preset),
                $audioK,
                escapeshellarg($outputPath)
            );
        } else {
            $cmd = sprintf(
                '%s -y -i %s -vf %s -c:v libx264 -crf %d -preset %s -profile:v main -c:a aac -b:a 96k -movflags +faststart %s 2>&1',
                escapeshellcmd($this->ffmpeg),
                escapeshellarg($inputPath),
                escapeshellarg($vf),
                $crf,
                escapeshellarg($preset),
                escapeshellarg($outputPath)
            );
        }

        exec($cmd, $output, $code);

        if ($code !== 0) {
            Logger::warning('FFmpeg encode failed', [
                'code' => $code,
                'output' => implode("\n", array_slice($output, -8)),
            ]);
            return false;
        }

        return is_file($outputPath);
    }

    private function getDuration(string $inputPath): ?float
    {
        if ($this->ffprobe === null) {
            return null;
        }

        $cmd = sprintf(
            '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null',
            escapeshellcmd($this->ffprobe),
            escapeshellarg($inputPath)
        );

        $out = shell_exec($cmd);
        if ($out === null || trim($out) === '') {
            return null;
        }

        $duration = (float) trim($out);
        return $duration > 0 ? $duration : null;
    }

    private function resolveBinary(string $configKey, string $fallback): ?string
    {
        $path = trim((string) ($this->config[$configKey] ?? ''));
        if ($path === '') {
            $path = $fallback;
        }

        $checkCmd = sprintf('%s -version 2>&1', escapeshellcmd($path));
        exec($checkCmd, $output, $code);

        return $code === 0 ? $path : null;
    }

    public static function cleanupTemp(?array $fileInfo): void
    {
        if ($fileInfo === null || empty($fileInfo['cleanup']) || empty($fileInfo['path'])) {
            return;
        }
        if (is_file($fileInfo['path'])) {
            @unlink($fileInfo['path']);
        }
    }
}
