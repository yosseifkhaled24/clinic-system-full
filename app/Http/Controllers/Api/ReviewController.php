<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreReviewRequest;
use App\Http\Requests\Api\UpdateReviewRequest;
use App\Models\Appointment;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(): JsonResponse
    {
        $reviews = Review::with(['doctor', 'user', 'appointment'])->latest()->get()->map(function (Review $review) {
            return $this->formatReview($review);
        });

        return response()->json($reviews);
    }

    public function show(Review $review): JsonResponse
    {
        return response()->json($this->formatReview($review->load(['doctor', 'user', 'appointment'])));
    }

    public function store(StoreReviewRequest $request): JsonResponse
    {
        $appointment = Appointment::find($request->appointment_id);

        if (! $appointment || $appointment->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Review can only be created for your own appointment.'], 403);
        }

        $review = Review::create([
            'user_id' => $request->user()->id,
            'doctor_id' => $request->doctor_id,
            'appointment_id' => $request->appointment_id,
            'rating' => $request->rating,
            'comment' => $request->comment ?? null,
        ]);

        return response()->json($this->formatReview($review->load(['doctor', 'user', 'appointment'])), 201);
    }

    public function update(UpdateReviewRequest $request, Review $review): JsonResponse
    {
        if ($review->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $review->update($request->validated());

        return response()->json($this->formatReview($review->refresh()->load(['doctor', 'user', 'appointment'])));
    }

    public function destroy(Request $request, Review $review): JsonResponse
    {
        if ($review->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $review->delete();

        return response()->json(null, 204);
    }

    private function formatReview(Review $review): array
    {
        return [
            'id' => $review->id,
            'rating' => $review->rating,
            'comment' => $review->comment,
            'user' => [
                'id' => $review->user->id,
                'name' => $review->user->name,
            ],
            'doctor' => [
                'id' => $review->doctor->id,
                'name' => $review->doctor->name,
            ],
            'appointment' => [
                'id' => $review->appointment->id,
                'appointment_date' => $review->appointment->appointment_date,
            ],
            'created_at' => $review->created_at?->toDateTimeString(),
            'updated_at' => $review->updated_at?->toDateTimeString(),
        ];
    }
}
