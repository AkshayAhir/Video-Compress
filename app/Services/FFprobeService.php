<?php

namespace App\Services;

use App\Exceptions\VideoValidationException;
use Symfony\Component\Process\Process;

class FFprobeService
{
    protected string $ffprobeBinary;

    public function __construct(?string $binary = null)
    {
        $this->ffprobeBinary = $binary ?: config('video.ffprobe_binary', 'ffprobe');
    }

    /**
     * Probe a media file and return structured metadata.
     *
     * @param string $filePath
     * @return array
     * @throws VideoValidationException
     */
    public function probe(string $filePath): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new VideoValidationException('Media file does not exist or is not readable on disk.');
        }

        $process = new Process([
            $this->ffprobeBinary,
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $filePath,
        ]);

        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new VideoValidationException(
                'FFprobe failed to inspect media file: ' . ($process->getErrorOutput() ?: 'Unsupported or corrupted format.')
            );
        }

        $rawOutput = $process->getOutput();
        $data = json_decode($rawOutput, true);

        if (!is_array($data)) {
            throw new VideoValidationException('Unable to parse FFprobe JSON output.');
        }

        $streams = $data['streams'] ?? [];
        $format = $data['format'] ?? [];

        // Find primary video stream
        $videoStream = null;
        $hasAudio = false;

        foreach ($streams as $stream) {
            $codecType = $stream['codec_type'] ?? '';
            if ($codecType === 'video' && $videoStream === null) {
                // Ensure this is not an attached picture / thumbnail
                $disposition = $stream['disposition'] ?? [];
                if (empty($disposition['attached_pic'])) {
                    $videoStream = $stream;
                }
            } elseif ($codecType === 'audio') {
                $hasAudio = true;
            }
        }

        if (!$videoStream) {
            throw new VideoValidationException('The uploaded file does not contain a valid video stream.');
        }

        // Determine duration
        $duration = 0.0;
        if (!empty($videoStream['duration']) && is_numeric($videoStream['duration'])) {
            $duration = (float) $videoStream['duration'];
        } elseif (!empty($format['duration']) && is_numeric($format['duration'])) {
            $duration = (float) $format['duration'];
        }

        if ($duration <= 0.0) {
            throw new VideoValidationException('The video stream does not have a positive duration.');
        }

        $width = isset($videoStream['width']) ? (int) $videoStream['width'] : 0;
        $height = isset($videoStream['height']) ? (int) $videoStream['height'] : 0;

        if ($width <= 0 || $height <= 0) {
            throw new VideoValidationException('Invalid video dimensions detected.');
        }

        // Determine rotation side data if present
        $rotation = 0;
        if (!empty($videoStream['side_data_list'])) {
            foreach ($videoStream['side_data_list'] as $sideData) {
                if (isset($sideData['rotation'])) {
                    $rotation = (int) $sideData['rotation'];
                    break;
                }
            }
        }
        if ($rotation === 0 && !empty($videoStream['tags']['rotate'])) {
            $rotation = (int) $videoStream['tags']['rotate'];
        }

        // Calculate FPS
        $fps = null;
        if (!empty($videoStream['r_frame_rate'])) {
            $parts = explode('/', $videoStream['r_frame_rate']);
            if (count($parts) === 2 && (float) $parts[1] > 0) {
                $fps = round((float) $parts[0] / (float) $parts[1], 2);
            }
        }

        return [
            'duration'     => round($duration, 2),
            'width'        => $width,
            'height'       => $height,
            'codec'        => $videoStream['codec_name'] ?? 'unknown',
            'format'       => $format['format_name'] ?? 'unknown',
            'has_audio'    => $hasAudio,
            'rotation'     => $rotation,
            'fps'          => $fps ? (string) $fps : null,
            'bit_rate'     => isset($format['bit_rate']) ? (int) $format['bit_rate'] : null,
            'size_bytes'   => isset($format['size']) ? (int) $format['size'] : (int) filesize($filePath),
        ];
    }
}

