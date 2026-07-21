<h1>Add Appointment</h1>

<form action="{{ route('appointments.store') }}" method="POST">
    @csrf

    <label>Patient</label>
    <select name="user_id">
        @foreach($users as $user)
            <option value="{{ $user->id }}">
                {{ $user->name }}
            </option>
        @endforeach
    </select>

    <br><br>

    <label>Doctor</label>
    <select name="doctor_id">
        @foreach($doctors as $doctor)
            <option value="{{ $doctor->id }}">
                {{ $doctor->name }}
            </option>
        @endforeach
    </select>

    <br><br>

    <label>Date</label>
    <input type="datetime-local" name="appointment_date">

    <br><br>

    <label>Status</label>
    <select name="status">
        <option value="pending">Pending</option>
        <option value="approved">Approved</option>
        <option value="completed">Completed</option>
        <option value="cancelled">Cancelled</option>
    </select>

    <br><br>

    <label>Notes</label>
    <textarea name="notes"></textarea>

    <br><br>

    <button type="submit">
        Save
    </button>
</form>