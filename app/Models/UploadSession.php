<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UploadSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'original_filename',
        'total_size',
        'total_chunks',
        'chunks_uploaded',
        'status',
        'chunk_dir',
        'expires_at',
    ];

    protected $casts = [
        'total_size' => 'integer',
        'total_chunks' => 'integer',
        'chunks_uploaded' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function getChunkPath(int $chunkIndex): string
    {
        return rtrim($this->chunk_dir, '/\\') . DIRECTORY_SEPARATOR . sprintf('chunk_%05d.part', $chunkIndex);
    }
}

