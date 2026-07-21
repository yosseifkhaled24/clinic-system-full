<h1>Doctors List</h1>

<a href="{{ route('doctors.create') }}">
    Add Doctor
</a>

<hr>

@foreach($doctors as $doctor)

    <div style="margin-bottom:20px;">

        @if($doctor->getFirstMediaUrl('doctors'))
            <img
                src="{{ $doctor->getFirstMediaUrl('doctors') }}"
                width="120"
                height="120"
                style="object-fit:cover;"
            >
        @endif

        <p>{{ $doctor->name }}</p>

    </div>

@endforeach