<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Video extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'original_filename',
        'input_path',
        'output_path',
        'original_size',
        'output_size',
        'duration',
        'source_width',
        'source_height',
        'source_format',
        'source_codec',
        'source_fps',
        'has_audio',
        'target_size',
        'target_resolution',
        'encoding_preset',
        'status',
        'processing_stage',
        'progress_percentage',
        'attempt_count',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'original_size' => 'integer',
        'output_size' => 'integer',
        'target_size' => 'integer',
        'duration' => 'float',
        'source_width' => 'integer',
        'source_height' => 'integer',
        'has_audio' => 'boolean',
        'progress_percentage' => 'integer',
        'attempt_count' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Video $video) {
            if (empty($video->uuid)) {
                $video->uuid = (string) Str::uuid();
            }
        });
    }

    public function getFormattedOriginalSizeAttribute(): string
    {
        return self::formatBytes($this->original_size);
    }

    public function getFormattedOutputSizeAttribute(): ?string
    {
        return $this->output_size ? self::formatBytes($this->output_size) : null;
    }

    public function getFormattedTargetSizeAttribute(): ?string
    {
        return $this->target_size ? self::formatBytes($this->target_size) : null;
    }

    public function getPercentageSavedAttribute(): ?float
    {
        if (!$this->output_size || !$this->original_size || $this->original_size <= 0) {
            return null;
        }

        $saved = (1 - ($this->output_size / $this->original_size)) * 100;
        return round(max(0, $saved), 1);
    }

    public function getProcessingTimeSecondsAttribute(): ?int
    {
        if ($this->started_at && $this->completed_at) {
            return $this->started_at->diffInSeconds($this->completed_at);
        }
        return null;
    }

    public function getFormattedDurationAttribute(): string
    {
        if (!$this->duration) {
            return '0:00';
        }

        $seconds = (int) round($this->duration);
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;
        $hours = floor($minutes / 60);
        $minutes = $minutes % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $remainingSeconds);
        }

        return sprintf('%d:%02d', $minutes, $remainingSeconds);
    }

    public function isProcessing(): bool
    {
        return in_array($this->status, ['queued', 'processing']);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public static function formatBytes(?int $bytes, int $precision = 2): string
    {
        if ($bytes === null || $bytes <= 0) {
            return '0 B';
        }

        // Standard decimal units (1 MB = 1,000,000 bytes) matching user convention
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = min((int) floor(log($bytes, 1000)), count($units) - 1);
        $power = max(0, $power);
        $value = $bytes / pow(1000, $power);

        return round($value, $precision) . ' ' . $units[$power];
    }
}

