<?php

namespace App\Services;

use App\Exceptions\VideoValidationException;
use App\Models\UploadSession;
use App\Models\Video;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ChunkUploadService
{
    protected FFprobeService $ffprobeService;

    public function __construct(?FFprobeService $ffprobeService = null)
    {
        $this->ffprobeService = $ffprobeService ?: new FFprobeService();
    }

    /**
     * Start a new chunked upload session.
     *
     * @param string $originalFilename
     * @param int $totalSize
     * @param int $totalChunks
     * @return UploadSession
     * @throws VideoValidationException
     */
    public function startSession(string $originalFilename, int $totalSize, int $totalChunks): UploadSession
    {
        $maxUploadSize = config('video.max_upload_size', 1073741824);
        if ($totalSize <= 0) {
            throw new VideoValidationException('Video file size must be greater than zero.');
        }

        if ($totalSize > $maxUploadSize) {
            $maxMb = round($maxUploadSize / 1000000, 1);
            throw new VideoValidationException("Video exceeds maximum permitted upload size of {$maxMb} MB.");
        }

        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $allowedExtensions = config('video.supported_extensions', ['mp4', 'mov', 'mkv', 'avi', 'webm']);
        if (!in_array($extension, $allowedExtensions, true)) {
            throw new VideoValidationException(
                "Unsupported file extension '.{$extension}'. Allowed: " . implode(', ', $allowedExtensions)
            );
        }

        if ($totalChunks <= 0) {
            throw new VideoValidationException('Invalid chunk count.');
        }

        $sessionId = Str::random(40);
        $chunkDir = storage_path('app/temp/chunks/' . $sessionId);
        File::ensureDirectoryExists($chunkDir);

        $retentionHours = config('video.temp_upload_retention_hours', 24);

        return UploadSession::create([
            'session_id'        => $sessionId,
            'original_filename' => basename($originalFilename),
            'total_size'        => $totalSize,
            'total_chunks'      => $totalChunks,
            'chunks_uploaded'   => 0,
            'status'            => 'pending',
            'chunk_dir'         => $chunkDir,
            'expires_at'        => now()->addHours($retentionHours),
        ]);
    }

    /**
     * Store an individual uploaded chunk.
     *
     * @param UploadSession $session
     * @param int $chunkIndex
     * @param UploadedFile $file
     * @return UploadSession
     * @throws VideoValidationException
     */
    public function storeChunk(UploadSession $session, int $chunkIndex, UploadedFile $file): UploadSession
    {
        if ($session->status === 'completed') {
            throw new VideoValidationException('Upload session is already completed.');
        }

        if ($session->isExpired()) {
            throw new VideoValidationException('Upload session has expired. Please start a new upload.');
        }

        if ($chunkIndex < 0 || $chunkIndex >= $session->total_chunks) {
            throw new VideoValidationException("Chunk index {$chunkIndex} out of bounds (0 to " . ($session->total_chunks - 1) . ").");
        }

        $targetPath = $session->getChunkPath($chunkIndex);
        File::ensureDirectoryExists(dirname($targetPath));

        // Move chunk to target location (overwriting allows retry)
        $file->move(dirname($targetPath), basename($targetPath));

        // Count stored chunks on disk
        $uploadedCount = 0;
        for ($i = 0; $i < $session->total_chunks; $i++) {
            if (file_exists($session->getChunkPath($i))) {
                $uploadedCount++;
            }
        }

        $session->update([
            'chunks_uploaded' => $uploadedCount,
        ]);

        return $session;
    }

    /**
     * Assemble all chunks into a complete video, validate with FFprobe, and register Video model.
     *
     * @param UploadSession $session
     * @return Video
     * @throws VideoValidationException
     */
    public function completeSession(UploadSession $session): Video
    {
        if ($session->status === 'completed') {
            throw new VideoValidationException('This upload session has already been completed.');
        }

        // Verify all chunks exist
        $accumulatedBytes = 0;
        for ($i = 0; $i < $session->total_chunks; $i++) {
            $chunkFile = $session->getChunkPath($i);
            if (!file_exists($chunkFile)) {
                throw new VideoValidationException("Missing chunk {$i} of {$session->total_chunks}. Please retry upload.");
            }
            $accumulatedBytes += filesize($chunkFile);
        }

        // Verify total size matches
        if ($accumulatedBytes !== $session->total_size) {
            throw new VideoValidationException(
                "Accumulated chunk size ({$accumulatedBytes} bytes) does not match expected size ({$session->total_size} bytes)."
            );
        }

        $session->update(['status' => 'assembling']);

        // Prepare final input video path
        $uuid = (string) Str::uuid();
        $ext = strtolower(pathinfo($session->original_filename, PATHINFO_EXTENSION)) ?: 'mp4';
        $inputDir = storage_path('app/videos/input');
        File::ensureDirectoryExists($inputDir);
        $finalPath = $inputDir . DIRECTORY_SEPARATOR . $uuid . '.' . $ext;

        // Assemble chunks without loading full video into PHP memory
        $outputHandle = fopen($finalPath, 'wb');
        if (!$outputHandle) {
            throw new VideoValidationException("Failed to open destination file for writing: {$finalPath}");
        }

        try {
            for ($i = 0; $i < $session->total_chunks; $i++) {
                $chunkPath = $session->getChunkPath($i);
                $inputHandle = fopen($chunkPath, 'rb');
                if (!$inputHandle) {
                    throw new VideoValidationException("Failed to open chunk {$i} for reading.");
                }

                while (!feof($inputHandle)) {
                    $buffer = fread($inputHandle, 1048576); // 1 MB buffer
                    if ($buffer !== false) {
                        fwrite($outputHandle, $buffer);
                    }
                }
                fclose($inputHandle);
            }
        } finally {
            fclose($outputHandle);
        }

        // Verify assembled size on disk
        if (filesize($finalPath) !== $session->total_size) {
            @unlink($finalPath);
            throw new VideoValidationException("Assembled file size mismatch on disk.");
        }

        // Probe assembled file with FFprobe to validate real video streams and duration
        try {
            $metadata = $this->ffprobeService->probe($finalPath);
        } catch (\Throwable $e) {
            @unlink($finalPath);
            throw new VideoValidationException("Invalid video file: " . $e->getMessage());
        }

        // Create the Video database record
        $video = Video::create([
            'uuid'              => $uuid,
            'original_filename' => $session->original_filename,
            'input_path'        => 'videos/input/' . $uuid . '.' . $ext,
            'original_size'     => $session->total_size,
            'duration'          => $metadata['duration'],
            'source_width'      => $metadata['width'],
            'source_height'     => $metadata['height'],
            'source_format'     => $metadata['format'],
            'source_codec'      => $metadata['codec'],
            'source_fps'        => $metadata['fps'],
            'has_audio'         => $metadata['has_audio'],
            'status'            => 'uploaded',
            'processing_stage'  => 'Uploaded & Validated',
            'progress_percentage' => 0,
        ]);

        // Clean up chunks
        File::deleteDirectory($session->chunk_dir);
        $session->update(['status' => 'completed']);

        return $video;
    }
}

