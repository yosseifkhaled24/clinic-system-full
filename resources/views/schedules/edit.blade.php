<h1>Edit Schedule</h1>

<form action="{{ route('schedules.update', $schedule->id) }}" method="POST">
    @csrf
    @method('PUT')

    <select name="doctor_id">
        @foreach($doctors as $doctor)
            <option value="{{ $doctor->id }}"
                {{ $schedule->doctor_id == $doctor->id ? 'selected' : '' }}>
                {{ $doctor->name }}
            </option>
        @endforeach
    </select>

    <br><br>

    <input type="text"
           name="day"
           value="{{ $schedule->day }}">

    <br><br>

    <input type="time"
           name="start_time"
           value="{{ $schedule->start_time }}">

    <br><br>

    <input type="time"
           name="end_time"
           value="{{ $schedule->end_time }}">

    <br><br>

    <button type="submit">
        Update
    </button>
</form>