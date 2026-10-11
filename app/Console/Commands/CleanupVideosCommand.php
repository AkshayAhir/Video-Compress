<?php

namespace App\Console\Commands;

use App\Models\UploadSession;
use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class CleanupVideosCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'video:clean-temp {--force : Force cleanup without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up expired upload sessions, stale temporary encoding logs, and expired completed videos';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting VideoShrink cleanup routine...');

        // 1. Clean up expired upload sessions and chunk folders
        $expiredSessions = UploadSession::where('expires_at', '<=', now())
            ->where('status', '!=', 'assembling')
            ->get();

        $cleanedSessionsCount = 0;
        foreach ($expiredSessions as $session) {
            if ($session->chunk_dir && File::isDirectory($session->chunk_dir)) {
                File::deleteDirectory($session->chunk_dir);
            }
            $session->delete();
            $cleanedSessionsCount++;
        }
        $this->line("Cleaned {$cleanedSessionsCount} expired upload sessions.");

        // 2. Clean up stale pass log folders older than retention hours
        $passLogRoot = storage_path('app/temp/passlogs');
        $cleanedPassLogsCount = 0;
        if (File::isDirectory($passLogRoot)) {
            $cutoff = now()->subHours(config('video.temp_upload_retention_hours', 24))->timestamp;
            foreach (File::directories($passLogRoot) as $dir) {
                if (filemtime($dir) < $cutoff) {
                    File::deleteDirectory($dir);
                    $cleanedPassLogsCount++;
                }
            }
        }
        $this->line("Cleaned {$cleanedPassLogsCount} stale pass log directories.");

        // 3. Clean up expired completed videos if retention days is set
        $retentionDays = config('video.completed_retention_days');
        $cleanedVideosCount = 0;

        if ($retentionDays !== null && $retentionDays > 0) {
            $expiredVideos = Video::where('status', 'completed')
                ->where('completed_at', '<=', now()->subDays($retentionDays))
                ->get();

            foreach ($expiredVideos as $video) {
                // Remove input file
                if ($video->input_path) {
                    $in = storage_path('app/' . $video->input_path);
                    if (file_exists($in)) {
                        @unlink($in);
                    }
                }
                // Remove output file
                if ($video->output_path) {
                    $out = storage_path('app/' . $video->output_path);
                    if (file_exists($out)) {
                        @unlink($out);
                    }
                }
                $video->delete();
                $cleanedVideosCount++;
            }
            $this->line("Cleaned {$cleanedVideosCount} completed videos older than {$retentionDays} days.");
        } else {
            $this->line("Completed video auto-deletion is disabled.");
        }

        $this->info('VideoShrink cleanup completed successfully.');
        return Command::SUCCESS;
    }
}

