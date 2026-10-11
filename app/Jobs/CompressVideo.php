<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\VideoCompressionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class CompressVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    public Video $video;

    /**
     * Create a new job instance.
     */
    public function __construct(Video $video)
    {
        $this->video = $video;
        $this->onQueue(config('video.queue', 'video'));
    }

    /**
     * Execute the job.
     */
    public function handle(VideoCompressionService $compressionService): void
    {
        // Reload video from database to get freshest status
        $video = Video::find($this->video->id);
        if (!$video) {
            Log::warning("Video record {$this->video->id} no longer exists.");
            return;
        }

        // Prevent duplicate processing
        if ($video->status === 'processing') {
            Log::warning("Video {$video->uuid} is already being processed. Skipping duplicate job.");
            return;
        }

        if ($video->status === 'completed') {
            Log::info("Video {$video->uuid} is already completed.");
            return;
        }

        // Set status to processing
        $video->update([
            'status'              => 'processing',
            'processing_stage'    => 'Starting compression...',
            'progress_percentage' => 0,
            'started_at'          => now(),
            'error_message'       => null,
        ]);

        $lastLoggedPercent = -1;

        try {
            $compressionService->compress($video, function (string $stage, int $percent) use ($video, &$lastLoggedPercent) {
                // Ensure percentage remains below 100% until post-encode validation completes
                if ($percent >= 100) {
                    return;
                }

                $safePercent = max(0, $percent);

                if ($safePercent !== $lastLoggedPercent) {
                    $lastLoggedPercent = $safePercent;
                    $video->update([
                        'processing_stage'    => $stage,
                        'progress_percentage' => $safePercent,
                    ]);
                }
            });

            Log::info("Successfully compressed video {$video->uuid}");
        } catch (\Throwable $e) {
            Log::error("Failed to compress video {$video->uuid}: " . $e->getMessage());

            // Safely remove any incomplete output file
            if ($video->output_path) {
                $fullOutputPath = storage_path('app/' . $video->output_path);
                if (file_exists($fullOutputPath)) {
                    @unlink($fullOutputPath);
                }
            }

            $video->update([
                'status'              => 'failed',
                'processing_stage'    => 'Compression Failed',
                'error_message'       => $e->getMessage(),
                'progress_percentage' => 0,
            ]);

            throw $e;
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        $video = Video::find($this->video->id);
        if ($video && $video->status !== 'completed') {
            $video->update([
                'status'           => 'failed',
                'processing_stage' => 'Job Failed',
                'error_message'    => $exception ? $exception->getMessage() : 'Unexpected queue error.',
            ]);
        }
    }
}
