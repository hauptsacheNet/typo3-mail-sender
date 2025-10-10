<?php

declare(strict_types=1);

namespace Hn\MailSender\Validation\Validator;

use Hn\MailSender\Validation\SenderAddressValidatorInterface;
use Hn\MailSender\Validation\ValueObject\ValidationResult;

/**
 * Email Syntax Validator
 *
 * Validates email address syntax according to RFC 5322 standards.
 * Runs before DNS checks to catch obvious syntax errors early.
 */
class EmailSyntaxValidator implements SenderAddressValidatorInterface
{
    public function validate(string $email, string $domain): ValidationResult
    {
        $details = [];
        $errors = [];

        // Basic filter_var validation (RFC 5322)
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address syntax';
        }

        // Check for common issues
        if (strpos($email, '@') === false) {
            $errors[] = 'Missing @ symbol';
        } elseif (substr_count($email, '@') > 1) {
            $errors[] = 'Multiple @ symbols found';
        }

        // Check local part (before @)
        $atPos = strrpos($email, '@');
        if ($atPos !== false) {
            $localPart = substr($email, 0, $atPos);

            if (empty($localPart)) {
                $errors[] = 'Empty local part (before @)';
            } elseif (strlen($localPart) > 64) {
                $errors[] = 'Local part exceeds 64 characters';
            }

            $details['local_part'] = $localPart;
        }

        // Check domain part
        if (empty($domain)) {
            $errors[] = 'Empty domain part (after @)';
        } elseif (strlen($domain) > 255) {
            $errors[] = 'Domain exceeds 255 characters';
        } elseif (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*$/', $domain)) {
            $errors[] = 'Invalid domain format';
        }

        $details['domain'] = $domain;
        $details['email_length'] = strlen($email);

        // Determine overall status
        if (!empty($errors)) {
            return ValidationResult::invalid(
                'Email syntax validation failed: ' . implode(', ', $errors),
                ['errors' => $errors, ...$details]
            );
        }

        return ValidationResult::valid(
            'Email syntax is valid',
            $details
        );
    }

    public function getName(): string
    {
        return 'Email Syntax Validator';
    }

    public function getPriority(): int
    {
        return 5; // Run first, before DNS/SMTP checks
    }
}
