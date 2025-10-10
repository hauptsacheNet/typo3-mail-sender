<?php

declare(strict_types=1);

namespace Hn\MailSender\Form\Element;

use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Custom FormEngine element for displaying validation results
 *
 * Renders JSON validation results in a user-friendly, structured format
 * with status badges, expandable details, and color-coded indicators.
 */
class ValidationResultElement extends AbstractFormElement
{
    /**
     * Render the validation result field
     *
     * @return array Result array as expected by FormEngine
     */
    public function render(): array
    {
        $result = $this->initializeResultArray();

        // Get the field value (JSON string)
        $fieldValue = $this->data['parameterArray']['itemFormElValue'] ?? '';

        // Parse JSON
        $validationData = [];
        if (!empty($fieldValue)) {
            $validationData = json_decode($fieldValue, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $validationData = ['error' => 'Failed to parse JSON: ' . json_last_error_msg()];
            }
        }

        // Build HTML output
        $html = $this->buildValidationResultHtml($validationData, $fieldValue);

        $result['html'] = $html;

        return $result;
    }

    /**
     * Build HTML for validation results
     *
     * @param array $data Parsed validation data
     * @param string $rawJson Raw JSON string (for fallback display)
     * @return string HTML content
     */
    private function buildValidationResultHtml(array $data, string $rawJson): string
    {
        if (empty($data)) {
            return '<div class="alert alert-info">No validation results available yet.</div>';
        }

        if (isset($data['error'])) {
            return '<div class="alert alert-danger">' . htmlspecialchars($data['error']) . '</div>';
        }

        $html = $this->getStyleTag();
        $html .= '<div class="validation-result-container">';

        // Overall status section
        $html .= $this->renderOverallStatus($data);

        // Metadata section
        $html .= $this->renderMetadata($data);

        // Validators section
        if (isset($data['validators']) && is_array($data['validators'])) {
            $html .= $this->renderValidators($data['validators']);
        }

        // Errors section
        if (!empty($data['errors'])) {
            $html .= $this->renderErrors($data['errors']);
        }

        // Raw JSON toggle (for debugging)
        $html .= $this->renderRawJsonSection($rawJson);

        $html .= '</div>';

        return $html;
    }

    /**
     * Render overall status section
     */
    private function renderOverallStatus(array $data): string
    {
        $status = $data['status'] ?? 'unknown';
        $badge = $this->getStatusBadge($status);

        return sprintf(
            '<div class="validation-overall-status">
                <h4>Overall Status</h4>
                <div class="status-badge-large">%s</div>
            </div>',
            $badge
        );
    }

    /**
     * Render metadata section
     */
    private function renderMetadata(array $data): string
    {
        $email = htmlspecialchars($data['email'] ?? 'N/A');
        $domain = htmlspecialchars($data['domain'] ?? 'N/A');
        $timestamp = $data['timestamp'] ?? 0;
        $dateTime = $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : 'N/A';

        return sprintf(
            '<div class="validation-metadata">
                <dl>
                    <dt>Email:</dt><dd>%s</dd>
                    <dt>Domain:</dt><dd>%s</dd>
                    <dt>Last Check:</dt><dd>%s</dd>
                </dl>
            </div>',
            $email,
            $domain,
            htmlspecialchars($dateTime)
        );
    }

    /**
     * Render validators section
     */
    private function renderValidators(array $validators): string
    {
        $html = '<div class="validation-validators">
            <h4>Validator Results</h4>';

        foreach ($validators as $validatorName => $result) {
            $html .= $this->renderValidatorResult($validatorName, $result);
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a single validator result
     */
    private function renderValidatorResult(string $name, array $result): string
    {
        $status = $result['status'] ?? 'unknown';
        $message = htmlspecialchars($result['message'] ?? 'No message');
        $details = $result['details'] ?? [];
        $badge = $this->getStatusBadge($status);

        $detailsHtml = '';
        if (!empty($details)) {
            $detailsId = 'details-' . md5($name);
            $detailsJson = htmlspecialchars(json_encode($details, JSON_PRETTY_PRINT));
            $detailsHtml = sprintf(
                '<details class="validator-details" id="%s">
                    <summary>Show details</summary>
                    <pre>%s</pre>
                </details>',
                $detailsId,
                $detailsJson
            );
        }

        return sprintf(
            '<div class="validator-result">
                <div class="validator-header">
                    <strong>%s</strong>
                    %s
                </div>
                <div class="validator-message">%s</div>
                %s
            </div>',
            htmlspecialchars($name),
            $badge,
            $message,
            $detailsHtml
        );
    }

    /**
     * Render errors section
     */
    private function renderErrors(array $errors): string
    {
        if (empty($errors)) {
            return '';
        }

        $html = '<div class="validation-errors">
            <h4>Errors</h4>
            <ul>';

        foreach ($errors as $error) {
            $html .= '<li>' . htmlspecialchars($error) . '</li>';
        }

        $html .= '</ul></div>';

        return $html;
    }

    /**
     * Render raw JSON section
     */
    private function renderRawJsonSection(string $json): string
    {
        if (empty($json)) {
            return '';
        }

        // Pretty print JSON
        $decoded = json_decode($json, true);
        $prettyJson = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return sprintf(
            '<details class="validation-raw-json">
                <summary>Show raw JSON</summary>
                <pre>%s</pre>
            </details>',
            htmlspecialchars($prettyJson)
        );
    }

    /**
     * Get status badge HTML
     */
    private function getStatusBadge(string $status): string
    {
        $badges = [
            'valid' => '<span class="badge badge-success">✓ Valid</span>',
            'invalid' => '<span class="badge badge-danger">✗ Invalid</span>',
            'warning' => '<span class="badge badge-warning">⚠ Warning</span>',
            'pending' => '<span class="badge badge-info">⏳ Pending</span>',
        ];

        return $badges[$status] ?? '<span class="badge badge-secondary">' . htmlspecialchars($status) . '</span>';
    }

    /**
     * Get inline CSS styles
     */
    private function getStyleTag(): string
    {
        return '<style>
            .validation-result-container {
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 4px;
                padding: 15px;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            }

            .validation-overall-status {
                margin-bottom: 20px;
                padding-bottom: 15px;
                border-bottom: 2px solid #dee2e6;
            }

            .validation-overall-status h4 {
                margin: 0 0 10px 0;
                font-size: 16px;
                font-weight: 600;
            }

            .status-badge-large {
                font-size: 18px;
            }

            .validation-metadata {
                margin-bottom: 20px;
                padding-bottom: 15px;
                border-bottom: 1px solid #dee2e6;
            }

            .validation-metadata dl {
                display: grid;
                grid-template-columns: 120px 1fr;
                gap: 8px 15px;
                margin: 0;
            }

            .validation-metadata dt {
                font-weight: 600;
                color: #495057;
            }

            .validation-metadata dd {
                margin: 0;
                color: #212529;
            }

            .validation-validators h4 {
                margin: 0 0 15px 0;
                font-size: 16px;
                font-weight: 600;
            }

            .validator-result {
                background: #fff;
                border: 1px solid #dee2e6;
                border-radius: 4px;
                padding: 12px;
                margin-bottom: 10px;
            }

            .validator-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 8px;
            }

            .validator-message {
                color: #6c757d;
                font-size: 14px;
                margin-bottom: 8px;
            }

            .validator-details {
                margin-top: 10px;
            }

            .validator-details summary {
                cursor: pointer;
                color: #007bff;
                font-size: 13px;
                user-select: none;
            }

            .validator-details summary:hover {
                text-decoration: underline;
            }

            .validator-details pre {
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 4px;
                padding: 10px;
                margin: 10px 0 0 0;
                font-size: 12px;
                overflow-x: auto;
            }

            .validation-errors {
                margin-top: 20px;
                padding-top: 15px;
                border-top: 1px solid #dee2e6;
            }

            .validation-errors h4 {
                margin: 0 0 10px 0;
                font-size: 16px;
                font-weight: 600;
                color: #dc3545;
            }

            .validation-errors ul {
                margin: 0;
                padding-left: 20px;
                color: #dc3545;
            }

            .validation-raw-json {
                margin-top: 20px;
                padding-top: 15px;
                border-top: 1px solid #dee2e6;
            }

            .validation-raw-json summary {
                cursor: pointer;
                color: #6c757d;
                font-size: 13px;
                user-select: none;
            }

            .validation-raw-json summary:hover {
                color: #495057;
            }

            .validation-raw-json pre {
                background: #fff;
                border: 1px solid #dee2e6;
                border-radius: 4px;
                padding: 12px;
                margin: 10px 0 0 0;
                font-size: 12px;
                overflow-x: auto;
                max-height: 400px;
            }

            .badge {
                display: inline-block;
                padding: 4px 8px;
                font-size: 13px;
                font-weight: 600;
                line-height: 1;
                text-align: center;
                white-space: nowrap;
                vertical-align: baseline;
                border-radius: 3px;
            }

            .badge-success {
                color: #fff;
                background-color: #28a745;
            }

            .badge-danger {
                color: #fff;
                background-color: #dc3545;
            }

            .badge-warning {
                color: #212529;
                background-color: #ffc107;
            }

            .badge-info {
                color: #fff;
                background-color: #17a2b8;
            }

            .badge-secondary {
                color: #fff;
                background-color: #6c757d;
            }
        </style>';
    }
}
