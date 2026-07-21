<h1>Add Doctor</h1>
@if ($errors->any())
    <div>
        @foreach ($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </div>
@endif
<form action="{{ route('doctors.store') }}"
      method="POST"
      enctype="multipart/form-data">
    @csrf

    <input type="text" name="name" placeholder="Doctor Name">
    <br><br>

    <input type="text" name="specialty" placeholder="Specialty">
    <br><br>

    <input type="email" name="email" placeholder="Email">
    <br><br>

    <input type="text" name="phone" placeholder="Phone">
    <br><br>

    <textarea name="bio" placeholder="Bio"></textarea>
    <br><br>
    <label>
    <input type="checkbox"
           name="allow_reviews"
           value="1"
           checked>

    Allow Reviews
</label>
<input type="file" name="image">
<br><br>
    <button type="submit">
        Save
    </button>
</form>