import { test, expect } from '../fixtures/setup-fixtures';

/**
 * Goal 2: the validation backend module still works.
 *
 * The "Mail Sender" backend module lists the configured sender addresses and
 * lets editors (re)run validation. This test opens the module, confirms the
 * seeded address is listed and triggers a revalidation, expecting the module to
 * complete the run and report success via a flash message.
 */
test.describe('Validation backend module', () => {
  test('lists addresses and revalidates one', async ({ backend }) => {
    const frame = await backend.gotoMailSenderModule();

    // The module rendered its address table.
    await expect(frame.locator('#mail-sender-module')).toBeVisible();

    const seedRow = frame.locator('#senderAddressForm tbody tr', {
      hasText: 'valid-seed@example.com',
    });
    await expect(seedRow).toBeVisible();

    // Trigger a revalidation of the seeded address (submits and reloads the module).
    await seedRow.locator('.single-validate-btn').click();

    // The module reports the completed validation run via a flash message.
    await expect(frame.getByText(/validated successfully/i)).toBeVisible({ timeout: 45000 });
  });
});
