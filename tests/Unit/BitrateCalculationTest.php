<?php

namespace Tests\Unit;

use App\Exceptions\InfeasibleTargetException;
use App\Services\VideoCompressionService;
use Tests\TestCase;

class BitrateCalculationTest extends TestCase
{
    protected VideoCompressionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new VideoCompressionService();
    }

    public function test_target_bitrate_calculation_with_audio(): void
    {
        // 40 MB = 40,000,000 bytes for 60 seconds video with audio
        $targetBytes = 40000000;
        $duration = 60.0;
        $hasAudio = true;

        $bitrates = $this->service->calculateBitrates($targetBytes, $duration, $hasAudio);

        $this->assertArrayHasKey('video_bitrate_kbps', $bitrates);
        $this->assertArrayHasKey('audio_bitrate_kbps', $bitrates);
        $this->assertArrayHasKey('total_bitrate_kbps', $bitrates);

        // Net bytes after 4% margin: 38,400,000 bytes -> 307,200,000 bits
        // Total bitrate: 307,200,000 / 60 / 1000 = 5,120 kbps
        $this->assertEquals(5120, $bitrates['total_bitrate_kbps']);
        // For total bitrate >= 1500 kbps, audio bitrate should be 128 kbps
        $this->assertEquals(128, $bitrates['audio_bitrate_kbps']);
        // Video bitrate = 5120 - 128 = 4992 kbps
        $this->assertEquals(4992, $bitrates['video_bitrate_kbps']);
    }

    public function test_target_bitrate_calculation_without_audio(): void
    {
        // 20 MB = 20,000,000 bytes for 30 seconds video without audio
        $targetBytes = 20000000;
        $duration = 30.0;
        $hasAudio = false;

        $bitrates = $this->service->calculateBitrates($targetBytes, $duration, $hasAudio);

        // Net bytes after 4% margin: 19,200,000 bytes -> 153,600,000 bits
        // Total bitrate: 153,600,000 / 30 / 1000 = 5,120 kbps
        $this->assertEquals(5120, $bitrates['total_bitrate_kbps']);
        $this->assertEquals(0, $bitrates['audio_bitrate_kbps']);
        $this->assertEquals(5120, $bitrates['video_bitrate_kbps']);
    }

    public function test_infeasible_target_size_is_rejected(): void
    {
        // 1 MB = 1,000,000 bytes for a 1-hour (3600 seconds) video is infeasible
        $this->expectException(InfeasibleTargetException::class);
        $this->expectExceptionMessage('too small');

        $this->service->calculateBitrates(1000000, 3600.0, true);
    }

    public function test_zero_or_negative_duration_throws_exception(): void
    {
        $this->expectException(InfeasibleTargetException::class);
        $this->service->calculateBitrates(40000000, 0.0, true);
    }

    public function test_video_filter_preserves_even_dimensions_and_aspect_ratio(): void
    {
        // Original resolution ensures dimensions are divisible by 2
        $filterOriginal = $this->service->buildVideoFilter('original', 1920, 1080);
        $this->assertStringContainsString('scale=trunc(iw/2)*2:trunc(ih/2)*2', $filterOriginal);

        // 720p landscape
        $filter720pLandscape = $this->service->buildVideoFilter('720p', 1920, 1080);
        $this->assertStringContainsString('min(1280,iw)', $filter720pLandscape);
        $this->assertStringContainsString('min(720,ih)', $filter720pLandscape);
        $this->assertStringContainsString('force_original_aspect_ratio=decrease', $filter720pLandscape);

        // 720p portrait
        $filter720pPortrait = $this->service->buildVideoFilter('720p', 1080, 1920);
        $this->assertStringContainsString('min(720,iw)', $filter720pPortrait);
        $this->assertStringContainsString('min(1280,ih)', $filter720pPortrait);
    }
}
