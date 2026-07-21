<h1>Edit Doctor</h1>

<form action="{{ route('doctors.update', $doctor->id) }}" method="POST">
    @csrf
    @method('PUT')

    <input type="text" name="name" value="{{ $doctor->name }}">
    <br><br>

    <input type="text" name="specialty" value="{{ $doctor->specialty }}">
    <br><br>

    <input type="email" name="email" value="{{ $doctor->email }}">
    <br><br>

    <input type="text" name="phone" value="{{ $doctor->phone }}">
    <br><br>

    <textarea name="bio">{{ $doctor->bio }}</textarea>
    <br><br>

    <button type="submit">
        Update
    </button>
</form>