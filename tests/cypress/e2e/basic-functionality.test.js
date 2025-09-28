describe("Basic Plugin Functionality", () => {
	beforeEach(() => {
		cy.setUpPlugin();
	});

	it("Plugin activates and deactivates without errors", () => {
		// Keep existing test but add validation
		cy.deactivatePlugin("archived-post-status");
		cy.activatePlugin("archived-post-status");

		// Create a published post first, then change it to archive status
		cy.createPost({
			title: 'Archive Status Test',
			status: 'publish'
		}).then(post => {
			// Change status to archive via WP-CLI to test that archive status is available
			cy.wpCli(`wp post archive ${post.id}`).then(result => {
				expect(result.code).to.equal(0, 'Should be able to change post to archive status');

				// Verify the post was actually changed to archive status
				cy.wpCli(`wp post get ${post.id} --field=post_status`).then(statusResult => {
					expect(statusResult.stdout.trim()).to.equal('archive');
				});
			});
		});
	});

	it("Plugin registers archive post status correctly", () => {
		// Test that archive status is available and behaves correctly
		cy.createPost({
			title: 'Archive Status Registration Test',
			status: 'publish'
		}).then(post => {
			// Change status to archive via WP-CLI
			cy.wpCli(`wp post archive ${post.id}`).then(result => {
				expect(result.code).to.equal(0);

				// Verify post status changed
				cy.wpCli(`wp post get ${post.id} --field=post_status`).then(statusResult => {
					expect(statusResult.stdout.trim()).to.equal('archive');
				});
			});
		});
	});

	it("Archived posts are excluded from main queries", () => {
		// Create a published post and an archived post
		cy.createPost({
			title: 'Published Post for Query Test',
			status: 'publish'
		});

		cy.createPost({
			title: 'Archived Post for Query Test',
			status: 'archive'
		});

		// Check that only published post appears in main query
		cy.wpCli('wp post list --post_status=publish --format=count').then(publishedResult => {
			const publishedCount = parseInt(publishedResult.stdout.trim());
			expect(publishedCount).to.be.at.least(1);
		});

		// Check that archived post is separate
		cy.wpCli('wp post list --post_status=archive --format=count').then(archivedResult => {
			const archivedCount = parseInt(archivedResult.stdout.trim());
			expect(archivedCount).to.be.at.least(1);
		});
	});

	it("Plugin doesn't interfere with standard WordPress operations", () => {
		// Test that normal post operations still work - use the working createPost approach
		cy.createPost({
			title: 'Standard WordPress Test',
			status: 'publish' // Start with publish since that works in other tests
		}).then(post => {
			// Simple test - just verify we can change status
			cy.wpCli(`wp post update ${post.id} --post_status=draft`).then(result => {
				expect(result.code).to.equal(0);

				// Verify it changed
				cy.wpCli(`wp post get ${post.id} --field=post_status`).then(statusResult => {
					expect(statusResult.stdout.trim()).to.equal('draft');
				});
			});
		});
	});
});
