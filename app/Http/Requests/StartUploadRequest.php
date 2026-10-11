<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxUploadSize = config('video.max_upload_size', 1073741824);

        return [
            'filename'     => ['required', 'string', 'max:255'],
            'total_size'   => ['required', 'integer', 'min:1', "max:{$maxUploadSize}"],
            'total_chunks' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
    }
}

