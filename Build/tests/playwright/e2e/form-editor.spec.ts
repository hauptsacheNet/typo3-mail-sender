import { test, expect } from '../fixtures/setup-fixtures';

/**
 * Goal 1: the ext:form integration still works and a form can be saved.
 *
 * The extension decorates the form editor's ConfigurationService to replace the
 * free-text "senderAddress" finisher option with a dropdown of validated sender
 * addresses. If that decoration breaks, the form editor fails to boot or to save.
 *
 * This test opens the seeded "E2E Mail Sender Form" (which carries an
 * EmailToReceiver finisher), confirms the injected dropdown offers the
 * pre-validated seed address, selects it and saves the form.
 */
test.describe('ext:form integration', () => {
  test('injects the validated sender-address dropdown and saves the form', async ({ page, backend }) => {
    const frame = await backend.gotoFormModule();

    // Open the seeded form in the form editor.
    await frame.getByText('E2E Mail Sender Form', { exact: false }).first().click();

    // The editor has booted once its toolbar Save button is present.
    const saveButton = frame.locator('[data-identifier="saveButton"]');
    await expect(saveButton).toBeVisible({ timeout: 30000 });

    // The root "Form" element is selected by default; expand the EmailToReceiver
    // finisher to reveal its options (including our injected sender-address field).
    // The inspector lives in a nested scroll region, so trigger the Bootstrap
    // collapse via a dispatched click rather than a positional click.
    await frame
      .locator('[data-bs-toggle="collapse"][aria-controls="t3-form-inspector-finishers-EmailToReceiver"]')
      .first()
      .dispatchEvent('click');

    const finisherPanel = frame.locator('#t3-form-inspector-finishers-EmailToReceiver');
    await expect(finisherPanel).toBeVisible();

    // The decorator turns "senderAddress" into a single-select that lists the
    // validated addresses. The pre-validated seed address must be selectable.
    const senderSelect = finisherPanel.locator(
      'select:has(option:has-text("valid-seed@example.com"))',
    );
    await expect(senderSelect).toBeVisible();

    const seedValue = await senderSelect
      .locator('option:has-text("valid-seed@example.com")')
      .first()
      .getAttribute('value');
    expect(seedValue).not.toBeNull();
    await senderSelect.selectOption(seedValue!);

    // Saving must succeed — TYPO3 reports this via a notification in the top frame.
    await saveButton.click();
    await expect(page.getByText(/successfully saved/i)).toBeVisible({ timeout: 20000 });
  });
});
