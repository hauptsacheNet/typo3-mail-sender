<?php

declare(strict_types=1);

namespace Hn\MailSender\Service;

use Hn\MailSender\Validation\SenderAddressValidatorInterface;
use Hn\MailSender\Validation\ValueObject\ValidationResult;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Validation Service
 *
 * Orchestrates all registered validators and manages validation results.
 */
class ValidationService
{
    /**
     * @var array<SenderAddressValidatorInterface>
     */
    private array $validators = [];

    /**
     * @param iterable<SenderAddressValidatorInterface> $validators Tagged iterator of validators
     */
    public function __construct(iterable $validators)
    {
        // Convert iterable to array and sort by priority
        $this->validators = iterator_to_array($validators);
        usort($this->validators, fn($a, $b) => $a->getPriority() <=> $b->getPriority());
    }

    /**
     * Validate a sender address record by UID
     *
     * Loads the record from the database, runs all validators,
     * and updates the validation fields.
     *
     * @param int $uid The record UID
     * @return array<string, mixed> Aggregated validation results
     */
    public function validateSenderAddress(int $uid): array
    {
        // Load record
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mailsender_address');

        $record = $connection->select(
            ['sender_address'],
            'tx_mailsender_address',
            ['uid' => $uid]
        )->fetchAssociative();

        if (!$record) {
            throw new \RuntimeException('Sender address record not found: ' . $uid);
        }

        $email = $record['sender_address'];

        // Run validation
        $results = $this->validateEmail($email);

        // Update database
        $this->updateValidationResult($uid, $results);

        return $results;
    }

    /**
     * Validate an email address without database operations
     *
     * @param string $email The email address to validate
     * @return array<string, mixed> Aggregated validation results
     */
    public function validateEmail(string $email): array
    {
        $domain = $this->extractDomain($email);
        $validatorResults = [];
        $overallStatus = ValidationResult::STATUS_VALID;
        $errors = [];

        // Run all validators
        foreach ($this->validators as $validator) {
            try {
                $result = $validator->validate($email, $domain);
                $validatorResults[$validator->getName()] = $result->toArray();

                // Track overall status (invalid > warning > valid)
                if ($result->isInvalid()) {
                    $overallStatus = ValidationResult::STATUS_INVALID;
                    $errors = array_merge($errors, $result->getErrors());
                } elseif ($result->isWarning() && $overallStatus === ValidationResult::STATUS_VALID) {
                    $overallStatus = ValidationResult::STATUS_WARNING;
                }
            } catch (\Throwable $e) {
                $validatorResults[$validator->getName()] = [
                    'status' => ValidationResult::STATUS_INVALID,
                    'message' => 'Validator error: ' . $e->getMessage(),
                    'details' => ['exception' => get_class($e)],
                ];
                $overallStatus = ValidationResult::STATUS_INVALID;
                $errors[] = $validator->getName() . ' failed';
            }
        }

        return [
            'status' => $overallStatus,
            'email' => $email,
            'domain' => $domain,
            'timestamp' => time(),
            'validators' => $validatorResults,
            'errors' => $errors,
        ];
    }

    /**
     * Update validation result in database
     *
     * @param int $uid The record UID
     * @param array<string, mixed> $results Validation results
     */
    private function updateValidationResult(int $uid, array $results): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mailsender_address');

        $connection->update(
            'tx_mailsender_address',
            [
                'validation_status' => $results['status'],
                'validation_last_check' => $results['timestamp'],
                'validation_result' => json_encode($results, JSON_PRETTY_PRINT),
            ],
            ['uid' => $uid]
        );
    }

    /**
     * Extract domain from email address
     */
    private function extractDomain(string $email): string
    {
        $atPos = strrpos($email, '@');
        if ($atPos === false) {
            throw new \InvalidArgumentException('Invalid email address: ' . $email);
        }

        return substr($email, $atPos + 1);
    }

    /**
     * Get all registered validators
     *
     * @return array<SenderAddressValidatorInterface>
     */
    public function getValidators(): array
    {
        return $this->validators;
    }
}
