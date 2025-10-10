<?php

declare(strict_types=1);

namespace Hn\MailSender\Command;

use Hn\MailSender\Service\ValidationService;
use Hn\MailSender\Validation\ValueObject\ValidationResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Console command to validate sender email addresses
 *
 * Usage:
 *   typo3 mail:sender:validate --email=test@example.com
 *   typo3 mail:sender:validate --uid=1
 *   typo3 mail:sender:validate --all
 */
class ValidateSenderCommand extends Command
{
    public function __construct(
        private readonly ValidationService $validationService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Validate email sender addresses')
            ->setHelp('Validates sender email addresses using DNS checks and other validators.')
            ->addOption(
                'email',
                'e',
                InputOption::VALUE_REQUIRED,
                'Validate a specific email address'
            )
            ->addOption(
                'uid',
                'u',
                InputOption::VALUE_REQUIRED,
                'Validate sender address by record UID'
            )
            ->addOption(
                'all',
                'a',
                InputOption::VALUE_NONE,
                'Validate all sender addresses in the database'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getOption('email');
        $uid = $input->getOption('uid');
        $all = $input->getOption('all');

        // Validate options
        if (!$email && !$uid && !$all) {
            $io->error('Please specify --email, --uid, or --all');
            return Command::FAILURE;
        }

        if (($email ? 1 : 0) + ($uid ? 1 : 0) + ($all ? 1 : 0) > 1) {
            $io->error('Please specify only one of --email, --uid, or --all');
            return Command::FAILURE;
        }

        try {
            if ($email) {
                return $this->validateByEmail($email, $io);
            }

            if ($uid) {
                return $this->validateByUid((int)$uid, $io);
            }

            if ($all) {
                return $this->validateAll($io);
            }
        } catch (\Throwable $e) {
            $io->error('Validation failed: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function validateByEmail(string $email, SymfonyStyle $io): int
    {
        $io->title('Validating email: ' . $email);

        $results = $this->validationService->validateEmail($email);

        $this->displayResults($results, $io);

        return $results['status'] === ValidationResult::STATUS_VALID
            ? Command::SUCCESS
            : Command::FAILURE;
    }

    private function validateByUid(int $uid, SymfonyStyle $io): int
    {
        $io->title('Validating sender address record UID: ' . $uid);

        $results = $this->validationService->validateSenderAddress($uid);

        $io->success('Validation results saved to database');
        $this->displayResults($results, $io);

        return $results['status'] === ValidationResult::STATUS_VALID
            ? Command::SUCCESS
            : Command::FAILURE;
    }

    private function validateAll(SymfonyStyle $io): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mailsender_address');

        $records = $connection->select(
            ['uid', 'sender_address'],
            'tx_mailsender_address',
            ['deleted' => 0, 'hidden' => 0]
        )->fetchAllAssociative();

        if (empty($records)) {
            $io->warning('No sender address records found');
            return Command::SUCCESS;
        }

        $io->title('Validating ' . count($records) . ' sender address(es)');

        $io->progressStart(count($records));

        $successCount = 0;
        $failureCount = 0;

        foreach ($records as $record) {
            try {
                $results = $this->validationService->validateSenderAddress((int)$record['uid']);

                if ($results['status'] === ValidationResult::STATUS_VALID) {
                    $successCount++;
                } else {
                    $failureCount++;
                }
            } catch (\Throwable $e) {
                $failureCount++;
                $io->writeln('');
                $io->error('Failed to validate UID ' . $record['uid'] . ': ' . $e->getMessage());
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        $io->newLine();
        $io->success(sprintf(
            'Validation complete: %d valid, %d invalid/warning',
            $successCount,
            $failureCount
        ));

        return Command::SUCCESS;
    }

    /**
     * Display validation results
     *
     * @param array<string, mixed> $results
     */
    private function displayResults(array $results, SymfonyStyle $io): void
    {
        // Overall status
        $statusColor = match ($results['status']) {
            ValidationResult::STATUS_VALID => 'green',
            ValidationResult::STATUS_WARNING => 'yellow',
            ValidationResult::STATUS_INVALID => 'red',
            default => 'gray',
        };

        $io->writeln(sprintf(
            '<fg=%s>Overall Status: %s</>',
            $statusColor,
            strtoupper($results['status'])
        ));

        $io->newLine();

        // Validator results table
        $table = new Table($io);
        $table->setHeaders(['Validator', 'Status', 'Message']);

        foreach ($results['validators'] as $validatorName => $validatorResult) {
            $status = $validatorResult['status'];
            $statusIcon = match ($status) {
                ValidationResult::STATUS_VALID => '<fg=green>✓</>',
                ValidationResult::STATUS_WARNING => '<fg=yellow>⚠</>',
                ValidationResult::STATUS_INVALID => '<fg=red>✗</>',
                default => '?',
            };

            $table->addRow([
                $validatorName,
                $statusIcon . ' ' . $status,
                $validatorResult['message'],
            ]);
        }

        $table->render();

        // Display errors if any
        if (!empty($results['errors'])) {
            $io->newLine();
            $io->error('Errors:');
            $io->listing($results['errors']);
        }

        // Display details if verbose
        if ($io->isVerbose()) {
            $io->newLine();
            $io->section('Detailed Results');
            $io->writeln(json_encode($results, JSON_PRETTY_PRINT));
        }
    }
}
