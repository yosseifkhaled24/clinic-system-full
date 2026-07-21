<h1>Schedules List</h1>

<a href="{{ route('schedules.create') }}">
    Add Schedule
</a>

<hr>

@foreach($schedules as $schedule)

    <p>
        Doctor ID: {{ $schedule->doctor_id }}
        |
        {{ $schedule->day }}
        |
        {{ $schedule->start_time }}
        -
        {{ $schedule->end_time }}

        <a href="{{ route('schedules.show', $schedule->id) }}">
            View
        </a>

        <a href="{{ route('schedules.edit', $schedule->id) }}">
            Edit
        </a>

        <form action="{{ route('schedules.destroy', $schedule->id) }}"
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