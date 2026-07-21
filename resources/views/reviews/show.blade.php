<h1>Review Details</h1>

<p>User ID: {{ $review->user_id }}</p>

<p>Doctor ID: {{ $review->doctor_id }}</p>

<p>Appointment ID: {{ $review->appointment_id }}</p>

<p>Rating: {{ $review->rating }}</p>

<p>Comment: {{ $review->comment }}</p>

<a href="{{ route('reviews.index') }}">
    Back
</a>