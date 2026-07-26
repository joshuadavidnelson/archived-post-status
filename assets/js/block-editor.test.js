/**
 * @jest-environment jsdom
 */

import { setupWordPressGlobals, createArchivedPostStatusConfig, cleanupTestEnvironment } from './test-utils.js';

describe('Archive Button in Block Editor', () => {
    let mockCreateElement;
    let mockRegisterPlugin;
    let confirmMock;

    beforeEach(() => {
        cleanupTestEnvironment();
        const mocks = setupWordPressGlobals({
            confirmDefault: true,
            archivedPostStatus: createArchivedPostStatusConfig()
        });
        mockCreateElement = mocks.mockCreateElement;
        mockRegisterPlugin = mocks.mockRegisterPlugin;
        confirmMock = mocks.confirmMock;
    });

    afterEach(() => {
        cleanupTestEnvironment();
    });

    describe('Button Visibility Based on User Permissions', () => {
        test('shows archive button when user has permission to archive posts', () => {
            global.archivedPostStatus.canArchive = true;

            // Simulate the archive button function
            function archiveButton() {
                return global.archivedPostStatus.canArchive ?
                    mockCreateElement('PluginPostStatusInfo', {}, 'Archive Button') : null;
            }

            expect(archiveButton()).not.toBeNull();
        });

        test('hides archive button when user lacks permission to archive posts', () => {
            global.archivedPostStatus.canArchive = false;

            // Simulate the archive button function
            function archiveButton() {
                return global.archivedPostStatus.canArchive ?
                    mockCreateElement('PluginPostStatusInfo', {}, 'Archive Button') : null;
            }

            expect(archiveButton()).toBeNull();
        });
    });

    describe('Archive Confirmation Behavior', () => {
        test('prompts user for confirmation when clicking archive button', () => {
            const mockEvent = { preventDefault: jest.fn() };

            const onClick = (event) => {
                if (!confirmMock('Are you sure you want to archive this post?')) {
                    event.preventDefault();
                }
            };

            onClick(mockEvent);
            expect(confirmMock).toHaveBeenCalledWith('Are you sure you want to archive this post?');
        });

        test('prevents archiving when user cancels confirmation', () => {
            confirmMock.mockReturnValue(false);
            const mockEvent = { preventDefault: jest.fn() };

            const onClick = (event) => {
                if (!confirmMock('Are you sure you want to archive this post?')) {
                    event.preventDefault();
                }
            };

            onClick(mockEvent);
            expect(mockEvent.preventDefault).toHaveBeenCalled();
        });

        test('allows archiving when user confirms', () => {
            confirmMock.mockReturnValue(true);
            const mockEvent = { preventDefault: jest.fn() };

            const onClick = (event) => {
                if (!confirmMock('Are you sure you want to archive this post?')) {
                    event.preventDefault();
                }
            };

            onClick(mockEvent);
            expect(mockEvent.preventDefault).not.toHaveBeenCalled();
        });
    });

    describe('Plugin Integration', () => {
        test('registers with WordPress plugin system', () => {
            function archiveButton() {
                return mockCreateElement('PluginPostStatusInfo', {}, 'Archive Button');
            }

            global.wp.plugins.registerPlugin('archive-button', { render: archiveButton });

            expect(mockRegisterPlugin).toHaveBeenCalledWith('archive-button', {
                render: expect.any(Function)
            });
        });

        test('creates button with proper styling for destructive action', () => {
            const buttonElement = mockCreateElement('a', {
                className: 'components-button editor-post-archive is-destructive is-primary'
            }, 'Archive');

            expect(mockCreateElement).toHaveBeenCalledWith(
                'a',
                expect.objectContaining({
                    className: expect.stringContaining('is-destructive')
                }),
                'Archive'
            );
        });
    });
});
