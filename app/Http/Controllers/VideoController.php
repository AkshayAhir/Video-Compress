<?php

namespace App\Http\Controllers;

use App\Exceptions\InfeasibleTargetException;
use App\Http\Requests\StartCompressionRequest;
use App\Jobs\CompressVideo;
use App\Models\Video;
use App\Services\VideoCompressionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VideoController extends Controller
{
    protected VideoCompressionService $compressionService;

    public function __construct(VideoCompressionService $compressionService)
    {
        $this->compressionService = $compressionService;
    }

    /**
     * Display the dashboard or return list of videos.
     */
    public function index(Request $request)
    {
        $videos = Video::orderBy('created_at', 'desc')->take(30)->get();

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'videos'  => $videos->map(fn(Video $v) => $this->formatVideoResponse($v)),
            ]);
        }

        return view('dashboard', [
            'videos'           => $videos,
            'encodingPresets'  => config('video.encoding_presets', []),
            'resolutions'      => config('video.resolutions', []),
            'targetPresetsMb'  => config('video.target_presets_mb', [20, 40, 80, 100]),
            'maxUploadSize'    => config('video.max_upload_size', 1073741824),
            'chunkSize'        => config('video.chunk_size', 10485760),
        ]);
    }

    /**
     * Queue compression for a video.
     */
    public function compress(StartCompressionRequest $request, Video $video): JsonResponse
    {
        // Protect against duplicate jobs while already running
        if ($video->status === 'processing') {
            return response()->json([
                'success' => false,
                'message' => 'Video compression is already running for this video.',
            ], 409);
        }

        $targetMb = (float) $request->input('target_mb');
        // Explicit convention: 1 MB = 1,000,000 bytes
        $targetBytes = (int) round($targetMb * 1000000);
        $resolution = $request->input('target_resolution');
        $preset = $request->input('encoding_preset');

        // Check bitrate feasibility before queueing
        try {
            $this->compressionService->calculateBitrates(
                $targetBytes,
                (float) ($video->duration ?: 1.0),
                (bool) $video->has_audio
            );
        } catch (InfeasibleTargetException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        // Set video to queued status
        $video->update([
            'target_size'         => $targetBytes,
            'target_resolution'   => $resolution,
            'encoding_preset'     => $preset,
            'status'              => 'queued',
            'processing_stage'    => 'Queued for compression',
            'progress_percentage' => 0,
            'error_message'       => null,
            'started_at'          => null,
            'completed_at'        => null,
        ]);

        // Dispatch queued background job
        CompressVideo::dispatch($video);

        return response()->json([
            'success' => true,
            'message' => 'Compression job dispatched to queue.',
            'video'   => $this->formatVideoResponse($video),
        ]);
    }

    /**
     * Poll current status of a video.
     */
    public function status(Video $video): JsonResponse
    {
        return response()->json([
            'success' => true,
            'video'   => $this->formatVideoResponse($video),
        ]);
    }

    /**
     * Stream compressed video with HTTP 206 Partial Content support for player scrubbing.
     */
    public function preview(Video $video)
    {
        if (!$video->output_path) {
            abort(404, 'No compressed output available for this video.');
        }

        $fullPath = storage_path('app/' . $video->output_path);
        if (!file_exists($fullPath)) {
            abort(404, 'Output file not found on disk.');
        }

        $size = filesize($fullPath);
        $length = $size;
        $start = 0;
        $end = $size - 1;

        $headers = [
            'Content-Type'   => 'video/mp4',
            'Accept-Ranges'  => 'bytes',
        ];

        // Handle HTTP Range header for streaming & scrubbing
        $range = request()->header('Range');
        if ($range && preg_match('/bytes=(\d+)-(\d*)?/', $range, $matches)) {
            $start = (int) $matches[1];
            if (!empty($matches[2])) {
                $end = (int) $matches[2];
            }
            $length = ($end - $start) + 1;

            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
            $headers['Content-Length'] = $length;

            return new StreamedResponse(function () use ($fullPath, $start, $length) {
                $file = fopen($fullPath, 'rb');
                fseek($file, $start);
                $remaining = $length;
                while (!feof($file) && $remaining > 0) {
                    $read = min(1048576, $remaining); // 1 MB chunk
                    echo fread($file, $read);
                    flush();
                    $remaining -= $read;
                }
                fclose($file);
            }, 206, $headers);
        }

        $headers['Content-Length'] = $size;

        return response()->file($fullPath, $headers);
    }

    /**
     * Download the compressed MP4 file.
     */
    public function download(Video $video): BinaryFileResponse
    {
        if (!$video->output_path) {
            abort(404, 'No compressed output available.');
        }

        $fullPath = storage_path('app/' . $video->output_path);
        if (!file_exists($fullPath)) {
            abort(404, 'Output file not found on disk.');
        }

        $downloadFilename = 'compressed_' . pathinfo($video->original_filename, PATHINFO_FILENAME) . '.mp4';

        return response()->download($fullPath, $downloadFilename, [
            'Content-Type' => 'video/mp4',
        ]);
    }

    /**
     * Retry a failed video compression job.
     */
    public function retry(Video $video): JsonResponse
    {
        if ($video->status === 'processing') {
            return response()->json([
                'success' => false,
                'message' => 'Video is currently being processed.',
            ], 409);
        }

        if (!$video->target_size) {
            return response()->json([
                'success' => false,
                'message' => 'Target settings must be configured before retrying.',
            ], 422);
        }

        $video->update([
            'status'              => 'queued',
            'processing_stage'    => 'Retrying compression...',
            'progress_percentage' => 0,
            'error_message'       => null,
            'started_at'          => null,
            'completed_at'        => null,
        ]);

        CompressVideo::dispatch($video);

        return response()->json([
            'success' => true,
            'message' => 'Compression retry queued successfully.',
            'video'   => $this->formatVideoResponse($video),
        ]);
    }

    /**
     * Safely delete a video and its stored files.
     */
    public function destroy(Video $video): JsonResponse
    {
        if ($video->status === 'processing') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete video while compression is actively processing. Please wait for completion or failure.',
            ], 409);
        }

        // Delete input file
        if ($video->input_path) {
            $inputFull = storage_path('app/' . $video->input_path);
            if (file_exists($inputFull)) {
                @unlink($inputFull);
            }
        }

        // Delete output file
        if ($video->output_path) {
            $outputFull = storage_path('app/' . $video->output_path);
            if (file_exists($outputFull)) {
                @unlink($outputFull);
            }
        }

        $video->delete();

        return response()->json([
            'success' => true,
            'message' => 'Video and stored media removed successfully.',
        ]);
    }

    /**
     * Format Video model into clean array for JSON responses.
     */
    protected function formatVideoResponse(Video $video): array
    {
        return [
            'id'                     => $video->id,
            'uuid'                   => $video->uuid,
            'original_filename'      => $video->original_filename,
            'original_size'          => $video->original_size,
            'formatted_original_size'=> $video->formatted_original_size,
            'output_size'            => $video->output_size,
            'formatted_output_size'  => $video->formatted_output_size,
            'target_size'            => $video->target_size,
            'formatted_target_size'  => $video->formatted_target_size,
            'percentage_saved'       => $video->percentage_saved,
            'duration'               => $video->duration,
            'formatted_duration'     => $video->formatted_duration,
            'width'                  => $video->source_width,
            'height'                 => $video->source_height,
            'codec'                  => $video->source_codec,
            'format'                 => $video->source_format,
            'fps'                    => $video->source_fps,
            'has_audio'              => $video->has_audio,
            'target_resolution'      => $video->target_resolution,
            'encoding_preset'        => $video->encoding_preset,
            'status'                 => $video->status,
            'processing_stage'       => $video->processing_stage,
            'progress_percentage'    => $video->progress_percentage,
            'attempt_count'          => $video->attempt_count,
            'error_message'          => $video->error_message,
            'processing_time_seconds'=> $video->processing_time_seconds,
            'created_at'             => $video->created_at?->toIso8601String(),
            'preview_url'            => route('videos.preview', ['video' => $video->uuid]),
            'download_url'           => route('videos.download', ['video' => $video->uuid]),
        ];
    }
}

