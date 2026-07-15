<?php

namespace XaniaShield\Laravel;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Laravel client for the Xania Shield spam-analysis API.
 *
 * Idiomatic port of the official PHP SDK: uses the HTTP client and config()
 * instead of raw cURL and $_SERVER. Reads settings from config/shield.php.
 *
 * Typical use in a controller:
 *
 *     $verdict = $shield->checkRequest($request, [
 *         'email'   => $request->input('email'),
 *         'content' => $request->input('message'),
 *     ]);
 *     if ($verdict->isBlocked()) { ... }
 */
class ShieldClient
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private bool $failOpen;
    private ?string $timingSecret;

    public const TIMING_FIELD = 'xsh_tf';
    private const MAX_FILL_SECONDS = 86400;
    private const HEADER_NAME = 'X-Shield-Key';

    public function __construct()
    {
        $this->apiKey       = (string) config('shield.api_key', '');
        $this->baseUrl      = rtrim((string) config('shield.base_url', 'https://xaniashield.com/v1'), '/');
        $this->timeout      = (int) config('shield.timeout', 5);
        $this->failOpen     = (bool) config('shield.fail_open', true);
        $secret             = (string) config('shield.timing_secret', '');
        $this->timingSecret = $secret !== '' ? $secret : null;
    }

    /**
     * Whether the client is configured (has an API key). Lets callers skip
     * the check gracefully if Shield isn't set up yet.
     */
    public function configured(): bool
    {
        return $this->apiKey !== '' && str_starts_with($this->baseUrl, 'https://');
    }

    /**
     * Analyze a submission, auto-detecting visitor IP / UA / referer from the
     * given request and verifying the signed timing token if present.
     *
     * @param array<string,mixed> $data  Recognized keys: email, content, subject,
     *        name, fields (array), form_identifier, honeypot_field, honeypot_value.
     */
    public function checkRequest(Request $request, array $data = []): ShieldResult
    {
        // Auto-detect visitor context from the Laravel request.
        $data['visitor_ip']         ??= $request->ip();
        $data['visitor_user_agent'] ??= (string) $request->userAgent();
        $data['visitor_referer']    ??= (string) $request->headers->get('referer', '');
        $data['page_url']           ??= $request->fullUrl();

        // Honeypot: if the caller didn't pass one, read the configured field.
        if (!array_key_exists('honeypot_value', $data)) {
            $hpField = (string) config('shield.honeypot_field', 'website_url');
            $data['honeypot_field'] = $hpField;
            $data['honeypot_value'] = (string) $request->input($hpField, '');
        }

        // Timing: verify the signed token and compute server-side fill time.
        $token = (string) $request->input(config('shield.timing_field', self::TIMING_FIELD), '');
        $elapsed = $this->elapsedFromToken($token);
        if ($elapsed !== null) {
            $data['form_fill_time'] = $elapsed;
        }

        return $this->analyze($data);
    }

    /**
     * Low-level analyze (no request auto-detection).
     *
     * @param array<string,mixed> $data
     */
    public function analyze(array $data): ShieldResult
    {
        if (!$this->configured()) {
            // Not set up — fail open/closed per config, but log once so it's visible.
            Log::warning('ShieldClient: not configured (missing API key); using fallback.');
            return ShieldResult::failure($this->failOpen, 'not_configured');
        }

        $payload = $this->buildPayload($data);

        try {
            $response = Http::withHeaders([self::HEADER_NAME => $this->apiKey])
                ->timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->post($this->baseUrl . '/analyze', $payload);
        } catch (Throwable $e) {
            return ShieldResult::failure($this->failOpen, 'network_error: ' . $e->getMessage());
        }

        $status = $response->status();
        $body   = $response->json();

        if ($status === 200 && is_array($body)) {
            return ShieldResult::fromApi($body);
        }

        if ($status === 401 || $status === 403) {
            Log::error("ShieldClient: API authentication failed (HTTP {$status}) — check API key.");
            return ShieldResult::failure($this->failOpen, 'auth_error', $status);
        }
        if ($status === 429) {
            Log::warning('ShieldClient: API quota exceeded (HTTP 429).');
            return ShieldResult::failure($this->failOpen, 'quota_exceeded', $status);
        }

        $message = is_array($body) && isset($body['message'])
            ? (string) $body['message']
            : "Unexpected HTTP {$status}";

        return ShieldResult::failure($this->failOpen, $message, $status);
    }

    /**
     * Health check against the API.
     */
    public function health(): bool
    {
        if (!$this->configured()) {
            return false;
        }
        try {
            $response = Http::withHeaders([self::HEADER_NAME => $this->apiKey])
                ->timeout($this->timeout)
                ->acceptJson()
                ->get($this->baseUrl . '/health');
            return $response->status() === 200
                && ($response->json('status') === 'ok');
        } catch (Throwable $e) {
            return false;
        }
    }

    // ── Timing helpers ────────────────────────────────────────────────

    /**
     * Hidden input carrying an HMAC-signed render timestamp. Echo inside a form.
     * Use {!! $shield->timingField() !!} in Blade (already escaped internally).
     */
    public function timingField(): string
    {
        if ($this->timingSecret === null) {
            return ''; // No secret -> timing disabled; render nothing.
        }
        $ts    = time();
        $token = $ts . '.' . $this->signTimestamp($ts);
        $name  = htmlspecialchars((string) config('shield.timing_field', self::TIMING_FIELD), ENT_QUOTES);

        return '<input type="hidden" name="' . $name
            . '" value="' . htmlspecialchars($token, ENT_QUOTES) . '">';
    }

    public function timingToken(): ?string
    {
        if ($this->timingSecret === null) {
            return null;
        }
        $ts = time();
        return $ts . '.' . $this->signTimestamp($ts);
    }

    /**
     * Static HTML for a hidden honeypot field, using the configured field name.
     * Rendered by the @shieldHoneypot Blade directive. Bots fill it; humans don't.
     */
    public static function honeypotFieldHtml(): string
    {
        $field = htmlspecialchars((string) config('shield.honeypot_field', 'website_url'), ENT_QUOTES);
        return '<div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">'
            . '<label>Leave this field empty'
            . '<input type="text" name="' . $field . '" tabindex="-1" autocomplete="off" value=""></label>'
            . '</div>';
    }

    // ── Internals ─────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function buildPayload(array $data): array
    {
        $allowed = [
            'email', 'content', 'subject', 'name', 'fields', 'form_identifier',
            'honeypot_field', 'honeypot_value', 'visitor_ip', 'visitor_user_agent',
            'visitor_referer', 'page_url', 'form_fill_time', 'submitted_at',
        ];
        $payload = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                $payload[$key] = $data[$key];
            }
        }
        // 'fields' must be an array if present.
        if (isset($payload['fields']) && !is_array($payload['fields'])) {
            unset($payload['fields']);
        }
        return $payload;
    }

    private function elapsedFromToken(?string $token): ?float
    {
        if ($this->timingSecret === null) {
            return null;
        }
        if (!is_string($token) || $token === '' || !str_contains($token, '.')) {
            return null;
        }
        [$tsPart, $sig] = explode('.', $token, 2);
        if (!ctype_digit($tsPart)) {
            return null;
        }
        $ts = (int) $tsPart;
        $expected = $this->signTimestamp($ts);
        if (!hash_equals($expected, $sig)) {
            return null; // Tampered or wrong secret.
        }
        $elapsed = time() - $ts;
        if ($elapsed < 0 || $elapsed > self::MAX_FILL_SECONDS) {
            return null; // Clock skew or stale tab; treat as unknown.
        }
        return (float) $elapsed;
    }

    private function signTimestamp(int $ts): string
    {
        return hash_hmac('sha256', (string) $ts, (string) $this->timingSecret);
    }
}
