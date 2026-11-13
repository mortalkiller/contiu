{{-- An image still to be made: a dashed box naming the expected file, as in the mocks. Replace with <img class="st-illu"> once the file exists. --}}
<figure class="st-ph {{ $class ?? '' }}" style="{{ $style ?? '' }}" aria-hidden="true"><span>{{ $label }}</span><b>{{ $file }}</b>@isset($size)<span>{{ $size }}</span>@endisset</figure>
