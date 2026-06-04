import { test as base, type FrameLocator, type Page, expect } from '@playwright/test';
import config from '../config';

/**
 * Helpers for navigating the TYPO3 backend.
 *
 * TYPO3 renders backend modules inside an iframe (#typo3-contentIframe). All
 * module content must therefore be addressed through the content frame, which
 * the navigation helpers return for convenience.
 *
 * Module *route paths* differ between TYPO3 v12, v13 and v14 (e.g. the Forms
 * module moved from /module/manage/forms to /module/form, and the record list
 * moved from the "web_list" module to the "records" module). The stable handle
 * across versions is the module-menu identifier, so we resolve routes from the
 * backend module menu rather than hard-coding paths.
 */
export class BackendPage {
  readonly page: Page;

  constructor(page: Page) {
    this.page = page;
  }

  /**
   * The frame that holds backend module content.
   */
  contentFrame(): FrameLocator {
    return this.page.frameLocator('#typo3-contentIframe');
  }

  /**
   * Open a backend module by its route path and return the content iframe.
   */
  async gotoModule(path: string): Promise<FrameLocator> {
    await this.page.goto(config.baseUrl + path);
    await this.page.waitForLoadState('networkidle');
    return this.contentFrame();
  }

  /**
   * Resolve a module route from the backend module menu and open it. The first
   * of the given identifiers that exists in the menu wins, so callers can list
   * version-specific identifiers in preference order.
   */
  async gotoModuleByIdentifier(identifiers: string[], query = ''): Promise<FrameLocator> {
    await this.page.goto(config.baseUrl + '/typo3/');
    await this.page.waitForLoadState('networkidle');

    let href: string | null = null;
    for (const identifier of identifiers) {
      const item = this.page.locator(`[data-modulemenu-identifier="${identifier}"]`).first();
      if ((await item.count()) > 0) {
        href = await item.getAttribute('href');
        if (href) {
          break;
        }
      }
    }
    if (!href) {
      throw new Error(`No module-menu entry found for identifiers: ${identifiers.join(', ')}`);
    }

    const url = config.baseUrl + href + (query ? (href.includes('?') ? '&' : '?') + query : '');
    await this.page.goto(url);
    await this.page.waitForLoadState('networkidle');
    return this.contentFrame();
  }

  /**
   * Open the Mail Sender backend module (the validation module).
   */
  async gotoMailSenderModule(): Promise<FrameLocator> {
    return this.gotoModuleByIdentifier(['mailsender', 'system_mailsender']);
  }

  /**
   * Open the record list for the given page id. Mail Sender Address records
   * live at the root level (pid 0). The module is "web_list" on v12/v13 and
   * "records" on v14.
   */
  async gotoListModule(pageId: number = 0): Promise<FrameLocator> {
    return this.gotoModuleByIdentifier(['web_list', 'records'], `id=${pageId}`);
  }

  /**
   * Open the ext:form "Forms" backend module.
   */
  async gotoFormModule(): Promise<FrameLocator> {
    return this.gotoModuleByIdentifier(['web_FormFormbuilder']);
  }
}

type Fixtures = {
  backend: BackendPage;
};

export const test = base.extend<Fixtures>({
  backend: async ({ page }, use) => {
    await use(new BackendPage(page));
  },
});

export { expect };
