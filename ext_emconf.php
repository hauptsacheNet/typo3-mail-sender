<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Mail Sender Configuration',
    'description' => 'Configure and validate email sender addresses with DNS and deliverability checks',
    'category' => 'backend',
    'author' => 'Marco Pfeiffer',
    'author_email' => 'marco@hauptsache.net',
    'state' => 'beta',
    'clearCacheOnLoad' => true,
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
            'php' => '8.1.0-8.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
    'autoload' => [
        'psr-4' => [
            'Hn\\MailSender\\' => 'Classes/',
        ],
    ],
];
