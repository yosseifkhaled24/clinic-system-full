<h1>Edit Appointment</h1>

<form action="{{ route('appointments.update', $appointment->id) }}" method="POST">
    @csrf
    @method('PUT')

    <select name="user_id">
        @foreach($users as $user)
            <option value="{{ $user->id }}"
                {{ $appointment->user_id == $user->id ? 'selected' : '' }}>
                {{ $user->name }}
            </option>
        @endforeach
    </select>

    <br><br>

    <select name="doctor_id">
        @foreach($doctors as $doctor)
            <option value="{{ $doctor->id }}"
                {{ $appointment->doctor_id == $doctor->id ? 'selected' : '' }}>
                {{ $doctor->name }}
            </option>
        @endforeach
    </select>

    <br><br>

    <input type="datetime-local"
           name="appointment_date"
           value="{{ date('Y-m-d\TH:i', strtotime($appointment->appointment_date)) }}">

    <br><br>

    <select name="status">
        <option value="pending" {{ $appointment->status == 'pending' ? 'selected' : '' }}>Pending</option>
        <option value="approved" {{ $appointment->status == 'approved' ? 'selected' : '' }}>Approved</option>
        <option value="completed" {{ $appointment->status == 'completed' ? 'selected' : '' }}>Completed</option>
        <option value="cancelled" {{ $appointment->status == 'cancelled' ? 'selected' : '' }}>Cancelled</option>
    </select>

    <br><br>

    <textarea name="notes">{{ $appointment->notes }}</textarea>

    <br><br>

    <button type="submit">
        Update
    </button>
</form>