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
                <dl class="validation-metadata-grid">
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
            $detailsJson = htmlspecialchars(json_encode($details, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $detailsHtml = sprintf(
                '<details class="validator-details mt-2" id="%s">
                    <summary class="text-primary small">Show details</summary>
                    <pre class="validation-pre">%s</pre>
                </details>',
                $detailsId,
                $detailsJson
            );
        }

        return sprintf(
            '<div class="validator-result-card">
                <div class="validator-header d-flex justify-content-between align-items-center mb-1">
                    <strong>%s</strong>
                    %s
                </div>
                <div class="validator-message small">%s</div>
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
                <pre class="validation-pre">%s</pre>
            </details>',
            htmlspecialchars($prettyJson)
        );
    }

    /**
     * Get status badge HTML
     * Uses inline styles for text color to ensure readability regardless of theme
     */
    private function getStatusBadge(string $status): string
    {
        $badges = [
            'valid' => '<span class="badge bg-success" style="color: #fff !important;">✓ Valid</span>',
            'invalid' => '<span class="badge bg-danger" style="color: #fff !important;">✗ Invalid</span>',
            'warning' => '<span class="badge bg-warning" style="color: #000 !important;">⚠ Warning</span>',
            'pending' => '<span class="badge bg-info" style="color: #fff !important;">⏳ Pending</span>',
        ];

        return $badges[$status] ?? '<span class="badge bg-secondary" style="color: #fff !important;">' . htmlspecialchars($status) . '</span>';
    }

    /**
     * Get inline CSS styles using TYPO3's CSS variables for dark mode compatibility
     */
    private function getStyleTag(): string
    {
        return '<style>
            .validation-result-container {
                background-color: var(--typo3-component-bg);
                color: var(--typo3-component-color);
                border: 1px solid var(--typo3-component-border-color);
                border-radius: var(--typo3-component-border-radius);
                padding: 1rem;
            }

            .validation-overall-status {
                margin-bottom: 1rem;
                padding-bottom: 1rem;
                border-bottom: 1px solid var(--typo3-component-border-color);
            }

            .validation-overall-status h4 {
                margin: 0 0 0.5rem 0;
                font-size: 1rem;
                font-weight: 600;
                color: inherit;
            }

            .status-badge-large {
                font-size: 1.125rem;
            }

            .validation-metadata {
                margin-bottom: 1rem;
                padding-bottom: 1rem;
                border-bottom: 1px solid var(--typo3-component-border-color);
            }

            .validation-metadata-grid {
                display: grid;
                grid-template-columns: 120px 1fr;
                gap: 0.5rem 1rem;
                margin: 0;
            }

            .validation-metadata-grid dt {
                font-weight: 600;
                opacity: 0.7;
            }

            .validation-metadata-grid dd {
                margin: 0;
            }

            .validation-validators h4 {
                margin: 0 0 1rem 0;
                font-size: 1rem;
                font-weight: 600;
                opacity: 0.7;
            }

            .validator-result-card {
                background-color: var(--typo3-component-bg);
                border: 1px solid var(--typo3-component-border-color);
                border-radius: var(--typo3-component-border-radius);
                padding: 0.75rem 1rem;
                margin-bottom: 0.5rem;
            }

            .validator-result-card strong {
                opacity: 0.8;
            }

            .validator-message {
                opacity: 0.6;
                font-size: 0.875rem;
            }

            .validator-details summary,
            .validation-raw-json summary {
                cursor: pointer;
                color: var(--typo3-text-color-link);
                font-size: 0.8125rem;
            }

            .validator-details summary:hover,
            .validation-raw-json summary:hover {
                text-decoration: underline;
            }

            .validation-pre {
                background-color: color-mix(in srgb, var(--typo3-component-bg), var(--typo3-component-color) 5%);
                border: 1px solid var(--typo3-component-border-color);
                border-radius: var(--typo3-component-border-radius);
                padding: 0.75rem;
                margin: 0.5rem 0 0 0;
                font-size: 0.75rem;
                overflow-x: auto;
                max-height: 400px;
            }

            .validation-errors {
                margin-top: 1rem;
                padding-top: 1rem;
                border-top: 1px solid var(--typo3-component-border-color);
            }

            .validation-errors h4 {
                margin: 0 0 0.5rem 0;
                font-size: 1rem;
                font-weight: 600;
                color: var(--typo3-state-danger-color, #dc3545);
            }

            .validation-errors ul {
                margin: 0;
                padding-left: 1.25rem;
                color: var(--typo3-state-danger-color, #dc3545);
            }

            .validation-raw-json {
                margin-top: 1rem;
                padding-top: 1rem;
                border-top: 1px solid var(--typo3-component-border-color);
            }
        </style>';
    }
}
