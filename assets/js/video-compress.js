/**
 * 浏览器端视频压缩（发送前压缩，减轻服务器压力）
 */
(function (global) {
    'use strict';

    const DEFAULTS = {
        enabled: true,
        minSizeToCompress: 8 * 1024 * 1024,
        targetMaxBytes: 10 * 1024 * 1024,
        maxHeight: 720,
        audioBitrate: 96000,
    };

    function pickMimeType() {
        if (typeof MediaRecorder === 'undefined') {
            return '';
        }
        const candidates = [
            'video/webm;codecs=vp9',
            'video/webm;codecs=vp8',
            'video/webm',
            'video/mp4;codecs=avc1',
            'video/mp4',
        ];
        for (const type of candidates) {
            if (MediaRecorder.isTypeSupported(type)) {
                return type;
            }
        }
        return '';
    }

    function extFromMime(mime) {
        return mime.includes('mp4') ? 'mp4' : 'webm';
    }

    function calcVideoBitrate(durationSec, targetBytes, audioBitrate) {
        const totalBps = Math.floor((targetBytes * 8) / Math.max(durationSec, 1));
        return Math.max(400000, Math.min(2500000, totalBps - audioBitrate));
    }

    function loadVideo(file) {
        const url = URL.createObjectURL(file);
        const video = document.createElement('video');
        video.playsInline = true;
        video.preload = 'auto';
        video.src = url;

        return new Promise((resolve, reject) => {
            video.onloadedmetadata = () => resolve({ video, url });
            video.onerror = () => {
                URL.revokeObjectURL(url);
                reject(new Error('无法读取视频文件'));
            };
        });
    }

    function cleanupVideo(video, url) {
        URL.revokeObjectURL(url);
        video.pause();
        video.removeAttribute('src');
        video.load();
    }

    /**
     * 播放视频并录制输出流
     */
    async function recordWhilePlaying(video, stream, mimeType, videoBps, audioBitrate, onProgress) {
        const duration = video.duration;

        return new Promise((resolve, reject) => {
            const chunks = [];
            let recorder;

            try {
                recorder = new MediaRecorder(stream, {
                    mimeType,
                    videoBitsPerSecond: videoBps,
                    audioBitsPerSecond: audioBitrate,
                });
            } catch (e) {
                reject(new Error('当前浏览器不支持视频压缩'));
                return;
            }

            const finish = () => {
                stream.getTracks().forEach((t) => t.stop());
                if (recorder.state === 'recording') {
                    try { recorder.stop(); } catch (e) { /* ignore */ }
                }
            };

            recorder.ondataavailable = (e) => {
                if (e.data && e.data.size > 0) chunks.push(e.data);
            };

            recorder.onstop = () => resolve(new Blob(chunks, { type: mimeType }));

            recorder.onerror = () => {
                finish();
                reject(new Error('视频压缩中断'));
            };

            recorder.start(400);

            video.onended = () => {
                if (onProgress) onProgress(99);
                finish();
            };

            video.onerror = () => {
                finish();
                reject(new Error('视频播放失败'));
            };

            video.ontimeupdate = () => {
                if (onProgress && duration > 0) {
                    onProgress(Math.min(99, Math.round((video.currentTime / duration) * 100)));
                }
            };

            video.play().catch(() => {
                video.muted = true;
                return video.play();
            }).catch(() => {
                finish();
                reject(new Error('无法播放视频，压缩已取消'));
            });
        });
    }

    async function buildStream(video, opts) {
        const needResize = video.videoHeight > opts.maxHeight;

        if (!needResize && video.captureStream) {
            return video.captureStream();
        }

        const scale = needResize ? opts.maxHeight / video.videoHeight : 1;
        const w = Math.max(2, Math.round(video.videoWidth * scale) & ~1);
        const h = Math.max(2, Math.round(video.videoHeight * scale) & ~1);

        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d', { alpha: false });
        if (!ctx) {
            throw new Error('无法创建画布');
        }

        const canvasStream = canvas.captureStream(30);
        const tracks = [...canvasStream.getVideoTracks()];

        if (video.captureStream) {
            video.captureStream().getAudioTracks().forEach((t) => tracks.push(t));
        }

        const draw = () => {
            if (video.ended) return;
            ctx.drawImage(video, 0, 0, w, h);
            if (!video.paused && !video.ended) {
                requestAnimationFrame(draw);
            }
        };

        video.addEventListener('play', () => requestAnimationFrame(draw), { once: true });

        return new MediaStream(tracks);
    }

    async function compress(file, userOpts, onProgress) {
        const raw = userOpts || {};
        const opts = {
            enabled: raw.enabled ?? DEFAULTS.enabled,
            minSizeToCompress: raw.min_size_to_compress ?? raw.minSizeToCompress ?? DEFAULTS.minSizeToCompress,
            targetMaxBytes: raw.target_max_bytes ?? raw.targetMaxBytes ?? DEFAULTS.targetMaxBytes,
            maxHeight: raw.max_height ?? raw.maxHeight ?? DEFAULTS.maxHeight,
            audioBitrate: raw.audio_bitrate ?? raw.audioBitrate ?? DEFAULTS.audioBitrate,
        };

        if (!opts.enabled || typeof MediaRecorder === 'undefined') {
            return { file, compressed: false };
        }

        if (!file || file.size < opts.minSizeToCompress) {
            return { file, compressed: false };
        }

        const mimeType = pickMimeType();
        if (!mimeType) {
            return { file, compressed: false };
        }

        let video;
        let url;

        try {
            ({ video, url } = await loadVideo(file));

            const duration = video.duration;
            if (!duration || !isFinite(duration) || duration <= 0) {
                cleanupVideo(video, url);
                return { file, compressed: false };
            }

            if (onProgress) onProgress(0);

            video.currentTime = 0;
            const stream = await buildStream(video, opts);
            const videoBps = calcVideoBitrate(duration, opts.targetMaxBytes, opts.audioBitrate);

            const blob = await recordWhilePlaying(
                video,
                stream,
                mimeType,
                videoBps,
                opts.audioBitrate,
                onProgress
            );

            cleanupVideo(video, url);

            if (!blob || blob.size === 0 || blob.size >= file.size) {
                return { file, compressed: false, originalSize: file.size };
            }

            const ext = extFromMime(mimeType);
            const baseName = (file.name || 'video').replace(/\.[^.]+$/, '') || 'video';
            const outFile = new File([blob], `${baseName}.${ext}`, {
                type: mimeType,
                lastModified: Date.now(),
            });

            if (onProgress) onProgress(100);

            return {
                file: outFile,
                compressed: true,
                originalSize: file.size,
            };
        } catch (err) {
            if (video && url) cleanupVideo(video, url);
            console.warn('[VideoCompress]', err);
            return { file, compressed: false, originalSize: file.size, error: err.message };
        }
    }

    global.VideoCompress = { compress };
})(window);
