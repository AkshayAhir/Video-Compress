<?php

namespace Tests\Feature;

use App\Models\UploadSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ChunkUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Clean up any test chunks in storage/app/temp
        $tempChunks = storage_path('app/temp/chunks');
        if (File::isDirectory($tempChunks)) {
            File::deleteDirectory($tempChunks);
        }
        parent::tearDown();
    }

    public function test_cannot_start_upload_with_disallowed_extension(): void
    {
        $response = $this->postJson('/upload/start', [
            'filename'     => 'malicious.exe',
            'total_size'   => 1024 * 1024,
            'total_chunks' => 1,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_cannot_start_upload_exceeding_max_size(): void
    {
        $maxSize = config('video.max_upload_size');

        $response = $this->postJson('/upload/start', [
            'filename'     => 'huge_video.mp4',
            'total_size'   => $maxSize + 1000000,
            'total_chunks' => 10,
        ]);

        $response->assertStatus(422);
    }

    public function test_start_upload_session_successfully(): void
    {
        $response = $this->postJson('/upload/start', [
            'filename'     => 'sample.mp4',
            'total_size'   => 20000000,
            'total_chunks' => 2,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('upload_sessions', [
            'original_filename' => 'sample.mp4',
            'total_size'        => 20000000,
            'total_chunks'      => 2,
            'status'            => 'pending',
        ]);
    }

    public function test_cannot_upload_chunk_with_invalid_index(): void
    {
        $start = $this->postJson('/upload/start', [
            'filename'     => 'sample.mp4',
            'total_size'   => 1000000,
            'total_chunks' => 2,
        ]);

        $sessionId = $start->json('session_id');
        $dummyFile = UploadedFile::fake()->create('chunk_5.part', 500);

        // Chunk index 5 is out of bounds for total_chunks = 2
        $response = $this->postJson('/upload/chunk', [
            'session_id'  => $sessionId,
            'chunk_index' => 5,
            'chunk_file'  => $dummyFile,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_cannot_complete_session_when_chunks_are_missing(): void
    {
        $start = $this->postJson('/upload/start', [
            'filename'     => 'sample.mp4',
            'total_size'   => 1000,
            'total_chunks' => 2,
        ]);

        $sessionId = $start->json('session_id');

        // Only upload chunk 0, omit chunk 1
        $fileChunk0 = UploadedFile::fake()->create('chunk_0.part', 500);
        $this->postJson('/upload/chunk', [
            'session_id'  => $sessionId,
            'chunk_index' => 0,
            'chunk_file'  => $fileChunk0,
        ])->assertStatus(200);

        // Complete should fail because chunk 1 is missing
        $response = $this->postJson('/upload/complete', [
            'session_id' => $sessionId,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
    }
}

