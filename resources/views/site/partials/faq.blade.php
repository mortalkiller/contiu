{{-- Questions of one list (App\Support\SiteFaq), as <details> as in the mocks. --}}
<div class="st-faq">@foreach ($items as $item)<details id="{{ $item['id'] }}" @if ($item['open']) open @endif><summary>{{ $item['question'] }}</summary><p>{{ $item['answer'] }}</p></details>@endforeach</div>
