<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Cloudflare;

use CurlHandle;
use RuntimeException;

/**
 * Minimal Cloudflare v4 client. Reuses a single cURL handle for connection
 * pooling (~10 ms per request saved on TLS handshake when syncing batches).
 * Surfaces rate-limit and Retry-After headers so the queue can back off
 * instead of hammering the API.
 *
 * Not `final` so tests can subclass and stub `createRecord`/`updateRecord`
 * without spinning up a real cURL handle — see ProcessEventTest.
 *
 * @phpstan-type Record array<string, mixed>
 */
class CloudflareApiClient
{
    private const BASE_URL = 'https://api.cloudflare.com/client/v4';
    private const USER_AGENT = 'zonemirror/1.0 (+https://github.com/zonemirror/zonemirror)';
    private const TIMEOUT_SECONDS = 20;

    private ?CurlHandle $handle = null;

    public function __construct(private readonly string $token)
    {
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            curl_close($this->handle);
        }
    }

    public function verifyToken(): bool
    {
        return $this->verifyTokenStatus() === 'active';
    }

    /**
     * Raw Cloudflare token status. Returns the literal string CF gives us
     * (typically "active", "expired", "disabled") or '' when CF rejects the
     * request before returning a status (e.g. HTTP 401 — token is just
     * wrong, not expired). Callers turn this into AdminToken::STATUS_*.
     */
    public function verifyTokenStatus(): string
    {
        $resp = $this->send('GET', '/user/tokens/verify');
        if (!$resp->isSuccess()) {
            return '';
        }

        return (string) ($resp->body['result']['status'] ?? '');
    }

    public function findZoneId(string $zoneName): ?string
    {
        $resp = $this->send('GET', '/zones?' . http_build_query(['name' => $zoneName]));
        if (!$resp->isSuccess()) {
            return null;
        }
        $id = $resp->body['result'][0]['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * @return list<Record>
     */
    public function listZones(): array
    {
        return $this->paginate('/zones');
    }

    /**
     * @param array{type?: string, name?: string} $filter
     * @return list<Record>
     */
    public function listRecords(string $zoneId, array $filter = []): array
    {
        return $this->paginate("/zones/$zoneId/dns_records", $filter);
    }

    /**
     * @param Record $record
     * @return Record
     */
    public function createRecord(string $zoneId, array $record): array
    {
        $resp = $this->send('POST', "/zones/$zoneId/dns_records", $record);

        return $this->resultOrThrow($resp, 'create record');
    }

    /**
     * @param Record $record
     * @return Record
     */
    public function updateRecord(string $zoneId, string $recordId, array $record): array
    {
        $resp = $this->send('PUT', "/zones/$zoneId/dns_records/$recordId", $record);

        return $this->resultOrThrow($resp, 'update record');
    }

    public function deleteRecord(string $zoneId, string $recordId): void
    {
        $resp = $this->send('DELETE', "/zones/$zoneId/dns_records/$recordId");
        $this->resultOrThrow($resp, 'delete record');
    }

    /**
     * @param non-empty-string $method
     * @param non-empty-string $path
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body = null): HttpResponse
    {
        $url = self::BASE_URL . $path;
        $handle = $this->handle();

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->token,
            'User-Agent: ' . self::USER_AGENT,
        ];

        $postFields = null;
        if ($body !== null) {
            $encoded = json_encode($body);
            // json_encode only returns false on encoding errors (NaN, INF,
            // malformed UTF-8). Our payloads are scalar arrays so this is
            // a runtime guard, not a path we expect to hit.
            if ($encoded !== false) {
                $postFields = $encoded;
            }
        }

        curl_setopt($handle, CURLOPT_URL, $url);
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_HEADER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 2);
        if ($postFields !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $postFields);
        }

        $raw = curl_exec($handle);
        if ($raw === false) {
            $err = curl_error($handle);

            throw new CloudflareException('Network error: ' . $err, 0, true);
        }
        if (!is_string($raw)) {
            throw new CloudflareException('Unexpected response type', 0, true);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $rawHeaders = substr($raw, 0, $headerSize);
        $rawBody = substr($raw, $headerSize);

        $decodedRaw = json_decode($rawBody, true);
        /** @var array<string, mixed> $decoded */
        $decoded = is_array($decodedRaw) ? $decodedRaw : [];

        return new HttpResponse(
            status: $status,
            body: $decoded,
            retryAfterSeconds: $this->parseRetryAfter($rawHeaders),
            rateLimitRemaining: $this->parseRateLimitRemaining($rawHeaders),
        );
    }

    private function handle(): CurlHandle
    {
        if ($this->handle === null) {
            $h = curl_init();
            if ($h === false) {
                throw new RuntimeException('Unable to init cURL handle.');
            }
            $this->handle = $h;
        }

        return $this->handle;
    }

    /**
     * @param array<string, mixed> $query
     * @return list<Record>
     */
    private function paginate(string $path, array $query = []): array
    {
        $page = 1;
        $perPage = 100;
        $out = [];
        do {
            $q = array_merge($query, ['page' => $page, 'per_page' => $perPage]);
            $resp = $this->send('GET', $path . '?' . http_build_query($q));
            if (!$resp->isSuccess()) {
                break;
            }
            /** @var list<Record> $items */
            $items = is_array($resp->body['result'] ?? null) ? $resp->body['result'] : [];
            $out = array_merge($out, $items);
            $totalPages = (int) ($resp->body['result_info']['total_pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages);

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function resultOrThrow(HttpResponse $resp, string $action): array
    {
        if ($resp->isSuccess()) {
            /** @var array<string, mixed> $result */
            $result = is_array($resp->body['result'] ?? null) ? $resp->body['result'] : [];

            return $result;
        }

        $errors = $resp->body['errors'] ?? [];
        $message = is_array($errors) && isset($errors[0]['message'])
            ? (string) $errors[0]['message']
            : ('HTTP ' . $resp->status);
        $cfCode = is_array($errors) && isset($errors[0]['code']) && is_numeric($errors[0]['code'])
            ? (int) $errors[0]['code']
            : null;

        $retryable = $resp->status === 429 || $resp->status >= 500;

        throw new CloudflareException(
            "Cloudflare $action failed: $message",
            httpStatus: $resp->status,
            retryable: $retryable,
            retryAfterSeconds: $resp->retryAfterSeconds,
            cloudflareCode: $cfCode,
        );
    }

    private function parseRetryAfter(string $headers): ?int
    {
        if (preg_match('/^Retry-After:\s*(\d+)/im', $headers, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    private function parseRateLimitRemaining(string $headers): ?int
    {
        if (preg_match('/^X-RateLimit-Remaining:\s*(\d+)/im', $headers, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }
}
