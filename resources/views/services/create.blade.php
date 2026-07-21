<h1>Add Service</h1>

<form action="{{ route('services.store') }}" method="POST"
enctype="multipart/form-data">
    @csrf

    <input type="text" name="name" placeholder="Service Name">
    <br><br>

    <textarea name="description"></textarea>
    <br><br>

    <input type="number" step="0.01" name="price" placeholder="Price">
    <br><br>

    <input type="file" name="image">
    <br><br>

    <button type="submit">
        Save
    </button>
</form>