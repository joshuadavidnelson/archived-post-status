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
	cy.login();
	cy.deactivateAllPlugins();
	cy.activatePlugin('archived-post-status');
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
	return cy.wpCli(`post update ${postId} --post_status=archive`, true);
});

Cypress.Commands.add('unarchivePost', (postId) => {
	return cy.wpCli(`post update ${postId} --post_status=publish`, true);
});

Cypress.Commands.add('getArchivedPosts', () => {
	return cy.wpCli('post list --post_status=archive --format=json', true);
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
	cy.wpCli('post delete --post_status=archive --force', true);
	cy.wpCli('post delete --post_status=publish --force', true);
});

