<?php

declare(strict_types=1);

namespace Hn\MailSender\Import;

use Hn\MailSender\Import\ValueObject\SenderAddress;

/**
 * Interface for sender address source providers
 *
 * All providers implementing this interface will be automatically
 * tagged and injected into the SenderAddressImportService via dependency injection.
 */
interface SenderAddressSourceProviderInterface
{
    /**
     * Get sender addresses from this source
     *
     * @return SenderAddress[] List of sender addresses to import
     */
    public function getSenderAddresses(): array;

    /**
     * Get the provider name for display and logging
     *
     * @return string The provider name (e.g., "Default Mail Configuration", "Form Finisher")
     */
    public function getName(): string;
}
