<?php

defined('TYPO3') or die();

// Register DataHandler hook for automatic validation on save
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] =
    \Hn\MailSender\Hooks\DataHandlerHook::class;

// Register custom FormEngine element for validation results
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1734000000] = [
    'nodeName' => 'validationResult',
    'priority' => 40,
    'class' => \Hn\MailSender\Form\Element\ValidationResultElement::class,
];
