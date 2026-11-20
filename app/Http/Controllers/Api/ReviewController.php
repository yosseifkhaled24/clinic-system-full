<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreReviewRequest;
use App\Http\Requests\Api\UpdateReviewRequest;
use App\Models\Appointment;
use App\Models\Review;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $reviews = Review::with(['doctor', 'user', 'appointment'])->latest()->get()->map(function (Review $review) {
            return $this->formatReview($review, $this->revealReviewerDetails($review));
        });

        return response()->json($reviews);
    }

    public function show(Request $request, Review $review): JsonResponse
    {
        return response()->json($this->formatReview(
            $review->load(['doctor', 'user', 'appointment']),
            $this->revealReviewerDetails($review)
        ));
    }

    public function store(StoreReviewRequest $request): JsonResponse
    {
        $appointment = Appointment::find($request->appointment_id);

        if (! $appointment || $appointment->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Review can only be created for your own appointment.'], 403);
        }

        if ($appointment->doctor_id !== (int) $request->doctor_id) {
            return response()->json(['message' => 'Review can only be created for the doctor of the reviewed appointment.'], 403);
        }

        // Business rule (same criterion the DatabaseSeeder applies):
        // only completed appointments can be reviewed.
        if ($appointment->status !== 'completed') {
            return response()->json(['message' => 'Review can only be created for a completed appointment.'], 422);
        }

        // Review rule: a review also requires the payment to be settled.
        // The system's paid state is payment_status === 'paid', which is
        // written only by the admin payment endpoint or the signature-
        // verified Stripe webhook.
        if ($appointment->payment_status !== 'paid') {
            return response()->json(['message' => 'Review can only be created for a paid appointment.'], 422);
        }

        if (Review::where('appointment_id', $appointment->id)->exists()) {
            return response()->json(['message' => 'This appointment has already been reviewed.'], 422);
        }

        try {
            $review = Review::create([
                'user_id' => $request->user()->id,
                'doctor_id' => $request->doctor_id,
                'appointment_id' => $request->appointment_id,
                'rating' => $request->rating,
                'comment' => $request->comment ?? null,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Race: a concurrent request slipped past the exists() check
            // above; the unique index on reviews.appointment_id rejected
            // this insert, so the duplicate rule still holds.
            return response()->json(['message' => 'This appointment has already been reviewed.'], 422);
        }

        return response()->json($this->formatReview($review->load(['doctor', 'user', 'appointment'])), 201);
    }

    public function update(UpdateReviewRequest $request, Review $review): JsonResponse
    {
        if (
            $request->user()->role !== 'admin' &&
            $review->user_id !== $request->user()->id
        ) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $review->update($request->validated());

        return response()->json($this->formatReview($review->refresh()->load(['doctor', 'user', 'appointment'])));
    }

    public function destroy(Request $request, Review $review): JsonResponse
    {
        if (
            $request->user()->role !== 'admin' &&
            $review->user_id !== $request->user()->id
        ) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $review->delete();

        return response()->json(null, 204);
    }

    /**
     * SEC-20: when may the full reviewer/appointment shape be returned?
     *
     *  - administrators (moderation views keep everything);
     *  - the owner of the review viewing their own entry — that reveals
     *    only THEIR OWN id/name/appointment, i.e. data they already
     *    possess through /api/appointments. This is required by the
     *    patient "Write Review" gate (hooks/useReviewedAppointmentIds +
     *    ReviewButtonVisibilityTest), which reads `appointment.id` from
     *    this very endpoint as the logged-in owner.
     *
     * Everyone else — guests AND other authenticated users (registration
     * is open, so "logged in" is not a privacy boundary) — receives the
     * anonymized shape: no reviewer id, no reviewer name, no appointment
     * block (id/date), on BOTH index and show. Rating, comment, doctor
     * and review timestamps remain public. Emails/phones were never part
     * of this payload. Store/update responses are unchanged (full shape,
     * contract locked by ReviewAuthorizationTest).
     */
    private function revealReviewerDetails(Review $review): bool
    {
        $viewer = Auth::guard('sanctum')->user();

        if ($viewer === null) {
            return false;
        }

        return $viewer->role === 'admin' || $review->user_id === $viewer->id;
    }

    private function formatReview(Review $review, bool $revealReviewer = true): array
    {
        $formatted = [
            'id' => $review->id,
            'rating' => $review->rating,
            'comment' => $review->comment,
            'user' => [
                'name' => $revealReviewer ? $review->user->name : null,
            ],
            'doctor' => [
                'id' => $review->doctor->id,
                'name' => $review->doctor->name,
            ],
            'created_at' => $review->created_at?->toDateTimeString(),
            'updated_at' => $review->updated_at?->toDateTimeString(),
        ];

        if ($revealReviewer) {
            // Admin or the review's own owner: reviewer id + the
            // appointment link with its exact date.
            $formatted['user'] = [
                'id' => $review->user->id,
                'name' => $review->user->name,
            ];
            $formatted['appointment'] = [
                'id' => $review->appointment->id,
                'appointment_date' => $review->appointment->appointment_date?->toDateString(),
            ];
        }

        return $formatted;
    }
}
