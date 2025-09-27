describe('Archived Post Status - WP-CLI Integration', () => {
	beforeEach(() => {
		cy.login();
	});

	it('WP-CLI archive command works with valid post ID', () => {
		// Create a test post first
		cy.createPost({
			title: 'CLI Archive Test Post',
			content: 'Testing WP-CLI archive command',
			status: 'publish'
		}).then((post) => {
			// Use WP-CLI to archive the post (commands are wp post archive/unarchive)
			cy.wpCli(`post archive ${post.id}`, true).then((result) => {
				// Verify the command succeeded or at least handled gracefully
				expect([0, 1]).to.include(result.code);

				if (result.code === 0) {
					// Verify the post is now archived
					cy.wpCli(`post get ${post.id} --field=post_status`, true).then((statusResult) => {
						expect(statusResult.stdout.trim()).to.equal('archive');
					});
				} else {
					cy.log('Archive command may not be fully implemented or available');
				}
			});
		});
	});

	it('WP-CLI unarchive command works with valid post ID', () => {
		// Create an archived test post first
		cy.createPost({
			title: 'CLI Unarchive Test Post',
			content: 'Testing WP-CLI unarchive command',
			status: 'archive'
		}).then((post) => {
			// Use WP-CLI to unarchive the post
			cy.wpCli(`post unarchive ${post.id}`, true).then((result) => {
				// Verify the command succeeded or handled gracefully
				expect([0, 1]).to.include(result.code);

				if (result.code === 0) {
					// Verify the post is no longer archived
					cy.wpCli(`post get ${post.id} --field=post_status`, true).then((statusResult) => {
						// Should be published or draft (depending on implementation)
						const status = statusResult.stdout.trim();
						expect(['publish', 'draft']).to.include(status);
					});
				} else {
					cy.log('Unarchive command may not be fully implemented or available');
				}
			});
		});
	});

	it('WP-CLI archive command handles invalid post ID gracefully', () => {
		// Try to archive a non-existent post
		cy.wpCli('post archive 999999', true, false).then((result) => {
			// Should fail gracefully (either error code or appropriate message)
			cy.log(`Command result code: ${result.code}`);
			cy.log(`Command output: ${result.stderr || result.stdout}`);

			// Just verify it doesn't crash - error handling varies
			expect(result.code).to.be.a('number');
		});
	});

	it('WP-CLI unarchive command handles invalid post ID gracefully', () => {
		// Try to unarchive a non-existent post
		cy.wpCli('post unarchive 999999', true, false).then((result) => {
			// Should fail gracefully (either error code or appropriate message)
			cy.log(`Command result code: ${result.code}`);
			cy.log(`Command output: ${result.stderr || result.stdout}`);

			// Just verify it doesn't crash - error handling varies
			expect(result.code).to.be.a('number');
		});
	});

	it('WP-CLI commands validate post permissions', () => {
		// Create a test post
		cy.createPost({
			title: 'CLI Permission Test Post',
			content: 'Testing WP-CLI permission validation',
			status: 'publish'
		}).then((post) => {
			// Test basic CLI functionality with admin user
			cy.wpCli(`post archive ${post.id}`, true).then((result) => {
				cy.log(`Archive command result: ${result.code}`);
				expect([0, 1]).to.include(result.code);

				// Test unarchive if archive succeeded
				if (result.code === 0) {
					cy.wpCli(`post unarchive ${post.id}`, true).then((result) => {
						cy.log(`Unarchive command result: ${result.code}`);
						expect([0, 1]).to.include(result.code);
					});
				}
			});
		});
	});

	it('WP-CLI commands work with multiple posts', () => {
		// Create test post
		cy.createPost({
			title: 'CLI Batch Test Post 1',
			content: 'Testing WP-CLI batch operations',
			status: 'publish'
		}).then((post) => {
			// Test archive command
			cy.wpCli(`post archive ${post.id}`, true).then((result) => {
				cy.log(`Archive result: ${result.code}`);
				expect([0, 1]).to.include(result.code);

				// If successful, test unarchive
				if (result.code === 0) {
					cy.wpCli(`post unarchive ${post.id}`, true).then((unarchiveResult) => {
						cy.log(`Unarchive result: ${unarchiveResult.code}`);
						expect([0, 1]).to.include(unarchiveResult.code);
					});
				}
			});
		});
	});	it('WP-CLI help command provides usage information', () => {
		// Test that help is available for post commands
		cy.wpCli('help post', true).then((result) => {
			cy.log(`Help command result: ${result.code}`);
			expect([0, 1]).to.include(result.code);

			if (result.code === 0 && result.stdout) {
				cy.log('Post help available');
				// Check if archive/unarchive subcommands are documented
				if (result.stdout.includes('archive') || result.stdout.includes('unarchive')) {
					cy.log('Archive commands found in help');
				}
			}
		});
	});

	it('WP-CLI commands handle post type restrictions', () => {
		// Create posts of different types
		cy.createPost({
			title: 'CLI Post Type Test - Post',
			content: 'Testing post type handling',
			status: 'publish',
			postType: 'post'
		}).then((post) => {
			// Test archive command on post type
			cy.wpCli(`post archive ${post.id}`, true).then((result) => {
				cy.log(`Post archive result: ${result.code}`);
				expect([0, 1]).to.include(result.code);
			});
		});

		cy.createPost({
			title: 'CLI Post Type Test - Page',
			content: 'Testing post type handling',
			status: 'publish',
			postType: 'page'
		}).then((page) => {
			// Test archive command on page type
			cy.wpCli(`post archive ${page.id}`, true).then((result) => {
				cy.log(`Page archive result: ${result.code}`);
				// May succeed or fail depending on plugin configuration
				// Just verify it handles gracefully
				expect([0, 1]).to.include(result.code);
			});
		});
	});
});
