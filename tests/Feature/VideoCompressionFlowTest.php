<?php

namespace Tests\Feature;

use App\Jobs\CompressVideo;
use App\Models\Video;
use App\Services\VideoCompressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VideoCompressionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_duplicate_compression_request_when_already_processing(): void
    {
        $video = Video::create([
            'uuid'              => 'test-uuid-duplicate',
            'original_filename' => 'test.mp4',
            'input_path'        => 'videos/input/test.mp4',
            'original_size'     => 10000000,
            'duration'          => 60.0,
            'source_width'      => 1920,
            'source_height'     => 1080,
            'status'            => 'processing',
        ]);

        $response = $this->postJson("/videos/{$video->uuid}/compress", [
            'target_mb'         => 20,
            'target_resolution' => 'original',
            'encoding_preset'   => 'medium',
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_rejects_infeasible_target_size_via_api(): void
    {
        $video = Video::create([
            'uuid'              => 'test-uuid-infeasible',
            'original_filename' => 'test.mp4',
            'input_path'        => 'videos/input/test.mp4',
            'original_size'     => 500000000,
            'duration'          => 7200.0, // 2 hours
            'source_width'      => 1920,
            'source_height'     => 1080,
            'has_audio'         => true,
            'status'            => 'uploaded',
        ]);

        // Attempting 1 MB for 2 hours video is completely infeasible
        $response = $this->postJson("/videos/{$video->uuid}/compress", [
            'target_mb'         => 1,
            'target_resolution' => 'original',
            'encoding_preset'   => 'medium',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_queues_compress_video_job_successfully(): void
    {
        Queue::fake();

        $video = Video::create([
            'uuid'              => 'test-uuid-queue',
            'original_filename' => 'test.mp4',
            'input_path'        => 'videos/input/test.mp4',
            'original_size'     => 10000000,
            'duration'          => 30.0,
            'source_width'      => 1280,
            'source_height'     => 720,
            'has_audio'         => true,
            'status'            => 'uploaded',
        ]);

        $response = $this->postJson("/videos/{$video->uuid}/compress", [
            'target_mb'         => 20,
            'target_resolution' => '720p',
            'encoding_preset'   => 'fast',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('videos', [
            'uuid'              => 'test-uuid-queue',
            'status'            => 'queued',
            'target_resolution' => '720p',
            'encoding_preset'   => 'fast',
        ]);

        Queue::assertPushed(CompressVideo::class, function ($job) use ($video) {
            return $job->video->id === $video->id;
        });
    }

    public function test_completed_job_keeps_progress_at_100_percent(): void
    {
        $video = Video::create([
            'uuid'              => 'test-uuid-progress-complete',
            'original_filename' => 'test.mp4',
            'input_path'        => 'videos/input/test.mp4',
            'original_size'     => 10000000,
            'duration'          => 30.0,
            'status'            => 'queued',
        ]);

        $compressionService = \Mockery::mock(VideoCompressionService::class);
        $compressionService->shouldReceive('compress')
            ->once()
            ->andReturnUsing(function (Video $video, callable $progressCallback): void {
                $video->update([
                    'status' => 'completed',
                    'processing_stage' => 'Completed',
                    'progress_percentage' => 100,
                ]);

                $progressCallback('Completed', 100);
            });

        (new CompressVideo($video))->handle($compressionService);

        $this->assertDatabaseHas('videos', [
            'uuid' => 'test-uuid-progress-complete',
            'status' => 'completed',
            'progress_percentage' => 100,
        ]);
    }

    public function test_status_polling_returns_accurate_metadata(): void
    {
        $video = Video::create([
            'uuid'                => 'test-uuid-status',
            'original_filename'   => 'test.mp4',
            'input_path'          => 'videos/input/test.mp4',
            'original_size'       => 40000000,
            'output_size'         => 20000000,
            'duration'            => 45.0,
            'status'              => 'completed',
            'processing_stage'    => 'Completed',
            'progress_percentage' => 100,
        ]);

        $response = $this->getJson("/videos/{$video->uuid}/status");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'video'   => [
                    'uuid'                => 'test-uuid-status',
                    'status'              => 'completed',
                    'progress_percentage' => 100,
                    'percentage_saved'    => 50.0,
                ],
            ]);
    }

    public function test_cannot_delete_video_while_actively_processing(): void
    {
        $video = Video::create([
            'uuid'              => 'test-uuid-delete-busy',
            'original_filename' => 'test.mp4',
            'input_path'        => 'videos/input/test.mp4',
            'original_size'     => 10000000,
            'status'            => 'processing',
        ]);

        $response = $this->deleteJson("/videos/{$video->uuid}");

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
            ]);

        $this->assertDatabaseHas('videos', ['uuid' => 'test-uuid-delete-busy']);
    }

    public function test_can_delete_video_when_not_processing(): void
    {
        $video = Video::create([
            'uuid'              => 'test-uuid-delete-safe',
            'original_filename' => 'test.mp4',
            'input_path'        => 'videos/input/test.mp4',
            'original_size'     => 10000000,
            'status'            => 'uploaded',
        ]);

        $response = $this->deleteJson("/videos/{$video->uuid}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('videos', ['uuid' => 'test-uuid-delete-safe']);
    }
}
