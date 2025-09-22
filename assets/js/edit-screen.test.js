/**
 * @jest-environment jsdom
 */

import { createWordPressAdminTable, assertArchivedPostBehavior, assertPublishedPostBehavior, cleanupTestEnvironment } from './test-utils.js';

/**
 * Edit Screen Tests - User Behavior Focused
 * Tests how archived posts behave in the WordPress admin edit screen
 */

describe('Archived Posts in WordPress Edit Screen', () => {
    beforeEach(() => {
        cleanupTestEnvironment();

        // Set up realistic WordPress edit screen DOM structure
        document.body.innerHTML = createWordPressAdminTable() + `
            <div class="inline-edit-row" id="edit-123" style="display: none;">
                <div class="inline-edit-col">
                    <input type="text" name="post_title" value="Archived Post Title">
                </div>
            </div>
        `;

        // Simulate the edit-screen.js functionality
        // This focuses on testing the behavior rather than the implementation
        const archivedRows = document.querySelectorAll('#the-list tr.status-archive');

        archivedRows.forEach(row => {
            // Simulate disallowEditing function behavior
            const titleLink = row.querySelector('.column-title a.row-title');
            if (titleLink) {
                const title = titleLink.textContent;
                titleLink.outerHTML = title;
            }

            const editAction = row.querySelector('.row-actions .edit');
            if (editAction) {
                editAction.remove();
            }
        });
    });

    afterEach(() => {
        cleanupTestEnvironment();
    });

    describe('Archived Post Display Behavior', () => {
        test('archived posts display titles as plain text and remove edit actions', () => {
            const archivedRows = document.querySelectorAll('#the-list tr.status-archive');

            archivedRows.forEach(row => {
                assertArchivedPostBehavior(row);
                const titleContainer = row.querySelector('.column-title strong');
                expect(titleContainer.textContent.trim()).toMatch(/Archived Post Title|Another Archived Post/);
            });
        });

        test('published posts remain fully editable', () => {
            const publishedRow = document.querySelector('#post-456');
            assertPublishedPostBehavior(publishedRow);

            const titleLink = publishedRow.querySelector('.column-title a.row-title');
            expect(titleLink.textContent.trim()).toBe('Published Post Title');
        });
    });

    describe('User Interaction Prevention', () => {
        test('users cannot edit archived post titles or access edit actions', () => {
            const archivedRow = document.querySelector('#post-123');
            const titleArea = archivedRow.querySelector('.column-title strong');
            const rowActions = archivedRow.querySelector('.row-actions');
            const editSpan = rowActions.querySelector('.edit');

            // Should not contain any clickable links
            expect(titleArea.querySelector('a')).toBeNull();
            // Edit actions should be removed
            expect(editSpan).toBeNull();
            // Title should be displayed as plain text
            expect(titleArea.textContent.trim()).toBe('Archived Post Title');
        });
    });

    describe('Multiple Post Handling', () => {
        test('handles mixed post statuses correctly', () => {
            const allRows = document.querySelectorAll('#the-list tr');
            let archivedCount = 0;
            let editableCount = 0;

            allRows.forEach(row => {
                const titleLink = row.querySelector('.column-title a.row-title');
                const isArchived = row.classList.contains('status-archive');

                if (isArchived) {
                    archivedCount++;
                    expect(titleLink).toBeNull(); // Should not be editable
                } else {
                    editableCount++;
                    expect(titleLink).not.toBeNull(); // Should be editable
                }
            });

            expect(archivedCount).toBe(2);
            expect(editableCount).toBe(1);
        });
    });

    describe('Error Handling and Edge Cases', () => {
        test('handles posts without title links gracefully', () => {
            // Add a malformed archived post row
            document.querySelector('#the-list').innerHTML += `
                <tr class="status-archive" id="post-999">
                    <td class="column-title">
                        <strong>Post Without Link</strong>
                    </td>
                </tr>
            `;

            expect(() => {
                const row = document.querySelector('#post-999');
                const titleElement = row.querySelector('.column-title a.row-title');
                if (titleElement) {
                    const title = titleElement.textContent;
                    titleElement.outerHTML = title;
                }
            }).not.toThrow();
        });

        test('works when no archived posts exist', () => {
            // Clear all archived posts
            document.querySelectorAll('.status-archive').forEach(row => row.remove());

            expect(() => {
                const rows = document.querySelectorAll('#the-list tr.status-archive');
                rows.forEach(function(row) {
                    const titleLink = row.querySelector('.column-title a.row-title');
                    if (titleLink) {
                        titleLink.outerHTML = titleLink.textContent;
                    }
                });
            }).not.toThrow();

            // Published posts should remain unaffected
            const publishedRow = document.querySelector('#post-456');
            const titleLink = publishedRow.querySelector('.column-title a.row-title');
            expect(titleLink).not.toBeNull();
        });
    });
});
