<?php

declare(strict_types=1);

namespace Hn\MailSender\Import\ValueObject;

/**
 * Value object representing a sender address to be imported
 */
final class SenderAddress
{
    public function __construct(
        public readonly string $email,
        public readonly string $name = '',
    ) {
    }
}
