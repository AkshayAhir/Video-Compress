<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadChunkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session_id'  => ['required', 'string', 'size:40'],
            'chunk_index' => ['required', 'integer', 'min:0'],
            'chunk_file'  => ['required', 'file'],
        ];
    }
}

