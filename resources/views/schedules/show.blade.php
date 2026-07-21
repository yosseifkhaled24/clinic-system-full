<h1>Schedule Details</h1>

<p>Doctor ID: {{ $schedule->doctor_id }}</p>

<p>Day: {{ $schedule->day }}</p>

<p>Start: {{ $schedule->start_time }}</p>

<p>End: {{ $schedule->end_time }}</p>

<a href="{{ route('schedules.index') }}">
    Back
</a>