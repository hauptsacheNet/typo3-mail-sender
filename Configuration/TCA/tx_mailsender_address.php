<?php

defined('TYPO3') or die;

return [
    'ctrl' => [
        'title' => 'LLL:EXT:mail_sender/Resources/Private/Language/locallang_db.xlf:tx_mailsender_address',
        'label' => 'sender_address',
        'label_alt' => 'sender_name',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'rootLevel' => 1,
        'searchFields' => 'sender_address,sender_name',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'iconfile' => 'EXT:mail_sender/Resources/Public/Icons/tx_mailsender_address.svg',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'hidden' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.enabled',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'items' => [
                    [
                        'label' => '',
                        'invertStateDisplay' => true,
                    ],
                ],
            ],
        ],
        'sender_address' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mail_sender/Resources/Private/Language/locallang_db.xlf:tx_mailsender_address.sender_address',
            'config' => [
                'type' => 'email',
                'size' => 30,
                'required' => true,
                'eval' => 'trim',
            ],
        ],
        'sender_name' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mail_sender/Resources/Private/Language/locallang_db.xlf:tx_mailsender_address.sender_name',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'validation_status' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mail_sender/Resources/Private/Language/locallang_db.xlf:tx_mailsender_address.validation_status',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:mail_sender/Resources/Private/Language/locallang_db.xlf:tx_mailsender_address.validation_status.pending', 'value' => 'pending'],
                    ['label' => 'LLL:EXT:mail_sender/Resources/Private/Language/locallang_db.xlf:tx_mailsender_address.validation_status.valid', 'value' => 'valid'],
                    ['label' => 'LLL:EXT:mail_sender/Resources/Private/Language/locallang_db.xlf:tx_mailsender_address.validation_status.warning', 'value' => 'warning'],
                    ['label' => 'LLL:EXT:mail_sender/Resources/Private/Language/locallang_db.xlf:tx_mailsender_address.validation_status.invalid', 'value' => 'invalid'],
                ],
                'default' => 'pending',
                'readOnly' => true,
            ],
        ],
        'validation_last_check' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mail_sender/Resources/Private/Language/locallang_db.xlf:tx_mailsender_address.validation_last_check',
            'config' => [
                'type' => 'datetime',
                'format' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'validation_result' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mail_sender/Resources/Private/Language/locallang_db.xlf:tx_mailsender_address.validation_result',
            'config' => [
                'type' => 'user',
                'renderType' => 'validationResult',
            ],
        ],
        'eml_file' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mail_sender/Resources/Private/Language/locallang_db.xlf:tx_mailsender_address.eml_file',
            'description' => 'LLL:EXT:mail_sender/Resources/Private/Language/locallang_db.xlf:tx_mailsender_address.eml_file.description',
            'config' => [
                'type' => 'file',
                'allowed' => 'eml',
                'maxitems' => 1,
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    sender_address, sender_name, hidden,
                --div--;LLL:EXT:mail_sender/Resources/Private/Language/locallang_db.xlf:tx_mailsender_address.tabs.validation,
                    validation_status, validation_last_check, eml_file, validation_result,
            ',
        ],
    ],
];
