import { test, expect } from '../fixtures/setup-fixtures';

/**
 * Goal 3: the List module still works for Mail Sender Address records.
 *
 * Mail Sender Address records live at the root level (pid 0). The List module
 * must list them and allow creating new ones through FormEngine — the same
 * FormEngine that renders the extension's custom "validationResult" field, so
 * this also guards against that custom node breaking the edit form.
 */
test.describe('List module', () => {
  test('lists sender address records and creates a new one', async ({ page, backend }) => {
    let frame = await backend.gotoListModule(0);

    // The record list for our table is rendered and shows the seeded address.
    await expect(frame.locator('#recordlist-tx_mailsender_address')).toBeVisible();
    await expect(frame.getByText('valid-seed@example.com').first()).toBeVisible();

    // Create a new record via the List module's "create new record" control for
    // our table. The link title differs across versions ("New Mail Sender Address"
    // vs "New record"), so target the stable data attribute + table in the href.
    const uniqueAddress = `e2e-list-${Date.now()}@example.com`;
    await frame
      .locator('a[data-recordlist-action="new"][href*="tx_mailsender_address"]')
      .first()
      .click();

    // Wait for FormEngine to finish initialising the inputs before filling, so the
    // typed value is synced into the hidden field that is actually submitted.
    const addressInput = frame.locator(
      '[data-formengine-input-name$="[sender_address]"][data-formengine-input-initialized="true"]',
    );
    await expect(addressInput).toBeVisible({ timeout: 30000 });
    await addressInput.fill(uniqueAddress);
    await addressInput.dispatchEvent('change');

    const nameInput = frame.locator(
      '[data-formengine-input-name$="[sender_name]"][data-formengine-input-initialized="true"]',
    );
    await nameInput.fill('E2E List Created');
    await nameInput.dispatchEvent('change');

    // Save the record.
    await frame.locator('button[name="_savedok"]').click();
    await page.waitForLoadState('networkidle');

    // Back in the List module the freshly created record must appear.
    frame = await backend.gotoListModule(0);
    await expect(frame.getByText(uniqueAddress).first()).toBeVisible();
  });
});
