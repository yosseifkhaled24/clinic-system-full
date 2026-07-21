<h1>Edit Review</h1>

<form action="{{ route('reviews.update', $review->id) }}" method="POST">
    @csrf
    @method('PUT')

    <select name="user_id">
        @foreach($users as $user)
            <option value="{{ $user->id }}"
                {{ $review->user_id == $user->id ? 'selected' : '' }}>
                {{ $user->name }}
            </option>
        @endforeach
    </select>

    <br><br>

    <select name="doctor_id">
        @foreach($doctors as $doctor)
            <option value="{{ $doctor->id }}"
                {{ $review->doctor_id == $doctor->id ? 'selected' : '' }}>
                {{ $doctor->name }}
            </option>
        @endforeach
    </select>

    <br><br>

    <select name="appointment_id">
        @foreach($appointments as $appointment)
            <option value="{{ $appointment->id }}"
                {{ $review->appointment_id == $appointment->id ? 'selected' : '' }}>
                Appointment #{{ $appointment->id }}
            </option>
        @endforeach
    </select>

    <br><br>

    <input type="number"
           min="1"
           max="5"
           name="rating"
           value="{{ $review->rating }}">

    <br><br>

    <textarea name="comment">{{ $review->comment }}</textarea>

    <br><br>

    <button type="submit">
        Update
    </button>
</form>