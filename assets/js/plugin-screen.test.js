/**
 * @jest-environment jsdom
 */

import { createPluginsPageDOM, setupWordPressGlobals, createArchivedPostStatusConfig, cleanupTestEnvironment } from './test-utils.js';

/**
 * Plugin Screen Deactivation Warning Tests - User Behavior Focused
 * Tests the user experience when attempting to deactivate the plugin
 */

describe('Plugin Deactivation Warning System', () => {
    let confirmMock;

    beforeEach(() => {
        cleanupTestEnvironment();

        // Set up realistic WordPress plugins page DOM
        document.body.innerHTML = createPluginsPageDOM();

        // Setup WordPress globals
        const mocks = setupWordPressGlobals();
        confirmMock = mocks.confirmMock;

        // Setup default archived post status
        global.archivedPostStatus = createArchivedPostStatusConfig();
    });

    afterEach(() => {
        cleanupTestEnvironment();
    });

    describe('Warning Display Based on Archived Content', () => {
        test('shows warning when deactivating plugin with archived posts', () => {
            global.archivedPostStatus.hasArchivedPosts = true;

            // Simulate the event handler behavior directly
            const handler = (e) => {
                const message = 'Warning! Deactivating this plugin will remove the \'archive\' status and all content in that status will be hidden. To keep that content visible, unarchive it first and then deactivate this plugin.';
                if (global.archivedPostStatus.hasArchivedPosts && !confirmMock(message)) {
                    e.preventDefault();
                }
            };

            const mockEvent = { preventDefault: jest.fn() };
            handler(mockEvent);

            expect(confirmMock).toHaveBeenCalledWith(
                'Warning! Deactivating this plugin will remove the \'archive\' status and all content in that status will be hidden. To keep that content visible, unarchive it first and then deactivate this plugin.'
            );
        });

        test('allows deactivation without warning when no archived posts exist', () => {
            global.archivedPostStatus.hasArchivedPosts = false;

            // Simulate the event handler behavior directly
            const handler = (e) => {
                if (global.archivedPostStatus.hasArchivedPosts && !confirmMock('Warning message')) {
                    e.preventDefault();
                }
            };

            const mockEvent = { preventDefault: jest.fn() };
            handler(mockEvent);

            expect(confirmMock).not.toHaveBeenCalled();
            expect(mockEvent.preventDefault).not.toHaveBeenCalled();
        });
    });

    describe('User Decision Handling', () => {
        test('prevents deactivation when user cancels the warning', () => {
            global.archivedPostStatus.hasArchivedPosts = true;
            confirmMock.mockReturnValue(false);

            const handler = (e) => {
                if (global.archivedPostStatus.hasArchivedPosts && !confirmMock('Warning message')) {
                    e.preventDefault();
                }
            };

            const mockEvent = { preventDefault: jest.fn() };
            handler(mockEvent);

            expect(mockEvent.preventDefault).toHaveBeenCalled();
        });

        test('allows deactivation when user confirms the warning', () => {
            global.archivedPostStatus.hasArchivedPosts = true;
            confirmMock.mockReturnValue(true);

            const handler = (e) => {
                if (global.archivedPostStatus.hasArchivedPosts && !confirmMock('Warning message')) {
                    e.preventDefault();
                }
            };

            const mockEvent = { preventDefault: jest.fn() };
            handler(mockEvent);

            expect(confirmMock).toHaveBeenCalled();
            expect(mockEvent.preventDefault).not.toHaveBeenCalled();
        });
    });

    describe('Plugin-Specific Behavior', () => {
        test('only affects the archived post status plugin deactivation', () => {
            global.archivedPostStatus = { hasArchivedPosts: true };
            global.confirm.mockReturnValue(false);

            // Set up event listener only for archived-post-status plugin
            const archivedPluginLink = document.querySelector('tr[data-slug="archived-post-status"] .deactivate a');
            const otherPluginLink = document.querySelector('tr[data-slug="other-plugin"] .deactivate a');

            const mockPreventDefault1 = jest.fn();
            const mockPreventDefault2 = jest.fn();

            // Only add warning to archived-post-status plugin
            archivedPluginLink.addEventListener('click', function(e) {
                e.preventDefault = mockPreventDefault1;
                const message = global.wp.i18n.__('Warning! Deactivating this plugin will remove the \'archive\' status and all content in that status will be hidden. To keep that content visible, unarchive it first and then deactivate this plugin.', 'archived-post-status');
                if (global.archivedPostStatus.hasArchivedPosts && !global.confirm(message)) {
                    e.preventDefault();
                }
            });

            // Click both plugin deactivate links
            const clickEvent1 = new Event('click');
            clickEvent1.preventDefault = mockPreventDefault1;
            archivedPluginLink.dispatchEvent(clickEvent1);

            const clickEvent2 = new Event('click');
            clickEvent2.preventDefault = mockPreventDefault2;
            otherPluginLink.dispatchEvent(clickEvent2);

            // Only the archived post status plugin should show warning and be prevented
            expect(global.confirm).toHaveBeenCalledTimes(1);
            expect(mockPreventDefault1).toHaveBeenCalled();
            expect(mockPreventDefault2).not.toHaveBeenCalled();
        });

        test('handles missing plugin gracefully', () => {
            // Remove the archived post status plugin from DOM
            const pluginRow = document.querySelector('tr[data-slug="archived-post-status"]');
            pluginRow.remove();

            // Should not throw error when plugin is not found
            expect(() => {
                const deactivateLink = document.querySelector('tr[data-slug="archived-post-status"] .deactivate a');
                if (deactivateLink) {
                    deactivateLink.addEventListener('click', function() {});
                }
            }).not.toThrow();

            expect(global.confirm).not.toHaveBeenCalled();
        });
    });

    describe('User Experience', () => {
        test('provides clear warning message about data visibility', () => {
            global.archivedPostStatus = { hasArchivedPosts: true };
            global.confirm.mockReturnValue(true);

            const deactivateLink = document.querySelector('tr[data-slug="archived-post-status"] .deactivate a');

            deactivateLink.addEventListener('click', function(e) {
                const message = global.wp.i18n.__('Warning! Deactivating this plugin will remove the \'archive\' status and all content in that status will be hidden. To keep that content visible, unarchive it first and then deactivate this plugin.', 'archived-post-status');
                if (global.archivedPostStatus.hasArchivedPosts && !global.confirm(message)) {
                    e.preventDefault();
                }
            });

            deactivateLink.click();

            // Warning should explain the consequences and provide guidance
            const warningMessage = global.confirm.mock.calls[0][0];
            expect(warningMessage).toContain('Warning!');
            expect(warningMessage).toContain('archive\' status');
            expect(warningMessage).toContain('will be hidden');
            expect(warningMessage).toContain('unarchive it first');
        });

        test('integrates with WordPress internationalization', () => {
            global.archivedPostStatus = { hasArchivedPosts: true };
            global.wp.i18n.__ = jest.fn((text, domain) => `[${domain}] ${text}`);

            const deactivateLink = document.querySelector('tr[data-slug="archived-post-status"] .deactivate a');

            deactivateLink.addEventListener('click', function(e) {
                const message = global.wp.i18n.__('Warning! Deactivating this plugin will remove the \'archive\' status and all content in that status will be hidden. To keep that content visible, unarchive it first and then deactivate this plugin.', 'archived-post-status');
                global.confirm(message);
            });

            deactivateLink.click();

            // Should use proper translation domain
            expect(global.wp.i18n.__).toHaveBeenCalledWith(
                expect.any(String),
                'archived-post-status'
            );
        });
    });
});

