<h1>Add Review</h1>

<form action="{{ route('reviews.store') }}" method="POST">
    @csrf

    <label>User</label>
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

    <label>Appointment</label>
    <select name="appointment_id">
        @foreach($appointments as $appointment)
            <option value="{{ $appointment->id }}">
                Appointment #{{ $appointment->id }}
            </option>
        @endforeach
    </select>

    <br><br>

    <label>Rating</label>
    <input type="number" min="1" max="5" name="rating">

    <br><br>

    <label>Comment</label>
    <textarea name="comment"></textarea>

    <br><br>

    <button type="submit">
        Save
    </button>
</form>