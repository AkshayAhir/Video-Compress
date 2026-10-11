<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartCompressionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $allowedPresets = array_keys(config('video.encoding_presets', []));
        $allowedResolutions = array_keys(config('video.resolutions', []));

        return [
            'target_mb'         => ['required', 'numeric', 'min:1', 'max:5000'],
            'target_resolution' => ['required', 'string', 'in:' . implode(',', $allowedResolutions)],
            'encoding_preset'   => ['required', 'string', 'in:' . implode(',', $allowedPresets)],
        ];
    }
}

