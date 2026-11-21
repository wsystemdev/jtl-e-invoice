<?php declare(strict_types=1);

namespace Plugin\wsystem_einvoice;

/** Client for the W System API. Transport is injectable for tests. */
final class Api
{
    public const BASE = 'https://api.wsystem.dev';

    /** @var callable|null fn(string $url, array $headers, string $json): array{0:int,1:string} */
    private $transport;
    private string $key;
    private string $ua;

    public function __construct(string $key, string $version, ?callable $transport = null, string $shopUrl = '')
    {
        $this->key = trim($key);
        $this->transport = $transport;
        $this->ua = 'wsystem-jtl-e-invoice/' . $version . ($shopUrl !== '' ? '; ' . $shopUrl : '');
    }

    /** @param array<string,mixed> $body @return array{ok:bool,status:int,data:array<string,mixed>,error:string} */
    public function post(string $path, array $body): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json', 'User-Agent: ' . $this->ua];
        if ($this->key !== '') {
            $headers[] = 'X-API-Key: ' . $this->key;
        }
        $json = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            [$status, $raw] = $this->transport
                ? ($this->transport)(self::BASE . $path, $headers, $json)
                : $this->curl(self::BASE . $path, $headers, $json);
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => $e->getMessage()];
        }
        $data = json_decode($raw, true);
        $data = is_array($data) ? $data : [];
        $err = ($status < 200 || $status >= 300) ? self::errorText($status, $data) : '';
        return ['ok' => $err === '', 'status' => $status, 'data' => $data, 'error' => $err];
    }

    /** @param array<string,mixed> $data */
    public static function errorText(int $status, array $data): string
    {
        if ($status === 402 || $status === 429) {
            return 'The free daily quota of the W System API is used up. Add an API key in the plugin settings or try again tomorrow.';
        }
        $d = $data['detail'] ?? $data['error'] ?? '';
        if (is_array($d)) {
            $d = $d['message'] ?? json_encode($d);
        }
        return sprintf('W System API error %d: %s', $status, (string) $d);
    }

    /** @param string[] $headers @return array{0:int,1:string} */
    private function curl(string $url, array $headers, string $json): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP curl extension is required.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 10]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $e = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException($e);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, (string) $raw];
    }
}
