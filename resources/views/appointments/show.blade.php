<h1>Appointment Details</h1>

<p>User ID: {{ $appointment->user_id }}</p>
<p>Doctor ID: {{ $appointment->doctor_id }}</p>
<p>Date: {{ $appointment->appointment_date }}</p>
<p>Status: {{ $appointment->status }}</p>
<p>Notes: {{ $appointment->notes }}</p>

<a href="{{ route('appointments.index') }}">
    Back
</a>