<?php

namespace App\Support\InvoiceBenchmark;

use Google\Auth\ApplicationDefaultCredentials;
use Google\Auth\FetchAuthTokenInterface;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\Encrypter;
use RuntimeException;
use Throwable;

class GoogleAccessTokenProvider
{
    public function __construct(
        private Repository $cache,
        private Encrypter $encrypter,
    ) {}

    public function token(string $project): string
    {
        // Only share a cached credential within the same ADC environment and effective user.
        $identity = [
            $project,
            getenv('GOOGLE_APPLICATION_CREDENTIALS'),
            getenv('GOOGLE_CLOUD_QUOTA_PROJECT'),
            getenv('HOME'),
            function_exists('posix_geteuid') ? posix_geteuid() : null,
        ];
        $key = 'invoice-benchmark:adc:'.hash('sha256', serialize($identity));

        try {
            $cached = $this->cache->get($key);

            if (is_string($cached)) {
                return $this->encrypter->decrypt($cached);
            }

            $issuedAt = now()->timestamp;
            $token = $this->credentials()->fetchAuthToken($this->httpHandler());
            $value = $token['access_token'] ?? null;
            if (! is_string($value) || $value === '') {
                throw new RuntimeException;
            }

            $expiry = (int) ($token['expires_at'] ?? ($issuedAt + (int) ($token['expires_in'] ?? 0)));
            $ttl = min(2700, $expiry - now()->timestamp - 60);
            if ($ttl > 0) {
                $this->cache->put($key, $this->encrypter->encrypt($value), $ttl);
            }

            return $value;
        } catch (Throwable) {
            throw new RuntimeException('Google Application Default Credentials could not provide a Vertex access token.');
        }
    }

    protected function credentials(): FetchAuthTokenInterface
    {
        return ApplicationDefaultCredentials::getCredentials('https://www.googleapis.com/auth/cloud-platform', $this->httpHandler(), logger: false);
    }

    private function httpHandler(): callable
    {
        return HttpHandlerFactory::build(new Client(['connect_timeout' => 2, 'timeout' => 4]), false);
    }
}
