<?php

defined('TYPO3') or die();

// Load bundled libraries autoloader for non-composer installations (TER)
// In composer mode, these classes are already autoloaded via the main autoloader
if (!\TYPO3\CMS\Core\Core\Environment::isComposerMode()) {
    require_once \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath('mail_sender')
        . 'Resources/Private/PHP/vendor/autoload.php';
}

// Register DataHandler hook for automatic validation on save
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] =
    \Hn\MailSender\Hooks\DataHandlerHook::class;

// Register custom FormEngine element for validation results
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1734000000] = [
    'nodeName' => 'validationResult',
    'priority' => 40,
    'class' => \Hn\MailSender\Form\Element\ValidationResultElement::class,
];

// Register Fluid namespace for mail_sender ViewHelpers
$GLOBALS['TYPO3_CONF_VARS']['SYS']['fluid']['namespaces']['mailsender'] = [
    'Hn\\MailSender\\ViewHelpers',
];

// Register scheduler task
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][
    \Hn\MailSender\Task\ValidateSenderAddressesTask::class
] = [
    'extension' => 'mail_sender',
    'title' => 'LLL:EXT:mail_sender/Resources/Private/Language/locallang.xlf:scheduler.task.title',
    'description' => 'LLL:EXT:mail_sender/Resources/Private/Language/locallang.xlf:scheduler.task.description',
];
