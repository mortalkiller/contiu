<?php

use App\Modules\InvoiceReader\GoogleAccessTokenProvider;
use Google\Auth\Credentials\UserRefreshCredentials;
use Google\Auth\FetchAuthTokenInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\Encrypter;

it('resolves Google ADC from a synthetic authorized-user configuration', function () {
    $file = tempnam(sys_get_temp_dir(), 'contiu-adc-');
    $before = getenv('GOOGLE_APPLICATION_CREDENTIALS');
    $provider = new class(app(Repository::class), app(Encrypter::class)) extends GoogleAccessTokenProvider
    {
        public function resolved(): FetchAuthTokenInterface
        {
            return $this->credentials();
        }
    };
    try {
        file_put_contents($file, json_encode(['type' => 'authorized_user', 'client_id' => 'fake-client', 'client_secret' => 'fake-secret', 'refresh_token' => 'fake-refresh'], JSON_THROW_ON_ERROR));
        putenv('GOOGLE_APPLICATION_CREDENTIALS='.$file);

        expect($provider->resolved())->toBeInstanceOf(UserRefreshCredentials::class);
    } finally {
        putenv($before === false ? 'GOOGLE_APPLICATION_CREDENTIALS' : 'GOOGLE_APPLICATION_CREDENTIALS='.$before);
        unlink($file);
    }
});

it('caches a fetched ADC token and refreshes it before expiry', function () {
    $this->freezeTime();
    $credentials = Mockery::mock(FetchAuthTokenInterface::class);
    $credentials->shouldReceive('fetchAuthToken')->twice()
        ->andReturn(['access_token' => 'first-token', 'expires_in' => 120], ['access_token' => 'second-token', 'expires_in' => 120]);
    $provider = Mockery::mock(GoogleAccessTokenProvider::class, [app(Repository::class), app(Encrypter::class)])
        ->makePartial()->shouldAllowMockingProtectedMethods();
    $provider->shouldReceive('credentials')->twice()->andReturn($credentials);

    expect($provider->token('contiu-test'))->toBe('first-token');
    $this->travel(59)->seconds();
    expect($provider->token('contiu-test'))->toBe('first-token');
    $this->travel(2)->seconds();
    expect($provider->token('contiu-test'))->toBe('second-token');
});

it('returns a safe authentication failure without leaking upstream details', function () {
    $provider = Mockery::mock(GoogleAccessTokenProvider::class, [app(Repository::class), app(Encrypter::class)])
        ->makePartial()->shouldAllowMockingProtectedMethods();
    $provider->shouldReceive('credentials')->once()->andThrow(new RuntimeException('internal credential details'));

    expect(fn () => $provider->token('contiu-test'))->toThrow(RuntimeException::class, 'Google Application Default Credentials could not provide a Vertex access token.');
});
