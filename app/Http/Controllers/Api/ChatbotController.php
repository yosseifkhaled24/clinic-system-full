<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\GeminiUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChatbotRequest;
use App\Services\GeminiChatbotService;
use Illuminate\Http\JsonResponse;

class ChatbotController extends Controller
{
    public function __construct(protected GeminiChatbotService $chatbot)
    {
    }

    /**
     * POST /api/chatbot — public AI clinic assistant (Gemini).
     *
     * Stateless: the client replays a bounded conversation history, so no
     * chat data (and no private records) are stored on the server.
     */
    public function store(ChatbotRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $result = $this->chatbot->chat(
                $data['message'],
                $data['history'] ?? [],
                $data['language'] ?? 'en',
            );
        } catch (GeminiUnavailableException $e) {
            // Static, user-safe message (never secrets or upstream bodies).
            return response()->json(['message' => $e->getMessage()], 503);
        }

        // `escalation` is null for normal replies and "emergency" for the
        // deterministic safety short-circuit (see GeminiChatbotService).
        // Adding the key is backward compatible: existing clients only
        // read `reply` / `model`.
        return response()->json([
            'reply' => $result['reply'],
            'model' => $result['model'],
            'escalation' => $result['escalation'] ?? null,
        ]);
    }
}
