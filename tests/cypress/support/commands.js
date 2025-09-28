Cypress.Commands.add('testRedirect', (origin, expectedUrl = "", responseCode = 301) => {

	// Append the base url to the expected url.
	expectedUrl = Cypress.config().baseUrl + expectedUrl;

	// Test the redirect.
	return cy.request({
		url: origin,
		followRedirect: false, // turn off following redirects
	}).then((resp) => {
		expect(resp.status).to.eq(responseCode);
		expect(resp.redirectedToUrl).to.eq(expectedUrl);
	});
});

// Test that a url returns a specific response code.
Cypress.Commands.add('urlReturns', (origin, responseCode = 200, followRedirect=false) => {
	return cy.request({
		url: origin,
		followRedirect: followRedirect,
		failOnStatusCode: false,
	}).then((resp) => {
		expect(resp.status).to.eq(responseCode);
	});
});

Cypress.Commands.add('setUpPlugin', () => {
	// Use comprehensive WordPress reset
	cy.resetWordPress();

	// Deactivate all plugins to ensure clean state
	cy.deactivateAllPlugins();

	// Activate only our plugin
	cy.activatePlugin('archived-post-status');

	// Small delay to ensure plugin activation is complete
	cy.wait(500);
});

// Ensure block editor sidebar is open
Cypress.Commands.add('openEditorSidebar', () => {
	// Wait for editor to load
	cy.get('.edit-post-header, .editor-header', { timeout: 10000 }).should('be.visible');

	// Use keyboard shortcut as most reliable method (Cmd/Ctrl + Shift + ,)
	cy.get('body').type('{cmd+shift+,}');
	cy.wait(1000);

	// If keyboard shortcut didn't work, try clicking settings buttons
	cy.get('body').then(($body) => {
		if ($body.find('.edit-post-sidebar:visible, .interface-interface-skeleton__sidebar:visible').length === 0) {
			// Try common settings button patterns
			const possibleButtons = [
				'[aria-label*="Settings"]',
				'[aria-label*="Document settings"]',
				'.edit-post-header__settings button',
				'button[aria-expanded="false"]'
			];

			for (const buttonSelector of possibleButtons) {
				if ($body.find(buttonSelector).length > 0) {
					cy.get(buttonSelector).first().click({ force: true });
					cy.wait(500);
					break;
				}
			}
		}
	});

	// More flexible sidebar detection
	cy.get('.edit-post-sidebar, .interface-interface-skeleton__sidebar, .components-panel', { timeout: 8000 }).should('exist');
});

// Add comprehensive WordPress reset command
Cypress.Commands.add('resetWordPress', () => {
	cy.login();

	// Delete all posts and pages
	cy.wpCli('wp post delete $(wp post list --post_type=post --format=ids) --force', true, false);
	cy.wpCli('wp post delete $(wp post list --post_type=page --format=ids) --force', true, false);

	// Delete all users except admin
	cy.wpCli('wp user delete $(wp user list --role=editor,author,contributor,subscriber --format=ids) --yes', true, false);

	// Clear any transients
	cy.wpCli('wp transient delete --all', true, false);

	// Flush permalinks
	cy.wpCli('wp rewrite flush', true, false);
});

Cypress.Commands.add('installPlugin', (plugin,activate=true) => {
	cy.wpCli("plugin install " + plugin, true);
	if ( activate ) {
		cy.wpCli("plugin activate " + plugin, true);
	}
});

Cypress.Commands.add('deletePlugin', (plugin) => {
	cy.wpCli("plugin deactivate " + plugin, true);
	cy.wpCli("plugin delete " + plugin, true);
});

Cypress.Commands.add('checkUrls', (urls, responseCode = 200) => {
	urls.forEach((url) => {
		cy.log(url);
		cy.urlReturns(url, responseCode);
	});
});

// Archived Post Status specific commands
Cypress.Commands.add('createArchivedPost', (options = {}) => {
	const defaults = {
		title: 'Test Archived Post',
		content: 'This is an archived test post',
		status: 'archive'
	};
	const postData = { ...defaults, ...options };

	return cy.createPost(postData);
});

Cypress.Commands.add('archivePost', (postId) => {
	return cy.wpCli(`wp post archive ${postId}`, true);
});

Cypress.Commands.add('unarchivePost', (postId) => {
	return cy.wpCli(`wp post unarchive ${postId}`, true);
});

Cypress.Commands.add('getArchivedPosts', () => {
	return cy.wpCli('wp post list --post_status=archive --format=json', true);
});

Cypress.Commands.add('setupArchivedPostTests', () => {
	cy.login();
	cy.activatePlugin('archived-post-status');

	// Create test data
	cy.createPost({
		title: 'Published Test Post',
		content: 'This is a published post for testing',
		status: 'publish'
	});

	cy.createPost({
		title: 'Archived Test Post',
		content: 'This is an archived post for testing',
		status: 'archive'
	});
});

Cypress.Commands.add('cleanupArchivedPostTests', () => {
	// Clean up test posts
	cy.wpCli('wp post delete --post_status=archive --force', true);
	cy.wpCli('wp post delete --post_status=publish --force', true);
});

// Create test user with specific role
Cypress.Commands.add('createTestUser', (username, role = 'author', password = 'testpassword123') => {
	return cy.wpCli(`wp user create ${username} ${username}@example.com --role=${role} --user_pass=${password}`, true);
});

// Delete test user
Cypress.Commands.add('deleteTestUser', (username) => {
	return cy.wpCli(`wp user delete ${username} --yes`, true);
});

// Login as specific user
Cypress.Commands.add('loginAs', (username, password = 'testpassword123') => {
	cy.visit('/wp-login.php');
	cy.get('#user_login').clear().type(username);
	cy.get('#user_pass').clear().type(password);
	cy.get('#wp-submit').click();

	// Wait for login to complete
	cy.url().should('include', '/wp-admin/');
});

// Verify post status via CLI
Cypress.Commands.add('verifyPostStatus', (postId, expectedStatus) => {
	return cy.wpCli(`wp post get ${postId} --field=post_status`, true).then(result => {
		expect(result.stdout.trim()).to.equal(expectedStatus);
	});
});

// Wait for admin notice with specific text
Cypress.Commands.add('waitForNotice', (expectedText, type = 'success', timeout = 5000) => {
	const selector = type === 'error' ? '.notice-error' : '.notice-success, .updated';

	return cy.get(selector, { timeout })
		.should('be.visible')
		.should('contain.text', expectedText);
});

// Enhanced post creation with verification
Cypress.Commands.add('createVerifiedPost', (options = {}) => {
	return cy.createPost(options).then(post => {
		// Verify the post was created correctly
		cy.verifyPostStatus(post.id, options.status || 'publish');
		return cy.wrap(post);
	});
});

// Activate test helper plugin
Cypress.Commands.add('activateTestHelpers', () => {
	return cy.wpCli('plugin activate archived-post-status-test-helpers', true);
});

// Setup test environment with helper plugin
Cypress.Commands.add('setupTestEnvironment', () => {
	cy.login();
	cy.activatePlugin('archived-post-status');
	cy.activateTestHelpers();

	// Create test users via CLI
	cy.wpCli('wp aps-test setup', true);
});

// Cleanup test environment
Cypress.Commands.add('cleanupTestEnvironment', () => {
	cy.wpCli('wp aps-test cleanup', true);
});

// Wait for block editor to be fully loaded
Cypress.Commands.add('waitForBlockEditor', (timeout = 15000) => {
	cy.get('.block-editor', { timeout }).should('exist');
	cy.get('.edit-post-header', { timeout: 10000 }).should('be.visible');
	// Wait for editor to be interactive
	cy.get('.block-editor-writing-flow', { timeout: 10000 }).should('be.visible');
});

// Wait for classic editor to be loaded
Cypress.Commands.add('waitForClassicEditor', (timeout = 10000) => {
	cy.get('#post-body', { timeout }).should('be.visible');
	cy.get('#title', { timeout }).should('be.visible');
	cy.get('#submitdiv', { timeout }).should('be.visible');
});

// Force classic editor mode
Cypress.Commands.add('forceClassicEditor', () => {
	cy.wpCli('wp plugin install classic-editor --activate', true);
	cy.wpCli('wp option update classic-editor-replace block-editor', true);
});

// Restore block editor mode
Cypress.Commands.add('restoreBlockEditor', () => {
	cy.wpCli('wp option delete classic-editor-replace', true);
});

// Check if element exists without failing
Cypress.Commands.add('elementExists', (selector) => {
	return cy.get('body').then($body => {
		return $body.find(selector).length > 0;
	});
});

// Archive post via WP-CLI with error handling
Cypress.Commands.add('archivePostSafely', (postId) => {
	return cy.wpCli(`wp post archive ${postId}`, true).then(result => {
		if (result.code === 0) {
			cy.verifyPostStatus(postId, 'archive');
		}
		return cy.wrap(result);
	});
});

// Unarchive post via WP-CLI with error handling
Cypress.Commands.add('unarchivePostSafely', (postId) => {
	return cy.wpCli(`wp post unarchive ${postId}`, true).then(result => {
		if (result.code === 0) {
			// Verify post is no longer archived (could be publish or draft)
			cy.wpCli(`wp post get ${postId} --field=post_status`, true).then(statusResult => {
				const status = statusResult.stdout.trim();
				expect(['publish', 'draft']).to.include(status);
			});
		}
		return cy.wrap(result);
	});
});

