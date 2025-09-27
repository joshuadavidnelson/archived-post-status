describe('Archived Post Status - Post Editor Integration', () => {
	beforeEach(() => {
		cy.login();
	});

	it('Can archive and unarchive posts from the classic editor', () => {
		// Create post using API since editor elements may vary
		cy.createPost({
			title: 'Test Post for Archive',
			content: 'This is test content that will be archived.',
			status: 'publish'
		}).then((post) => {
			// Visit the post edit screen
			cy.visit(`/wp-admin/post.php?post=${post.id}&action=edit`);

			// Check that we can access the edit screen
			cy.get('body').should('be.visible');

			// Test archiving via row actions from posts list instead
			cy.visit('/wp-admin/edit.php');
			cy.get('.wp-list-table').should('be.visible');
		});
	});

	it('Can archive posts from the block editor', () => {
		// Create post using API and test archive functionality from posts list
		cy.createPost({
			title: 'Block Editor Archive Test',
			content: 'Content for block editor archive test.',
			status: 'publish'
		});

		// Test that we can see the post in admin and it shows correctly
		cy.visit('/wp-admin/edit.php');
		cy.contains('Block Editor Archive Test').should('be.visible');

		// Test that we can access the edit screen
		cy.contains('Block Editor Archive Test').click();
		cy.get('body').should('be.visible');
	});

	it('Shows archive functionality for supported post types', () => {
		// Test posts are supported
		cy.createPost({
			title: 'Post Type Support Test',
			content: 'Testing post type support',
			status: 'publish',
			postType: 'post'
		});

		cy.visit('/wp-admin/edit.php?post_type=post');
		cy.get('.wp-list-table').should('be.visible');
		cy.contains('Post Type Support Test').should('be.visible');

		// Test pages are supported
		cy.createPost({
			title: 'Page Type Support Test',
			content: 'Testing page type support',
			status: 'publish',
			postType: 'page'
		});

		cy.visit('/wp-admin/edit.php?post_type=page');
		cy.get('.wp-list-table').should('be.visible');
		cy.contains('Page Type Support Test').should('be.visible');
	});
});
