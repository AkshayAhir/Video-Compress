<?php

namespace App\Services;

use App\Exceptions\InfeasibleTargetException;
use App\Exceptions\VideoValidationException;
use App\Models\Video;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class VideoCompressionService
{
    protected string $ffmpegBinary;
    protected FFprobeService $ffprobeService;

    // Safety margin for MP4 container overhead (moov atom, indexes, packet headers)
    public const CONTAINER_OVERHEAD_MARGIN = 0.04; // 4%
    public const MIN_VIDEO_BITRATE_KBPS = 64;
    public const MAX_ATTEMPTS = 3;

    public function __construct(?string $binary = null, ?FFprobeService $ffprobe = null)
    {
        $this->ffmpegBinary = $binary ?: config('video.ffmpeg_binary', 'ffmpeg');
        $this->ffprobeService = $ffprobe ?: new FFprobeService();
    }

    /**
     * Calculate target bitrates in kbps from target MB and duration.
     * Convention: 1 MB = 1,000,000 bytes.
     *
     * @param int $targetBytes Target size in bytes (1 MB = 1,000,000 bytes)
     * @param float $duration Duration in seconds
     * @param bool $hasAudio Whether source has an audio stream
     * @return array [video_bitrate_kbps, audio_bitrate_kbps, total_bitrate_kbps]
     * @throws InfeasibleTargetException
     */
    public function calculateBitrates(int $targetBytes, float $duration, bool $hasAudio): array
    {
        if ($duration <= 0.0) {
            throw new InfeasibleTargetException('Invalid duration: video duration must be greater than zero.');
        }

        if ($targetBytes <= 0) {
            throw new InfeasibleTargetException('Target size must be greater than zero.');
        }

        // Available bytes after reserving container overhead margin
        $availableBytes = $targetBytes * (1.0 - self::CONTAINER_OVERHEAD_MARGIN);
        $availableBits = $availableBytes * 8.0;

        // Total available bitrate in kilobits per second (1 kbps = 1000 bps)
        $totalBitrateKbps = ($availableBits / $duration) / 1000.0;

        // Allocate audio bitrate
        $audioBitrateKbps = 0;
        if ($hasAudio) {
            if ($totalBitrateKbps >= 1500) {
                $audioBitrateKbps = 128;
            } elseif ($totalBitrateKbps >= 800) {
                $audioBitrateKbps = 96;
            } elseif ($totalBitrateKbps >= 400) {
                $audioBitrateKbps = 64;
            } elseif ($totalBitrateKbps >= 160) {
                $audioBitrateKbps = 48;
            } else {
                $audioBitrateKbps = 32;
            }
        }

        $videoBitrateKbps = (int) floor($totalBitrateKbps - $audioBitrateKbps);

        if ($videoBitrateKbps < self::MIN_VIDEO_BITRATE_KBPS) {
            $minAudio = $hasAudio ? 32 : 0;
            $minTotalKbps = self::MIN_VIDEO_BITRATE_KBPS + $minAudio;
            $minRequiredBytes = ($minTotalKbps * 1000 * $duration / 8) / (1.0 - self::CONTAINER_OVERHEAD_MARGIN);
            $minRequiredMb = ceil($minRequiredBytes / 1000000.0);
            $targetMb = round($targetBytes / 1000000.0, 1);

            throw new InfeasibleTargetException(
                "Target size of {$targetMb} MB is too small for a {$duration}s video. Minimum viable target is {$minRequiredMb} MB."
            );
        }

        return [
            'video_bitrate_kbps' => $videoBitrateKbps,
            'audio_bitrate_kbps' => $audioBitrateKbps,
            'total_bitrate_kbps' => (int) floor($totalBitrateKbps),
        ];
    }

    /**
     * Build the FFmpeg video filter (-vf) for target resolution, preserving aspect ratio
     * without upscaling, and ensuring width and height are divisible by 2.
     *
     * @param string $targetResolution 'original', '1080p', '720p', '480p'
     * @param int $sourceWidth
     * @param int $sourceHeight
     * @return string
     */
    public function buildVideoFilter(string $targetResolution, int $sourceWidth, int $sourceHeight): string
    {
        if ($targetResolution === 'original' || empty($targetResolution)) {
            // Keep original resolution but ensure dimensions are divisible by 2 for H.264
            return 'scale=trunc(iw/2)*2:trunc(ih/2)*2';
        }

        $isPortrait = $sourceHeight > $sourceWidth;

        // Bounding box dimensions for resolution presets
        $maxLongEdge = match ($targetResolution) {
            '1080p' => 1920,
            '720p'  => 1280,
            '480p'  => 854,
            default => 1920,
        };

        $maxShortEdge = match ($targetResolution) {
            '1080p' => 1080,
            '720p'  => 720,
            '480p'  => 480,
            default => 1080,
        };

        $boxW = $isPortrait ? $maxShortEdge : $maxLongEdge;
        $boxH = $isPortrait ? $maxLongEdge : $maxShortEdge;

        // Do not upscale: use min(box, current_dim)
        // force_original_aspect_ratio=decrease scales within box, trunc ensures divisible by 2
        return "scale='min({$boxW},iw)':'min({$boxH},ih)':force_original_aspect_ratio=decrease,scale=trunc(iw/2)*2:trunc(ih/2)*2";
    }

    /**
     * Compress the given Video model.
     *
     * @param Video $video
     * @param callable|null $progressCallback function(string $stage, int $percentage): void
     * @return void
     * @throws \Throwable
     */
    public function compress(Video $video, ?callable $progressCallback = null): void
    {
        $inputPath = storage_path('app/' . $video->input_path);
        if (!file_exists($inputPath)) {
            throw new VideoValidationException("Input video file not found at: {$inputPath}");
        }

        // Probe source metadata if missing
        if (!$video->duration || !$video->source_width || !$video->source_height) {
            $probe = $this->ffprobeService->probe($inputPath);
            $video->update([
                'duration'      => $probe['duration'],
                'source_width'  => $probe['width'],
                'source_height' => $probe['height'],
                'source_format' => $probe['format'],
                'source_codec'  => $probe['codec'],
                'source_fps'    => $probe['fps'],
                'has_audio'     => $probe['has_audio'],
            ]);
        }

        $duration = (float) $video->duration;
        $targetBytes = (int) $video->target_size;
        $hasAudio = (bool) $video->has_audio;
        $preset = $video->encoding_preset ?: 'medium';
        $targetResolution = $video->target_resolution ?: 'original';

        // Check if source already satisfies target size and no downscaling requested
        if ($targetBytes > 0 && $video->original_size <= $targetBytes && $targetResolution === 'original') {
            Log::info("Video {$video->uuid} already fits target size ({$video->original_size} <= {$targetBytes}). Skipping re-encoding.");
            $this->copyAsCompleted($video, $inputPath, $progressCallback);
            return;
        }

        // Prepare output path
        $outputDir = storage_path('app/videos/output');
        File::ensureDirectoryExists($outputDir);
        $outputPath = $outputDir . DIRECTORY_SEPARATOR . $video->uuid . '.mp4';
        $video->update(['output_path' => 'videos/output/' . $video->uuid . '.mp4']);

        // Calculate initial bitrates
        $bitrates = $this->calculateBitrates($targetBytes, $duration, $hasAudio);
        $videoBitrateKbps = $bitrates['video_bitrate_kbps'];
        $audioBitrateKbps = $bitrates['audio_bitrate_kbps'];

        $filter = $this->buildVideoFilter($targetResolution, $video->source_width, $video->source_height);
        $nullDevice = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'NUL' : '/dev/null';

        $attempt = 1;
        $success = false;
        $lastError = null;

        while ($attempt <= self::MAX_ATTEMPTS && !$success) {
            $video->update([
                'attempt_count' => $attempt,
                'processing_stage' => "Attempt {$attempt}: Pass 1 (analysis)",
                'progress_percentage' => 0,
            ]);

            // Unique pass log directory and prefix for this attempt
            $passLogDir = storage_path('app/temp/passlogs/' . $video->uuid . '_a' . $attempt);
            File::ensureDirectoryExists($passLogDir);
            $passLogPrefix = $passLogDir . DIRECTORY_SEPARATOR . 'ffmpeg2pass';

            try {
                // PASS 1
                if ($progressCallback) {
                    $progressCallback("Attempt {$attempt}: Pass 1 (analysis)", 5);
                }

                $pass1Args = [
                    $this->ffmpegBinary,
                    '-y',
                    '-i', $inputPath,
                    '-c:v', 'libx264',
                    '-b:v', "{$videoBitrateKbps}k",
                    '-pass', '1',
                    '-passlogfile', $passLogPrefix,
                    '-preset', $preset,
                    '-vf', $filter,
                    '-pix_fmt', 'yuv420p',
                    '-an',
                    '-f', 'null',
                    $nullDevice,
                ];

                $this->runFfmpegWithProgress(
                    $pass1Args,
                    $duration,
                    0,
                    45,
                    "Attempt {$attempt}: Pass 1 (analysis)",
                    $progressCallback
                );

                // PASS 2
                $video->update(['processing_stage' => "Attempt {$attempt}: Pass 2 (encoding)"]);
                if ($progressCallback) {
                    $progressCallback("Attempt {$attempt}: Pass 2 (encoding)", 45);
                }

                $pass2Args = [
                    $this->ffmpegBinary,
                    '-y',
                    '-i', $inputPath,
                    '-c:v', 'libx264',
                    '-b:v', "{$videoBitrateKbps}k",
                    '-pass', '2',
                    '-passlogfile', $passLogPrefix,
                    '-preset', $preset,
                    '-vf', $filter,
                    '-pix_fmt', 'yuv420p',
                ];

                if ($hasAudio && $audioBitrateKbps > 0) {
                    $pass2Args = array_merge($pass2Args, [
                        '-c:a', 'aac',
                        '-b:a', "{$audioBitrateKbps}k",
                    ]);
                } else {
                    $pass2Args[] = '-an';
                }

                $pass2Args = array_merge($pass2Args, [
                    '-movflags', '+faststart',
                    $outputPath,
                ]);

                $this->runFfmpegWithProgress(
                    $pass2Args,
                    $duration,
                    45,
                    90,
                    "Attempt {$attempt}: Pass 2 (encoding)",
                    $progressCallback
                );

                // VERIFICATION
                $video->update(['processing_stage' => "Verifying compressed output", 'progress_percentage' => 92]);
                if ($progressCallback) {
                    $progressCallback("Verifying compressed output", 92);
                }

                if (!file_exists($outputPath) || filesize($outputPath) === 0) {
                    throw new VideoValidationException("Output MP4 was not created or is empty.");
                }

                $outputProbe = $this->ffprobeService->probe($outputPath);
                $actualOutputBytes = filesize($outputPath);

                // Check duration preservation within 3-second or 5% tolerance
                $durationTolerance = max(3.0, $duration * 0.05);
                if (abs($outputProbe['duration'] - $duration) > $durationTolerance) {
                    throw new VideoValidationException(
                        "Output duration mismatch: expected {$duration}s, got {$outputProbe['duration']}s."
                    );
                }

                // Check audio stream presence if source had audio
                if ($hasAudio && !$outputProbe['has_audio']) {
                    throw new VideoValidationException("Audio stream was lost in output video.");
                }

                // Check output size against target size limit
                if ($actualOutputBytes <= $targetBytes) {
                    // Success!
                    $success = true;
                    $video->update([
                        'output_size' => $actualOutputBytes,
                        'status' => 'completed',
                        'processing_stage' => 'Completed',
                        'progress_percentage' => 100,
                        'completed_at' => now(),
                        'error_message' => null,
                    ]);

                    if ($progressCallback) {
                        $progressCallback('Completed', 100);
                    }
                } else {
                    // Overshot target size: adjust video bitrate and retry if attempts remain
                    $overshootRatio = $actualOutputBytes / $targetBytes;
                    $overshootMb = round($actualOutputBytes / 1000000.0, 2);
                    $targetMb = round($targetBytes / 1000000.0, 2);

                    Log::warning("Output size {$overshootMb} MB exceeded target {$targetMb} MB (attempt {$attempt}/" . self::MAX_ATTEMPTS . ")");

                    if ($attempt < self::MAX_ATTEMPTS) {
                        // Reduce bitrate with safety multiplier
                        $reductionFactor = (1.0 / $overshootRatio) * 0.95;
                        $videoBitrateKbps = (int) floor($videoBitrateKbps * $reductionFactor);

                        if ($videoBitrateKbps < self::MIN_VIDEO_BITRATE_KBPS) {
                            throw new InfeasibleTargetException(
                                "Cannot further reduce video bitrate below " . self::MIN_VIDEO_BITRATE_KBPS . " kbps."
                            );
                        }

                        // Remove failed output before retrying
                        if (file_exists($outputPath)) {
                            @unlink($outputPath);
                        }
                    } else {
                        // Final attempt still exceeded target
                        throw new VideoValidationException(
                            "Output size ({$overshootMb} MB) exceeded target limit ({$targetMb} MB) after " . self::MAX_ATTEMPTS . " encoding attempts."
                        );
                    }
                }

            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                Log::error("Compression error on attempt {$attempt}: " . $lastError);
                if ($attempt >= self::MAX_ATTEMPTS || $e instanceof InfeasibleTargetException) {
                    throw $e;
                }
            } finally {
                // Safely remove pass logs for this attempt
                if (File::isDirectory($passLogDir)) {
                    File::deleteDirectory($passLogDir);
                }
            }

            $attempt++;
        }

        if (!$success) {
            throw new VideoValidationException($lastError ?: "Failed to compress video within target constraints.");
        }
    }

    /**
     * Copy input directly to output when video already fits target constraints.
     */
    protected function copyAsCompleted(Video $video, string $inputPath, ?callable $progressCallback): void
    {
        $outputDir = storage_path('app/videos/output');
        File::ensureDirectoryExists($outputDir);
        $outputPath = $outputDir . DIRECTORY_SEPARATOR . $video->uuid . '.mp4';

        if ($progressCallback) {
            $progressCallback("Verifying original file", 50);
        }

        File::copy($inputPath, $outputPath);

        $video->update([
            'output_path'         => 'videos/output/' . $video->uuid . '.mp4',
            'output_size'         => filesize($outputPath),
            'status'              => 'completed',
            'processing_stage'    => 'Completed (Source fits target)',
            'progress_percentage' => 100,
            'completed_at'        => now(),
            'error_message'       => null,
        ]);

        if ($progressCallback) {
            $progressCallback('Completed', 100);
        }
    }

    /**
     * Run an FFmpeg process and report progress via callback.
     */
    protected function runFfmpegWithProgress(
        array $command,
        float $totalDuration,
        int $stageStartPercent,
        int $stageEndPercent,
        string $stageName,
        ?callable $progressCallback
    ): void {
        // Append machine-readable progress output to pipe 1
        $cmd = array_merge($command, ['-progress', 'pipe:1']);

        $process = new Process($cmd);
        $process->setTimeout(config('video.max_processing_time', 3600));

        $lastUpdate = microtime(true);
        $stageSpan = $stageEndPercent - $stageStartPercent;

        $process->run(function ($type, $buffer) use (
            $totalDuration,
            $stageStartPercent,
            $stageSpan,
            $stageName,
            $progressCallback,
            &$lastUpdate
        ) {
            if ($type === Process::OUT && $totalDuration > 0) {
                // Parse out_time_us=... or out_time_ms=...
                if (preg_match('/out_time_us=(\d+)/', $buffer, $matches)) {
                    $currentTimeSeconds = ((float) $matches[1]) / 1000000.0;
                    $fraction = min(1.0, max(0.0, $currentTimeSeconds / $totalDuration));
                    $currentPercent = $stageStartPercent + (int) round($fraction * $stageSpan);

                    $now = microtime(true);
                    if ($now - $lastUpdate >= 1.0) { // Throttle updates to at most once per second
                        $lastUpdate = $now;
                        if ($progressCallback) {
                            $progressCallback($stageName, min(99, $currentPercent));
                        }
                    }
                }
            }
        });

        if (!$process->isSuccessful()) {
            $err = trim($process->getErrorOutput());
            // Filter down to meaningful error lines
            $lines = array_filter(explode("\n", $err), fn($l) => !empty(trim($l)));
            $tail = implode(' ', array_slice($lines, -5));
            throw new VideoValidationException("FFmpeg encoding failed: " . ($tail ?: 'Unknown error.'));
        }
    }
}

