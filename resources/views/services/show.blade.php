<h1>{{ $service->name }}</h1>

<p>{{ $service->description }}</p>

<p>Price: {{ $service->price }}</p>

<a href="{{ route('services.index') }}">
    Back
</a>