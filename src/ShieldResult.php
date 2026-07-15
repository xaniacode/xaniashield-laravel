<?php

namespace XaniaShield\Laravel;

/**
 * Verdict returned by the Xania Shield API (or a fail-open/closed fallback).
 *
 * Mirrors the official PHP SDK's ShieldResult so behaviour is identical, but
 * lives in the app namespace for Laravel autoloading.
 */
final class ShieldResult
{
    public const ACTION_ALLOW     = 'allow';
    public const ACTION_BLOCK     = 'block';
    public const ACTION_CHALLENGE = 'challenge';

    private function __construct(
        private string $action,
        private int $score,
        /** @var string[] */
        private array $reasons,
        private ?string $requestId,
        private ?int $processingTimeMs,
        private bool $failedOpen,
        private ?int $httpStatus,
    ) {}

    public static function fromApi(array $body): self
    {
        return new self(
            action: (string) ($body['action'] ?? self::ACTION_ALLOW),
            score: (int) ($body['score'] ?? 0),
            reasons: is_array($body['reasons'] ?? null) ? $body['reasons'] : [],
            requestId: isset($body['request_id']) ? (string) $body['request_id'] : null,
            processingTimeMs: isset($body['processing_time_ms']) ? (int) $body['processing_time_ms'] : null,
            failedOpen: false,
            httpStatus: 200,
        );
    }

    public static function failure(bool $failOpen, string $reason, ?int $httpStatus = null): self
    {
        return new self(
            action: $failOpen ? self::ACTION_ALLOW : self::ACTION_BLOCK,
            score: 0,
            reasons: ['sdk_' . ($failOpen ? 'fail_open' : 'fail_closed'), $reason],
            requestId: null,
            processingTimeMs: null,
            failedOpen: true,
            httpStatus: $httpStatus,
        );
    }

    public function isAllowed(): bool       { return $this->action === self::ACTION_ALLOW; }
    public function isBlocked(): bool        { return $this->action === self::ACTION_BLOCK; }
    public function shouldChallenge(): bool  { return $this->action === self::ACTION_CHALLENGE; }
    public function failedOpen(): bool       { return $this->failedOpen; }

    public function action(): string         { return $this->action; }
    public function score(): int             { return $this->score; }
    /** @return string[] */
    public function reasons(): array         { return $this->reasons; }
    public function requestId(): ?string     { return $this->requestId; }
    public function processingTimeMs(): ?int { return $this->processingTimeMs; }
    public function httpStatus(): ?int       { return $this->httpStatus; }

    public function toArray(): array
    {
        return [
            'action'             => $this->action,
            'score'              => $this->score,
            'reasons'            => $this->reasons,
            'request_id'         => $this->requestId,
            'processing_time_ms' => $this->processingTimeMs,
            'failed_open'        => $this->failedOpen,
            'http_status'        => $this->httpStatus,
        ];
    }
}
