<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;


class StorePostRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'chapter_id' => 'nullable|exists:id,chapters',
            'caption' => 'nullable|string',
            'script' => 'nullable|string',

            'media_assets' => 'required|in:single,carousel',
            'status' => 'required|in:draft,scheduled,published',

            'ai_model' => 'nullable|string',
            'ai_prompt' => 'nullable|string',
            'scheduled_at' => 'nullable|date',

            'media' => 'nullable|array',
            // 'media.*.media_type' => 'required|in:image,video',
            // 'media.*.media_path' => 'required|string',
            'media.*.file' => 'required|file|mimes:jpg,jpeg,png,gif,mp4,mov,avi,webm|max:51200',
            'media.*.media_order' => 'required|integer',

            'hashtags' => 'nullable|array',
            'hashtags.*' => 'string|max:50',

            'platforms' => 'nullable|array',
            'platforms.*' => 'in:instagram,tiktok'
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([ 
            'success' => false,
            'responseCode' => 422,
            'error' => 'Validation failed.',
            'messages' => $validator->errors(),
            'timestamp' => now()->format('Y-m-d H:i:s')

        ], 422));
    }
}
