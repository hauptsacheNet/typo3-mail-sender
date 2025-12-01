<?php

declare(strict_types=1);

namespace Hn\MailSender\Report;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Reports\Status;
use TYPO3\CMS\Reports\StatusProviderInterface;

/**
 * Status provider for the TYPO3 Reports module
 *
 * Shows the health status of configured mail sender addresses.
 */
class MailSenderStatusProvider implements StatusProviderInterface
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function getLabel(): string
    {
        return 'Mail Sender';
    }

    /**
     * @return Status[]
     */
    public function getStatus(): array
    {
        $stats = $this->getValidationStatistics();

        return [
            'mailSenderAddresses' => $this->createOverallStatus($stats),
        ];
    }

    private function createOverallStatus(array $stats): Status
    {
        $total = $stats['total'];

        if ($total === 0) {
            return new Status(
                'Sender Addresses',
                'Not configured',
                'No sender email addresses have been configured yet. Add addresses in the Mail Sender module.',
                ContextualFeedbackSeverity::NOTICE
            );
        }

        $valid = $stats['valid'];
        $warning = $stats['warning'];
        $invalid = $stats['invalid'];
        $pending = $stats['pending'];

        // Determine severity
        if ($invalid > 0) {
            $severity = ContextualFeedbackSeverity::ERROR;
            $value = $invalid . ' invalid';
        } elseif ($warning > 0 || $pending > 0) {
            $severity = ContextualFeedbackSeverity::WARNING;
            $value = $warning > 0 ? ($warning . ' with warnings') : ($pending . ' pending');
        } else {
            $severity = ContextualFeedbackSeverity::OK;
            $value = 'All valid';
        }

        // Build message
        $parts = [];
        if ($valid > 0) {
            $parts[] = $valid . ' valid';
        }
        if ($warning > 0) {
            $parts[] = $warning . ' with warnings';
        }
        if ($invalid > 0) {
            $parts[] = $invalid . ' invalid';
        }
        if ($pending > 0) {
            $parts[] = $pending . ' pending validation';
        }

        $message = 'Status of ' . $total . ' configured sender address(es): ' . implode(', ', $parts) . '.';

        return new Status(
            'Sender Addresses',
            $value,
            $message,
            $severity
        );
    }

    private function getValidationStatistics(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_mailsender_address');
        $queryBuilder->getRestrictions()->removeAll();

        $results = $queryBuilder
            ->select('validation_status')
            ->addSelectLiteral('COUNT(*) AS count')
            ->from('tx_mailsender_address')
            ->where(
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->groupBy('validation_status')
            ->executeQuery()
            ->fetchAllAssociative();

        $stats = [
            'total' => 0,
            'valid' => 0,
            'warning' => 0,
            'invalid' => 0,
            'pending' => 0,
        ];

        foreach ($results as $row) {
            $count = (int)$row['count'];
            $stats['total'] += $count;

            switch ($row['validation_status']) {
                case 'valid':
                    $stats['valid'] = $count;
                    break;
                case 'warning':
                    $stats['warning'] = $count;
                    break;
                case 'invalid':
                    $stats['invalid'] = $count;
                    break;
                case 'pending':
                default:
                    $stats['pending'] += $count;
                    break;
            }
        }

        return $stats;
    }
}
