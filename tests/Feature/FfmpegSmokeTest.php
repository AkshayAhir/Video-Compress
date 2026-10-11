<?php

namespace Tests\Feature;

use App\Models\Video;
use App\Services\FFprobeService;
use App\Services\VideoCompressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class FfmpegSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = storage_path('app/temp/smoke_test');
        File::ensureDirectoryExists($this->testDir);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->testDir)) {
            File::deleteDirectory($this->testDir);
        }
        parent::tearDown();
    }

    public function test_ffmpeg_and_ffprobe_synthetic_video_smoke_test(): void
    {
        $ffmpegBinary = config('video.ffmpeg_binary');
        $ffprobeBinary = config('video.ffprobe_binary');

        if (!file_exists($ffmpegBinary) || !file_exists($ffprobeBinary)) {
            $this->markTestSkipped("FFmpeg/FFprobe binaries not found at configured paths.");
        }

        $sourceFile = $this->testDir . DIRECTORY_SEPARATOR . 'synthetic_source.mp4';

        // 1. Generate 5-second synthetic video with audio using FFmpeg
        $genProcess = new Process([
            $ffmpegBinary,
            '-y',
            '-f', 'lavfi', '-i', 'testsrc=duration=5:size=640x360:rate=25',
            '-f', 'lavfi', '-i', 'sine=frequency=1000:duration=5',
            '-c:v', 'libx264',
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac',
            $sourceFile,
        ]);

        $genProcess->setTimeout(30);
        $genProcess->run();

        $this->assertTrue($genProcess->isSuccessful(), "FFmpeg failed to create synthetic source: " . $genProcess->getErrorOutput());
        $this->assertFileExists($sourceFile);

        // 2. Probe source using FFprobeService
        $ffprobeService = new FFprobeService($ffprobeBinary);
        $probe = $ffprobeService->probe($sourceFile);

        $this->assertEquals(640, $probe['width']);
        $this->assertEquals(360, $probe['height']);
        $this->assertTrue($probe['has_audio']);
        $this->assertEqualsWithDelta(5.0, $probe['duration'], 0.5);

        // 3. Register a Video record for compression
        $inputRelPath = 'temp/smoke_test/synthetic_source.mp4';
        $targetBytes = 800000; // 800 KB

        $video = Video::create([
            'uuid'              => 'smoke-test-uuid',
            'original_filename' => 'synthetic_source.mp4',
            'input_path'        => $inputRelPath,
            'original_size'     => filesize($sourceFile),
            'duration'          => $probe['duration'],
            'source_width'      => $probe['width'],
            'source_height'     => $probe['height'],
            'source_format'     => $probe['format'],
            'source_codec'      => $probe['codec'],
            'source_fps'        => $probe['fps'],
            'has_audio'         => true,
            'target_size'       => $targetBytes,
            'target_resolution' => 'original',
            'encoding_preset'   => 'ultrafast',
            'status'            => 'queued',
        ]);

        // 4. Perform compression
        $compressionService = new VideoCompressionService($ffmpegBinary, $ffprobeService);
        $compressionService->compress($video);

        // 5. Verify output
        $video->refresh();
        $this->assertEquals('completed', $video->status);
        $this->assertEquals(100, $video->progress_percentage);
        $this->assertNotNull($video->output_path);

        $fullOutputPath = storage_path('app/' . $video->output_path);
        $this->assertFileExists($fullOutputPath);

        $outputSize = filesize($fullOutputPath);
        $this->assertLessThanOrEqual($targetBytes, $outputSize, "Output size ({$outputSize}) exceeded target ({$targetBytes})");

        // 6. Probe the compressed output
        $outputProbe = $ffprobeService->probe($fullOutputPath);
        $this->assertEqualsWithDelta(5.0, $outputProbe['duration'], 0.5, "Duration was not preserved.");
        $this->assertTrue($outputProbe['has_audio'], "Audio was lost during compression.");

        // Clean up output file
        if (file_exists($fullOutputPath)) {
            @unlink($fullOutputPath);
        }
    }
}

