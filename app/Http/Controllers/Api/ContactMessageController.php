<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContactMessageRequest;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;

class ContactMessageController extends Controller
{
    public function index(): JsonResponse
    {
        $messages = ContactMessage::all()->map(function (ContactMessage $message) {
            return $this->formatContactMessage($message);
        });

        return response()->json($messages);
    }

    public function show(ContactMessage $contactMessage): JsonResponse
    {
        return response()->json($this->formatContactMessage($contactMessage));
    }

    public function store(ContactMessageRequest $request): JsonResponse
    {
        $message = ContactMessage::create($request->validated());

        return response()->json([
            'message' => 'Contact message submitted successfully.',
            'contact_message' => $this->formatContactMessage($message),
        ], 201);
    }

    public function destroy(ContactMessage $contactMessage): JsonResponse
    {
        $contactMessage->delete();

        return response()->json(null, 204);
    }

    private function formatContactMessage(ContactMessage $message): array
    {
        return [
            'id' => $message->id,
            'name' => $message->name,
            'email' => $message->email,
            'subject' => $message->subject,
            'message' => $message->message,
            'created_at' => $message->created_at?->toDateTimeString(),
            'updated_at' => $message->updated_at?->toDateTimeString(),
        ];
    }
}
