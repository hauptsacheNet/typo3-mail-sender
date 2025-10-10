<?php

declare(strict_types=1);

namespace Hn\MailSender\Validation;

use Hn\MailSender\Validation\ValueObject\ValidationResult;

/**
 * Interface for sender address validators
 *
 * All validators implementing this interface will be automatically
 * tagged and injected into the ValidationService via dependency injection.
 */
interface SenderAddressValidatorInterface
{
    /**
     * Validate a sender email address
     *
     * @param string $email The full email address to validate
     * @param string $domain The domain part of the email address
     * @return ValidationResult The validation result
     */
    public function validate(string $email, string $domain): ValidationResult;

    /**
     * Get the validator name for display and logging
     *
     * @return string The validator name (e.g., "DNS Validator", "SMTP Existence Check")
     */
    public function getName(): string;

    /**
     * Get the validator priority
     *
     * Lower numbers run first (e.g., 5 for syntax, 10 for DNS, 20 for SMTP)
     *
     * @return int The priority value
     */
    public function getPriority(): int;
}
