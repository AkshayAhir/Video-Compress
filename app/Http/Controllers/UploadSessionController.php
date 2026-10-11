<?php

namespace App\Http\Controllers;

use App\Exceptions\VideoValidationException;
use App\Http\Requests\CompleteUploadRequest;
use App\Http\Requests\StartUploadRequest;
use App\Http\Requests\UploadChunkRequest;
use App\Models\UploadSession;
use App\Services\ChunkUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UploadSessionController extends Controller
{
    protected ChunkUploadService $chunkUploadService;

    public function __construct(ChunkUploadService $chunkUploadService)
    {
        $this->chunkUploadService = $chunkUploadService;
    }

    /**
     * Start a new chunked upload session.
     */
    public function start(StartUploadRequest $request): JsonResponse
    {
        try {
            $session = $this->chunkUploadService->startSession(
                $request->input('filename'),
                (int) $request->input('total_size'),
                (int) $request->input('total_chunks')
            );

            return response()->json([
                'success'    => true,
                'session_id' => $session->session_id,
                'chunk_size' => config('video.chunk_size', 10485760),
                'message'    => 'Upload session initialized.',
            ]);
        } catch (VideoValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Error starting upload session: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Unable to start upload session. Please try again.',
            ], 500);
        }
    }

    /**
     * Store an uploaded chunk.
     */
    public function chunk(UploadChunkRequest $request): JsonResponse
    {
        $session = UploadSession::where('session_id', $request->input('session_id'))->first();

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired upload session.',
            ], 404);
        }

        try {
            $this->chunkUploadService->storeChunk(
                $session,
                (int) $request->input('chunk_index'),
                $request->file('chunk_file')
            );

            return response()->json([
                'success'         => true,
                'chunk_index'     => (int) $request->input('chunk_index'),
                'chunks_uploaded' => $session->chunks_uploaded,
                'total_chunks'    => $session->total_chunks,
            ]);
        } catch (VideoValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Error saving chunk: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to save upload chunk. Please retry.',
            ], 500);
        }
    }

    /**
     * Complete upload session and assemble chunks.
     */
    public function complete(CompleteUploadRequest $request): JsonResponse
    {
        $session = UploadSession::where('session_id', $request->input('session_id'))->first();

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid upload session.',
            ], 404);
        }

        try {
            $video = $this->chunkUploadService->completeSession($session);

            return response()->json([
                'success' => true,
                'message' => 'Video uploaded and validated successfully.',
                'video'   => [
                    'id'                => $video->id,
                    'uuid'              => $video->uuid,
                    'original_filename' => $video->original_filename,
                    'original_size'     => $video->original_size,
                    'formatted_size'    => $video->formatted_original_size,
                    'duration'          => $video->duration,
                    'formatted_duration'=> $video->formatted_duration,
                    'width'             => $video->source_width,
                    'height'            => $video->source_height,
                    'codec'             => $video->source_codec,
                    'format'            => $video->source_format,
                    'fps'               => $video->source_fps,
                    'has_audio'         => $video->has_audio,
                    'status'            => $video->status,
                ],
            ]);
        } catch (VideoValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Error assembling uploaded video: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Video processing failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}

