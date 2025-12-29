<?php

declare(strict_types=1);

namespace Hn\MailSender\Exception;

/**
 * Exception thrown when the EML parser library is not available
 *
 * This typically occurs in non-composer TYPO3 installations where
 * the zbateson/mail-mime-parser library has not been bundled.
 */
class EmlParserUnavailableException extends \RuntimeException
{
}
