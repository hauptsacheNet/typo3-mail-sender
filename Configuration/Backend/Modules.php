<?php

declare(strict_types=1);

use Hn\MailSender\Controller\MailSenderController;

/**
 * Backend module registration for TYPO3 13+
 */
return [
    'mailsender' => [
        'parent' => 'system',
        'position' => ['after' => 'scheduler'],
        'access' => 'admin',
        'path' => '/module/system/mailsender',
        'labels' => 'LLL:EXT:mail_sender/Resources/Private/Language/locallang_mod.xlf',
        'iconIdentifier' => 'module-mail-sender',
        'routes' => [
            '_default' => [
                'target' => MailSenderController::class . '::indexAction',
            ],
            'validate' => [
                'path' => '/validate',
                'target' => MailSenderController::class . '::validateAction',
            ],
            'sendTestEmail' => [
                'path' => '/send-test-email',
                'target' => MailSenderController::class . '::sendTestEmailAction',
            ],
            'delete' => [
                'path' => '/delete',
                'target' => MailSenderController::class . '::deleteAction',
            ],
        ],
    ],
];
