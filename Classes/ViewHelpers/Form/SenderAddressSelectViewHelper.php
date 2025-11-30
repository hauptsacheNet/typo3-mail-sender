<?php

declare(strict_types=1);

namespace Hn\MailSender\ViewHelpers\Form;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractTagBasedViewHelper;

/**
 * ViewHelper which renders a `<select>` tag with validated sender addresses.
 *
 * Only addresses with validation_status 'valid' or 'warning' are shown.
 *
 * Example:
 * ```
 *   <mailsender:form.senderAddressSelect name="senderAddress" class="form-select" />
 * ```
 */
class SenderAddressSelectViewHelper extends AbstractTagBasedViewHelper
{
    protected $tagName = 'select';

    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerUniversalTagAttributes();
        $this->registerArgument('name', 'string', 'Name of the select field');
        $this->registerArgument('value', 'string', 'Currently selected value');
        $this->registerArgument('prependOptionLabel', 'string', 'Label for the first empty option');
        $this->registerArgument('prependOptionValue', 'string', 'Value for the first empty option', false, '');
        $this->registerArgument('required', 'boolean', 'If set, makes the field required', false, false);
        $this->registerArgument('includeInvalid', 'boolean', 'If set, also includes addresses with status "invalid"', false, false);
        $this->registerArgument('includePending', 'boolean', 'If set, also includes addresses with status "pending"', false, false);
    }

    public function render(): string
    {
        if ($this->arguments['name']) {
            $this->tag->addAttribute('name', $this->arguments['name']);
        }

        if ($this->arguments['required']) {
            $this->tag->addAttribute('required', 'required');
        }

        $options = $this->getSenderAddresses();
        $selectedValue = $this->arguments['value'] ?? '';

        $tagContent = $this->renderPrependOptionTag();
        foreach ($options as $option) {
            $value = $option['sender_address'];
            $label = $this->formatLabel($option);
            $isSelected = $value === $selectedValue;
            $tagContent .= $this->renderOptionTag($value, $label, $isSelected);
        }

        $this->tag->forceClosingTag(true);
        $this->tag->setContent($tagContent);
        return $this->tag->render();
    }

    /**
     * Get sender addresses from database
     *
     * @return array<int, array{sender_address: string, sender_name: string, validation_status: string}>
     */
    protected function getSenderAddresses(): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_mailsender_address');

        $allowedStatuses = ['valid', 'warning'];
        if ($this->arguments['includeInvalid']) {
            $allowedStatuses[] = 'invalid';
        }
        if ($this->arguments['includePending']) {
            $allowedStatuses[] = 'pending';
        }

        $result = $queryBuilder
            ->select('sender_address', 'sender_name', 'validation_status')
            ->from('tx_mailsender_address')
            ->where(
                $queryBuilder->expr()->eq('hidden', 0),
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->in(
                    'validation_status',
                    $queryBuilder->createNamedParameter($allowedStatuses, \Doctrine\DBAL\ArrayParameterType::STRING)
                )
            )
            ->orderBy('sender_name')
            ->addOrderBy('sender_address')
            ->executeQuery()
            ->fetchAllAssociative();

        return $result;
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

    /**
     * Render prepended option tag (empty first option)
     */
    protected function renderPrependOptionTag(): string
    {
        if ($this->hasArgument('prependOptionLabel')) {
            $value = $this->arguments['prependOptionValue'] ?? '';
            $label = $this->arguments['prependOptionLabel'];
            return $this->renderOptionTag($value, $label, false) . "\n";
        }
        return '';
    }

    /**
     * Render one option tag
     */
    protected function renderOptionTag(string $value, string $label, bool $isSelected): string
    {
        $output = '<option value="' . htmlspecialchars($value) . '"';
        if ($isSelected) {
            $output .= ' selected="selected"';
        }
        $output .= '>' . htmlspecialchars($label) . '</option>';
        return $output;
    }
}
