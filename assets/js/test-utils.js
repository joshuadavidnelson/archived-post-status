/**
 * Shared Test Utilities for Archived Post Status Plugin
 * Centralizes common setup and helper functions to reduce duplication
 */

/**
 * WordPress Global Mocks
 * Provides consistent WordPress API mocking across all tests
 */
export const createWordPressMocks = () => {
    const mockCreateElement = jest.fn();
    const mockRegisterPlugin = jest.fn();

    return {
        mockCreateElement,
        mockRegisterPlugin,
        wpGlobals: {
            element: { createElement: mockCreateElement },
            plugins: { registerPlugin: mockRegisterPlugin },
            editPost: { PluginPostStatusInfo: 'PluginPostStatusInfo' },
            i18n: { __: (text) => text }
        }
    };
};

/**
 * DOM Test Helpers
 * Provides reusable DOM setup functions
 */
export const createWordPressAdminTable = (rows = []) => {
    const defaultRows = [
        {
            id: 'post-123',
            status: 'archive',
            title: 'Archived Post Title',
            hasEdit: true,
            hasTrash: true
        },
        {
            id: 'post-456',
            status: 'publish',
            title: 'Published Post Title',
            hasEdit: true,
            hasTrash: false
        },
        {
            id: 'post-789',
            status: 'archive',
            title: 'Another Archived Post',
            hasEdit: true,
            hasTrash: false
        }
    ];

    const rowsToRender = rows.length > 0 ? rows : defaultRows;

    return `
        <table class="wp-list-table">
            <tbody id="the-list">
                ${rowsToRender.map(row => `
                    <tr class="status-${row.status}" id="${row.id}">
                        <td class="column-title">
                            <strong>
                                <a class="row-title" href="/wp-admin/post.php?post=${row.id.split('-')[1]}&action=edit">
                                    ${row.title}
                                </a>
                            </strong>
                            <div class="row-actions">
                                ${row.hasEdit ? `
                                    <span class="edit">
                                        <a href="/wp-admin/post.php?post=${row.id.split('-')[1]}&action=edit">Edit</a>${row.hasTrash ? ' |' : ''}
                                    </span>
                                ` : ''}
                                ${row.hasTrash ? `
                                    <span class="trash">
                                        <a href="/wp-admin/post.php?post=${row.id.split('-')[1]}&action=trash">Trash</a>
                                    </span>
                                ` : ''}
                            </div>
                        </td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;
};

export const createPluginsPageDOM = () => {
    return `
        <table class="wp-list-table plugins">
            <tbody>
                <tr data-slug="archived-post-status" class="active">
                    <td class="plugin-title">
                        <strong>Archived Post Status</strong>
                    </td>
                    <td class="deactivate">
                        <a href="plugins.php?action=deactivate&plugin=archived-post-status%2Farchived-post-status.php"
                           id="deactivate-archived-post-status">
                            Deactivate
                        </a>
                    </td>
                </tr>
                <tr data-slug="other-plugin" class="active">
                    <td class="plugin-title">
                        <strong>Other Plugin</strong>
                    </td>
                    <td class="deactivate">
                        <a href="plugins.php?action=deactivate&plugin=other-plugin%2Fother-plugin.php">
                            Deactivate
                        </a>
                    </td>
                </tr>
            </tbody>
        </table>
    `;
};

/**
 * Common Test Setup Functions
 * Standardizes beforeEach setup across test files
 */
export const setupWordPressGlobals = (options = {}) => {
    const { mockCreateElement, mockRegisterPlugin, wpGlobals } = createWordPressMocks();

    global.wp = wpGlobals;

    // Setup window with proper confirm mock
    const confirmMock = jest.fn().mockReturnValue(options.confirmDefault !== undefined ? options.confirmDefault : true);
    global.window = {
        ...global.window,
        wp: global.wp,
        confirm: confirmMock
    };

    // Also set global.confirm for plugin-screen tests
    global.confirm = confirmMock;

    // Setup archivedPostStatus global if provided
    if (options.archivedPostStatus) {
        global.archivedPostStatus = options.archivedPostStatus;
    }

    return { mockCreateElement, mockRegisterPlugin, confirmMock };
};

/**
 * Assertion Helpers
 * Common assertions used across multiple test files
 */
export const assertArchivedPostBehavior = (postElement) => {
    const titleLink = postElement.querySelector('.column-title a.row-title');
    const editAction = postElement.querySelector('.row-actions .edit');

    expect(titleLink).toBeNull();
    expect(editAction).toBeNull();
};

export const assertPublishedPostBehavior = (postElement) => {
    const titleLink = postElement.querySelector('.column-title a.row-title');
    const editAction = postElement.querySelector('.row-actions .edit');

    expect(titleLink).not.toBeNull();
    expect(titleLink.href).toContain('action=edit');
};

/**
 * Test Data Factories
 * Consistent test data generation
 */
export const createArchivedPostStatusConfig = (overrides = {}) => {
    return {
        canArchive: true,
        archiveUrl: 'http://example.com/archive',
        hasArchivedPosts: true,
        ...overrides
    };
};

/**
 * Mock Cleanup Utilities
 * Consistent cleanup between tests
 */
export const cleanupTestEnvironment = () => {
    jest.clearAllMocks();
    document.body.innerHTML = '';
    delete global.archivedPostStatus;
    delete global.wp;
    delete global.confirm;

    // More robust cleanup for window.confirm mock
    if (global.window) {
        delete global.window.confirm;
    }
};
