<?php

namespace App\Http\Requests;

use App\Services\GeminiChatbotService;
use Illuminate\Foundation\Http\FormRequest;

class ChatbotRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Public endpoint (same visibility as doctors/services/contact).
        // Abuse is controlled by the route's throttle middleware.
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => [
                'required',
                'string',
                'min:1',
                'max:'.GeminiChatbotService::MAX_MESSAGE_LENGTH,
            ],

            // Bounded multi-turn history replayed to Gemini. The server
            // additionally slices it down to the newest entries.
            'history' => ['sometimes', 'array', 'max:'.GeminiChatbotService::MAX_HISTORY_MESSAGES],
            'history.*.role' => ['required', 'string', 'in:user,model'],
            'history.*.text' => ['required', 'string', 'max:'.GeminiChatbotService::MAX_HISTORY_TEXT],

            'language' => ['sometimes', 'string', 'in:en,ar'],
        ];
    }

    public function messages(): array
    {
        return [
            'message.required' => 'A message is required.',
            'message.max' => 'The message is too long.',
            'history.max' => 'The conversation history is too long.',
            'history.*.role' => 'History roles must be "user" or "model".',
            'language.in' => 'Language must be "en" or "ar".',
        ];
    }
}
