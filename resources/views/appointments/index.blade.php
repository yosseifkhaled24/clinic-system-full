<h1>Appointments List</h1>

<a href="{{ route('appointments.create') }}">
    Add Appointment
</a>

<hr>

@foreach($appointments as $appointment)

    <p>
        Patient ID: {{ $appointment->user_id }}
        |
        Doctor ID: {{ $appointment->doctor_id }}
        |
        {{ $appointment->appointment_date }}
        |
        {{ $appointment->status }}

        <a href="{{ route('appointments.show', $appointment->id) }}">
            View
        </a>

        <a href="{{ route('appointments.edit', $appointment->id) }}">
            Edit
        </a>

        <form action="{{ route('appointments.destroy', $appointment->id) }}"
              method="POST"
              style="display:inline;">
            @csrf
            @method('DELETE')

            <button type="submit">
                Delete
            </button>
        </form>
    </p>

@endforeach