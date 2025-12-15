<?php

declare(strict_types=1);

namespace Hn\MailSender\Validation\ValueObject;

/**
 * Validation result value object
 *
 * Represents the result of a single validator or aggregated validation results.
 */
class ValidationResult
{
    public const STATUS_VALID = 'valid';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_WARNING = 'warning';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_PENDING = 'pending';

    /**
     * @param string $status One of: valid, invalid, warning, skipped, pending
     * @param string $message Human-readable message describing the result
     * @param array<string, mixed> $details Additional details (e.g., DNS records, error codes)
     */
    public function __construct(
        private readonly string $status,
        private readonly string $message,
        private readonly array $details = []
    ) {
    }

    /**
     * Create a valid result
     */
    public static function valid(string $message = 'Validation passed', array $details = []): self
    {
        return new self(self::STATUS_VALID, $message, $details);
    }

    /**
     * Create an invalid result
     */
    public static function invalid(string $message, array $details = []): self
    {
        return new self(self::STATUS_INVALID, $message, $details);
    }

    /**
     * Create a warning result
     */
    public static function warning(string $message, array $details = []): self
    {
        return new self(self::STATUS_WARNING, $message, $details);
    }

    /**
     * Create a skipped result (validator could not perform its check)
     */
    public static function skipped(string $message, array $details = []): self
    {
        return new self(self::STATUS_SKIPPED, $message, $details);
    }

    /**
     * Create a pending result
     */
    public static function pending(string $message = 'Validation pending', array $details = []): self
    {
        return new self(self::STATUS_PENDING, $message, $details);
    }

    /**
     * Check if the result is valid
     */
    public function isValid(): bool
    {
        return $this->status === self::STATUS_VALID;
    }

    /**
     * Check if the result is invalid
     */
    public function isInvalid(): bool
    {
        return $this->status === self::STATUS_INVALID;
    }

    /**
     * Check if the result is a warning
     */
    public function isWarning(): bool
    {
        return $this->status === self::STATUS_WARNING;
    }

    /**
     * Check if the result was skipped
     */
    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }

    /**
     * Get the status
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Get the message
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Get the details
     *
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * Get errors from details (if any)
     *
     * @return array<string>
     */
    public function getErrors(): array
    {
        return $this->details['errors'] ?? [];
    }

    /**
     * Convert to array for JSON serialization
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'details' => $this->details,
        ];
    }
}
