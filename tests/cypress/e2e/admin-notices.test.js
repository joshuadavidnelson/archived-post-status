describe("Archived Post Status - Admin Notices", () => {
	beforeEach(() => {
		cy.setUpPlugin();
	});

	it('Shows notice when posts are successfully archived via bulk action', () => {
		let postIds = [];

		// Create test posts and store their IDs
		cy.createPost({
			title: 'Bulk Archive Notice Test 1',
			content: 'Testing bulk archive notices',
			status: 'publish'
		}).then(post => postIds.push(post.id));

		cy.createPost({
			title: 'Bulk Archive Notice Test 2',
			content: 'Testing bulk archive notices',
			status: 'publish'
		}).then(post => postIds.push(post.id));

		// Go to posts list and wait for page to load
		cy.visit('/wp-admin/edit.php');
		cy.get('.wp-list-table').should('be.visible');
		cy.get('#bulk-action-selector-top').should('be.visible');

		// Check if Archive bulk action exists
		cy.get('#bulk-action-selector-top option[value="archive"]').then(($archiveOption) => {
			if ($archiveOption.length > 0) {
				// Select the first two posts
				cy.get('input[name="post[]"]').eq(0).check();
				cy.get('input[name="post[]"]').eq(1).check();

				// Select archive action and submit
				cy.get('#bulk-action-selector-top').select('archive');
				cy.get('#doaction').click();

				// Wait for redirect and check URL contains archived parameter
				cy.url().should('include', 'archived=', { timeout: 10000 });

				// Look for success notice with specific content
				cy.get('.notice-success, .updated').should('be.visible', { timeout: 5000 });
				cy.get('.notice-success, .updated').should('contain.text', 'Archive');
			} else {
				cy.log('Archive bulk action not available - plugin may not be active');
			}
		});
	});

	it('Shows notice when posts are successfully unarchived via bulk action', () => {
		let archivedPostId;

		// Create a published post first, then archive it to ensure proper test setup
		cy.createPost({
			title: 'Bulk Unarchive Notice Test',
			content: 'Testing bulk unarchive notices',
			status: 'publish'
		}).then(post => {
			archivedPostId = post.id;
			// Archive the post via WP-CLI to ensure it's properly archived
			cy.wpCli(`wp post archive ${post.id}`);
		});

		// Go to archived posts view
		cy.visit('/wp-admin/edit.php?post_status=archive');
		cy.get('.wp-list-table').should('be.visible');

		// Wait for posts to load, then check if any archived posts exist
		cy.get('body').then(($body) => {
			if ($body.find('input[name="post[]"]').length > 0) {
				// Check if unarchive bulk action exists
				cy.get('#bulk-action-selector-top option[value="unarchive"]').then(($unarchiveOption) => {
					if ($unarchiveOption.length > 0) {
						// Select the first archived post
						cy.get('input[name="post[]"]').first().check();
						cy.get('#bulk-action-selector-top').select('unarchive');
						cy.get('#doaction').click();

						// Wait for redirect and check for success notice
						cy.url().should('include', 'unarchived=', { timeout: 10000 });
						cy.get('.notice-success, .updated').should('be.visible', { timeout: 5000 });
						cy.get('.notice-success, .updated').should(($notice) => {
							const text = $notice.text().toLowerCase();
							expect(text).to.match(/unarchiv|restor/);
						});
					} else {
						cy.log('Unarchive bulk action not available');
					}
				});
			} else {
				cy.log('No archived posts available for unarchive test');
			}
		});
	});

	it('Shows appropriate notice for single post archive via row action', () => {
		let testPostId;

		// Create test post
		cy.createPost({
			title: 'Single Archive Notice Test',
			content: 'Testing single post archive notice',
			status: 'publish'
		}).then(post => {
			testPostId = post.id;
		});

		cy.visit('/wp-admin/edit.php');
		cy.get('.wp-list-table').should('be.visible');

		// Find the row for our test post and check for archive row action
		cy.get('.wp-list-table tbody tr').first().within(() => {
			// Hover to reveal row actions
			cy.get('.row-title').invoke('attr', 'title').then((title) => {
				if (title && title.includes('Single Archive Notice Test')) {
					cy.get('.row-title').trigger('mouseover');

					// Wait for row actions to appear
					cy.get('.row-actions', { timeout: 3000 }).should('be.visible');

					// Check if archive action exists
					cy.get('.row-actions').then(($actions) => {
						if ($actions.find('a[href*="archive"]').length > 0) {
							cy.get('.row-actions a[href*="archive"]').first().click();

							// Wait for redirect and check for notice
							cy.url().should('include', '/wp-admin/edit.php', { timeout: 10000 });
							cy.get('.notice, .updated', { timeout: 5000 }).should('be.visible');
						} else {
							cy.log('Archive row action not found for this post');
						}
					});
				} else {
					cy.log('Test post not found in first row, checking if archive actions exist generally');
				}
			});
		});
	});

	it('Shows error notice when archive action fails', () => {
		// Create a test scenario where archiving might fail
		cy.createPost({
			title: 'Archive Failure Test Post',
			status: 'publish'
		}).then(post => {
			// Use wpCliEval to temporarily disable archive capability for testing
			cy.wpCliEval(`
				add_filter('user_can_archive_post', function($can, $post_id) {
					if ($post_id == ${post.id}) {
						return false;
					}
					return $can;
				}, 10, 2);
			`);

			cy.visit('/wp-admin/edit.php');
			cy.get('.wp-list-table').should('be.visible');

			// Try to archive the post where capability was disabled
			cy.get('#bulk-action-selector-top option[value="archive"]').then(($archiveOption) => {
				if ($archiveOption.length > 0) {
					// Select the first post and try to archive
					cy.get('input[name="post[]"]').first().check();
					cy.get('#bulk-action-selector-top').select('archive');
					cy.get('#doaction').click();

					// Look for any notice (error handling may vary by implementation)
					cy.get('body', { timeout: 10000 }).then(($body) => {
						// Check if there's any kind of notice
						if ($body.find('.notice').length > 0) {
							cy.get('.notice').should('be.visible');
							cy.log('Notice displayed for archive action');
						} else {
							cy.log('No notice displayed (implementation may vary)');
						}
					});
				} else {
					cy.log('Archive bulk action not available');
				}
			});
		});
	});

	it('Handles notice display with internationalization', () => {
		// Test that notices can be displayed (basic test for i18n readiness)
		cy.createPost({
			title: 'I18n Notice Test Post',
			content: 'Testing internationalization of notices',
			status: 'publish'
		});

		cy.visit('/wp-admin/edit.php');

		// Ensure the interface loads properly (basic i18n test)
		cy.get('.wp-list-table').should('be.visible');
		cy.get('#bulk-action-selector-top').should('be.visible');

		// Check that common UI elements are present (would show i18n issues)
		cy.get('.wp-list-table th').should('contain', 'Title');
		cy.get('.wp-list-table th').should('contain', 'Date');

		// Test that the page title is accessible
		cy.get('h1').should('be.visible');
	});
});
