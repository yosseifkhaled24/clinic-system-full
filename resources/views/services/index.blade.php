<h1>Services List</h1>

<a href="{{ route('services.create') }}">
    Add Service
</a>

<hr>

@foreach($services as $service)

    <div style="margin-bottom:20px;">

        @if($service->getFirstMediaUrl('services'))
            <img
                src="{{ $service->getFirstMediaUrl('services') }}"
                width="120"
                height="120"
                style="object-fit:cover;"
            >
            <br><br>
        @endif

        <strong>{{ $service->name }}</strong>
        <br>

        Price: {{ $service->price }}
        <br><br>

        <a href="{{ route('services.show', $service->id) }}">
            View
        </a>

        <a href="{{ route('services.edit', $service->id) }}">
            Edit
        </a>

        <form action="{{ route('services.destroy', $service->id) }}"
              method="POST"
              style="display:inline;">
            @csrf
            @method('DELETE')

            <button type="submit">
                Delete
            </button>
        </form>

    </div>

    <hr>

@endforeach