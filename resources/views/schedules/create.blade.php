<h1>Add Schedule</h1>

<form action="{{ route('schedules.store') }}" method="POST">
    @csrf

    <select name="doctor_id">
        @foreach($doctors as $doctor)
            <option value="{{ $doctor->id }}">
                {{ $doctor->name }}
            </option>
        @endforeach
    </select>

    <br><br>

    <input type="text" name="day" placeholder="Saturday">

    <br><br>

    <input type="time" name="start_time">

    <br><br>

    <input type="time" name="end_time">

    <br><br>

    <button type="submit">
        Save
    </button>
</form>