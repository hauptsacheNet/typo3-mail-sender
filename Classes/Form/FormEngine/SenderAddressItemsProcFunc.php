<?php

declare(strict_types=1);

namespace Hn\MailSender\Form\FormEngine;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * ItemsProcFunc to populate sender address select fields in TCA/FormEngine
 *
 * This provides validated sender addresses for the form extension's
 * finisher configuration when editing forms via the content element.
 */
class SenderAddressItemsProcFunc
{
    /**
     * Populate items array with sender addresses from tx_mailsender_address
     *
     * @param array $params TCA itemsProcFunc parameters
     */
    public function getItems(array &$params): void
    {
        // Add empty option first
        $params['items'][] = [
            'label' => '--- Select sender address ---',
            'value' => '',
        ];

        $senderAddresses = $this->getSenderAddresses();

        foreach ($senderAddresses as $address) {
            $label = $this->formatLabel($address);
            $params['items'][] = [
                'label' => $label,
                'value' => $address['sender_address'],
            ];
        }
    }

    /**
     * Get validated sender addresses from database
     *
     * @return array<int, array{sender_address: string, sender_name: string, validation_status: string}>
     */
    protected function getSenderAddresses(): array
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
                    $queryBuilder->createNamedParameter(['valid', 'warning'], \Doctrine\DBAL\ArrayParameterType::STRING)
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
    protected function formatLabel(array $option): string
    {
        $email = $option['sender_address'];
        $name = trim($option['sender_name'] ?? '');

        if ($name !== '') {
            return sprintf('%s <%s>', $name, $email);
        }

        return $email;
    }
}
