<?php

return [
    /*
    |--------------------------------------------------------------------------
    | FFmpeg & FFprobe Binaries
    |--------------------------------------------------------------------------
    |
    | Executable paths for FFmpeg and FFprobe. Supports paths with spaces.
    |
    */
    'ffmpeg_binary' => env('FFMPEG_BINARY', 'C:\Program Files\Kdenlive\bin\ffmpeg.exe'),
    'ffprobe_binary' => env('FFPROBE_BINARY', 'C:\Program Files\Kdenlive\bin\ffprobe.exe'),

    /*
    |--------------------------------------------------------------------------
    | Upload & Chunking Configuration
    |--------------------------------------------------------------------------
    |
    | Maximum upload size allowed in bytes (default: 1 GB).
    | Chunk size used for sequential chunked upload (default: 10 MB).
    |
    */
    'max_upload_size' => (int) env('MAX_UPLOAD_SIZE', 1073741824), // 1 GB
    'chunk_size' => (int) env('CHUNK_SIZE', 10485760), // 10 MB

    /*
    |--------------------------------------------------------------------------
    | Processing & Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Maximum processing duration in seconds before job timeout (default: 3600).
    | Queue name dedicated to video encoding tasks.
    |
    */
    'max_processing_time' => (int) env('VIDEO_MAX_PROCESSING_SECONDS', 3600),
    'queue' => env('VIDEO_QUEUE', 'video'),

    /*
    |--------------------------------------------------------------------------
    | Cleanup & Retention
    |--------------------------------------------------------------------------
    |
    | Hours to retain incomplete/abandoned upload sessions before cleanup.
    | Days to retain completed compressed videos (set null to disable auto-delete).
    |
    */
    'temp_upload_retention_hours' => (int) env('VIDEO_TEMP_RETENTION_HOURS', 24),
    'completed_retention_days' => env('VIDEO_COMPLETED_RETENTION_DAYS') !== null
        ? (int) env('VIDEO_COMPLETED_RETENTION_DAYS')
        : 7,

    /*
    |--------------------------------------------------------------------------
    | Presets & Supported Options
    |--------------------------------------------------------------------------
    */
    'encoding_presets' => [
        'ultrafast' => 'Ultra Fast - Lowest compression efficiency, fastest processing',
        'superfast' => 'Super Fast - Very quick, larger file size',
        'veryfast'  => 'Very Fast - Recommended for quick testing',
        'faster'    => 'Faster - Good balance if you have limited time',
        'fast'      => 'Fast - Slightly faster than standard',
        'medium'    => 'Medium (Default) - Optimal balance of quality, size, and speed',
        'slow'      => 'Slow - High visual fidelity, longer encoding time',
        'slower'    => 'Slower - Very high fidelity, significantly slower',
        'veryslow'  => 'Very Slow - Maximum compression efficiency, longest encoding time',
    ],

    'resolutions' => [
        'original' => 'Keep Original Resolution',
        '1080p'    => '1080p (Full HD - max 1920x1080)',
        '720p'     => '720p (HD - max 1280x720)',
        '480p'     => '480p (SD - max 854x480)',
    ],

    'target_presets_mb' => [20, 40, 80, 100],

    'supported_extensions' => ['mp4', 'mov', 'mkv', 'avi', 'webm'],
];

