<h1>Edit Service</h1>

<form action="{{ route('services.update', $service->id) }}" method="POST">
    @csrf
    @method('PUT')

    <input type="text"
           name="name"
           value="{{ $service->name }}">

    <br><br>

    <textarea name="description">{{ $service->description }}</textarea>

    <br><br>

    <input type="number"
           step="0.01"
           name="price"
           value="{{ $service->price }}">

    <br><br>

    <button type="submit">
        Update
    </button>
</form>