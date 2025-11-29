<?php

declare(strict_types=1);

namespace Hn\MailSender\Controller;

use Hn\MailSender\Service\ValidationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Backend module controller for Mail Sender management
 */
class MailSenderController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly ValidationService $validationService,
        private readonly FlashMessageService $flashMessageService,
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Main index action - list all sender addresses
     */
    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        // Set module title
        $moduleTemplate->setTitle('Mail Sender');

        // Get dependencies from GeneralUtility
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);

        // Include JavaScript
        $pageRenderer->addJsFile('EXT:mail_sender/Resources/Public/JavaScript/mail-sender-module.js');

        // Fetch all sender addresses
        $senderAddresses = $this->fetchAllSenderAddresses();

        // Fetch scheduler task info
        $schedulerInfo = $this->getSchedulerTaskInfo();

        // Build scheduler URL only if scheduler extension is loaded
        $schedulerUrl = '';
        if (\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('scheduler')) {
            try {
                $schedulerUrl = (string)$this->uriBuilder->buildUriFromRoute('scheduler');
            } catch (\Exception $e) {
                // Scheduler route not available
            }
        }

        // Build URLs
        $newUrl = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => ['tx_mailsender_address' => [0 => 'new']],
            'returnUrl' => (string)$this->uriBuilder->buildUriFromRoute('mailsender'),
        ]);
        $validateUrl = (string)$this->uriBuilder->buildUriFromRoute('mailsender.validate');
        $sendTestEmailUrl = (string)$this->uriBuilder->buildUriFromRoute('mailsender.sendTestEmail');
        $deleteUrl = (string)$this->uriBuilder->buildUriFromRoute('mailsender.delete');
        $returnUrl = (string)$this->uriBuilder->buildUriFromRoute('mailsender');

        // Add "Add New Sender Address" button to DocHeader
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $newButton = $buttonBar->makeLinkButton()
            ->setHref($newUrl)
            ->setTitle('Add New Sender Address')
            ->setShowLabelText(true)
            ->setIcon($iconFactory->getIcon('actions-add', IconSize::SMALL));
        $buttonBar->addButton($newButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        $moduleTemplate->assignMultiple([
            'senderAddresses' => $senderAddresses,
            'schedulerInfo' => $schedulerInfo,
            'editUrl' => (string)$this->uriBuilder->buildUriFromRoute('record_edit'),
            'newUrl' => $newUrl,
            'validateUrl' => $validateUrl,
            'sendTestEmailUrl' => $sendTestEmailUrl,
            'deleteUrl' => $deleteUrl,
            'returnUrl' => $returnUrl,
            'schedulerUrl' => $schedulerUrl,
        ]);

        return $moduleTemplate->renderResponse('MailSender/Index');
    }

    /**
     * Validate one or more sender addresses
     */
    public function validateAction(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();
        $uids = $this->getUidsFromRequest($parsedBody);

        $successCount = 0;
        $errorCount = 0;

        foreach ($uids as $uid) {
            try {
                $this->validationService->validateSenderAddress($uid);
                $successCount++;
            } catch (\Exception $e) {
                $errorCount++;
                $this->addFlashMessage(
                    'Validation failed for UID ' . $uid . ': ' . $e->getMessage(),
                    'Validation Error',
                    ContextualFeedbackSeverity::ERROR
                );
            }
        }

        if ($successCount > 0) {
            $this->addFlashMessage(
                $successCount . ' sender address(es) validated successfully.',
                'Validation Complete',
                ContextualFeedbackSeverity::OK
            );
        }

        return new RedirectResponse(
            (string)$this->uriBuilder->buildUriFromRoute('mailsender')
        );
    }

    /**
     * Send test email from one or more sender addresses
     */
    public function sendTestEmailAction(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();
        $uids = $this->getUidsFromRequest($parsedBody);
        $recipientEmail = trim($parsedBody['recipientEmail'] ?? '');

        if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            $this->addFlashMessage(
                'Please provide a valid recipient email address.',
                'Invalid Recipient',
                ContextualFeedbackSeverity::ERROR
            );
            return new RedirectResponse(
                (string)$this->uriBuilder->buildUriFromRoute('mailsender')
            );
        }

        $successCount = 0;
        $errorCount = 0;

        foreach ($uids as $uid) {
            try {
                $sender = $this->fetchSenderAddress($uid);
                if ($sender) {
                    $this->sendTestEmail($sender, $recipientEmail);
                    $successCount++;
                }
            } catch (\Exception $e) {
                $errorCount++;
                $this->addFlashMessage(
                    'Failed to send test email from UID ' . $uid . ': ' . $e->getMessage(),
                    'Send Error',
                    ContextualFeedbackSeverity::ERROR
                );
            }
        }

        if ($successCount > 0) {
            $this->addFlashMessage(
                $successCount . ' test email(s) sent successfully to ' . $recipientEmail,
                'Test Email Sent',
                ContextualFeedbackSeverity::OK
            );
        }

        return new RedirectResponse(
            (string)$this->uriBuilder->buildUriFromRoute('mailsender')
        );
    }

    /**
     * Delete one or more sender addresses
     */
    public function deleteAction(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();
        $uids = $this->getUidsFromRequest($parsedBody);

        $connection = $this->connectionPool->getConnectionForTable('tx_mailsender_address');

        $deletedCount = 0;
        foreach ($uids as $uid) {
            $connection->update(
                'tx_mailsender_address',
                ['deleted' => 1],
                ['uid' => $uid]
            );
            $deletedCount++;
        }

        if ($deletedCount > 0) {
            $this->addFlashMessage(
                $deletedCount . ' sender address(es) deleted.',
                'Deleted',
                ContextualFeedbackSeverity::OK
            );
        }

        return new RedirectResponse(
            (string)$this->uriBuilder->buildUriFromRoute('mailsender')
        );
    }

    /**
     * Fetch all non-deleted sender addresses
     */
    private function fetchAllSenderAddresses(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_mailsender_address');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from('tx_mailsender_address')
            ->where(
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->orderBy('sender_address', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Fetch single sender address by UID
     */
    private function fetchSenderAddress(int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_mailsender_address');
        $queryBuilder->getRestrictions()->removeAll();

        $result = $queryBuilder
            ->select('*')
            ->from('tx_mailsender_address')
            ->where(
                $queryBuilder->expr()->eq('uid', $uid),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchAssociative();

        return $result ?: null;
    }

    /**
     * Send a test email using the given sender
     */
    private function sendTestEmail(array $sender, string $recipientEmail): void
    {
        $senderEmail = $sender['sender_address'];
        $senderName = $sender['sender_name'] ?: '';

        $mail = GeneralUtility::makeInstance(MailMessage::class);

        $fromAddress = $senderName
            ? new Address($senderEmail, $senderName)
            : new Address($senderEmail);

        $body = sprintf(
            "This is a test email to verify that emails can be sent from: %s\n\n" .
            "Sender Email: %s\n" .
            "Sender Name: %s\n" .
            "Sent at: %s\n" .
            "Server: %s\n\n" .
            "This email was sent from the TYPO3 Mail Sender extension.",
            $senderEmail,
            $senderEmail,
            $senderName ?: '(not set)',
            date('Y-m-d H:i:s'),
            $_SERVER['SERVER_NAME'] ?? 'unknown'
        );

        $mail
            ->from($fromAddress)
            ->to($recipientEmail)
            ->subject('Test Email from ' . $senderEmail)
            ->text($body)
            ->send();
    }

    /**
     * Get scheduler task information
     */
    private function getSchedulerTaskInfo(): array
    {
        $info = [
            'configured' => false,
            'lastRun' => null,
            'nextRun' => null,
        ];

        // Check if scheduler extension is loaded
        if (!\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('scheduler')) {
            return $info;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_scheduler_task');
        $queryBuilder->getRestrictions()->removeAll();

        $task = $queryBuilder
            ->select('*')
            ->from('tx_scheduler_task')
            ->where(
                $queryBuilder->expr()->like(
                    'serialized_task_object',
                    $queryBuilder->createNamedParameter('%ValidateSenderAddressesTask%')
                ),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($task) {
            $info['configured'] = true;
            $info['lastRun'] = $task['lastexecution_time'] ? (int)$task['lastexecution_time'] : null;
            $info['nextRun'] = $task['nextexecution'] ? (int)$task['nextexecution'] : null;
            $info['disabled'] = (bool)$task['disable'];
        }

        return $info;
    }

    /**
     * Extract UIDs from request body
     */
    private function getUidsFromRequest(array $parsedBody): array
    {
        // Single UID
        if (isset($parsedBody['uid'])) {
            return [(int)$parsedBody['uid']];
        }

        // Multiple UIDs
        if (isset($parsedBody['uids']) && is_array($parsedBody['uids'])) {
            return array_map('intval', $parsedBody['uids']);
        }

        return [];
    }

    /**
     * Add a flash message
     */
    private function addFlashMessage(
        string $message,
        string $title,
        ContextualFeedbackSeverity $severity
    ): void {
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $message,
            $title,
            $severity,
            true
        );

        $this->flashMessageService
            ->getMessageQueueByIdentifier()
            ->addMessage($flashMessage);
    }
}
