<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\UploadedFile;


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
            'chapter_id' => 'nullable|exists:chapters,id',
            'caption' => 'nullable|string',
            'script' => 'nullable|string',

            'media_assets' => 'required|in:single,carousel',
            'status' => 'required|in:draft,scheduled,published',

            'ai_model' => 'nullable|string',
            'ai_prompt' => 'nullable|string',
            'scheduled_at' => 'nullable|date',

            'media' => 'nullable|array',
            // 'media.*.file' => 'required|file|mimes:jpg,jpeg,png,gif,mp4,mov,avi,webm|max:51200',
            'media.*.file' => [
                'required',
                function ($attribute, $value, $fail) {
                    // Uploaded file
                    if ($value instanceof UploadedFile) {
                        return;
                    }

                    // URL string
                    if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
                        return;
                    }

                    // Base64 image
                    if (is_string($value) && preg_match('/^data:(image|video)\/[a-zA-Z0-9.+-]+;base64,/', $value)) {
                        return;
                    }

                    $fail('The file must be an uploaded file, a valid URL, or a base64 media string.');
                },
            ],
            'media.*.media_order' => 'required|integer',

            'hashtags' => 'nullable|string',

            'platforms' => 'required|array',
            'platforms.*' => 'in:instagram,tiktok',

            'affiliate_url' => 'required|string',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([ 
            'success' => false,
            'responseCode' => 422,
            'error' => 'Validation failed.',
            'messages' => $validator->errors()->first(),
            'timestamp' => now()->format('Y-m-d H:i:s')

        ], 422));
    }
}
