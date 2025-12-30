<?php

declare(strict_types=1);

namespace Hn\MailSender\Form\FormEditor;

use Doctrine\DBAL\ArrayParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service that provides sender address select options for the form editor.
 *
 * This is used to dynamically inject validated sender addresses into
 * the form editor's select fields.
 */
class SenderAddressOptionsProvider
{
    /**
     * Get sender addresses formatted as selectOptions for form editor
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getSelectOptions(): array
    {
        $options = [
            10 => [
                'value' => '',
                'label' => '--- Select sender address ---',
            ],
        ];

        $senderAddresses = $this->getSenderAddressesFromDatabase();
        $index = 20;

        foreach ($senderAddresses as $address) {
            $label = $this->formatLabel($address);
            $options[$index] = [
                'value' => $address['sender_address'],
                'label' => $label,
            ];
            $index += 10;
        }

        return $options;
    }

    /**
     * Get validated sender addresses from database
     *
     * @return array<int, array{sender_address: string, sender_name: string, validation_status: string}>
     */
    private function getSenderAddressesFromDatabase(): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_mailsender_address');

        return $queryBuilder
            ->select('sender_address', 'sender_name', 'validation_status')
            ->from('tx_mailsender_address')
            ->where(
                $queryBuilder->expr()->eq('hidden', 0),
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->in(
                    'validation_status',
                    $queryBuilder->createNamedParameter(['valid', 'warning'], ArrayParameterType::STRING)
                )
            )
            ->orderBy('sender_name')
            ->addOrderBy('sender_address')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Format the option label as "Name <email>" or just "email"
     */
    private function formatLabel(array $option): string
    {
        $email = $option['sender_address'];
        $name = trim($option['sender_name'] ?? '');

        if ($name !== '') {
            return sprintf('%s <%s>', $name, $email);
        }

        return $email;
    }
}
