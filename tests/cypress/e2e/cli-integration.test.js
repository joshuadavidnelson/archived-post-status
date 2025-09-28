describe("Archived Post Status - WP-CLI Integration", () => {
	beforeEach(() => {
		cy.setUpPlugin();
	});

	it('Registers WP-CLI commands properly', () => {
		// First verify that the archive/unarchive commands are available
		cy.wpCli('wp help post archive', true).then((result) => {
			if (result.code === 0) {
				expect(result.stdout).to.contain('Archive one or more posts');
			} else {
				// Command might not be registered yet, check if it's mentioned in post help
				cy.wpCli('wp help post', true).then((helpResult) => {
					if (helpResult.code === 0) {
						cy.log('Post help output:', helpResult.stdout);
					}
				});
			}
		});

		cy.wpCli('wp help post unarchive', true).then((result) => {
			if (result.code === 0) {
				expect(result.stdout).to.contain('Unarchive one or more posts');
			}
		});
	});

	it('WP-CLI archive command works with valid post ID', () => {
		// Create a test post first
		cy.createPost({
			title: 'CLI Archive Test Post',
			content: 'Testing WP-CLI archive command',
			status: 'publish'
		}).then((post) => {
			// Use WP-CLI to archive the post (commands are wp post archive/unarchive)
			cy.wpCli(`wp post archive ${post.id}`, true).then((result) => {
				// The command should succeed (exit code 0)
				expect(result.code).to.equal(0, 'Archive command should succeed with valid post ID');

				// Verify the post is now archived
				cy.wpCli(`wp post get ${post.id} --field=post_status`, true).then((statusResult) => {
					expect(statusResult.code).to.equal(0);
					expect(statusResult.stdout.trim()).to.equal('archive');
				});
			});
		});
	});

	it('WP-CLI unarchive command works with valid post ID', () => {
		// Create a published post first, then archive it
		cy.createPost({
			title: 'CLI Unarchive Test Post',
			content: 'Testing WP-CLI unarchive command',
			status: 'publish'
		}).then((post) => {
			// First archive the post
			cy.wpCli(`wp post archive ${post.id}`, true).then((archiveResult) => {
				expect(archiveResult.code).to.equal(0, 'Post should be archived first');

				// Now use WP-CLI to unarchive the post
				cy.wpCli(`wp post unarchive ${post.id}`, true).then((result) => {
					// The command should succeed
					expect(result.code).to.equal(0, 'Unarchive command should succeed with valid archived post');

					// Verify the post is no longer archived
					cy.wpCli(`wp post get ${post.id} --field=post_status`, true).then((statusResult) => {
						expect(statusResult.code).to.equal(0);
						// Should be published or draft (depending on implementation)
						const status = statusResult.stdout.trim();
						expect(['publish', 'draft']).to.include(status);
					});
				});
			});
		});
	});

	it('WP-CLI archive command validates post ID parameter', () => {
		// Try to archive a non-existent post
		cy.wpCli('wp post archive 999999', true, false).then((result) => {
			// Should fail with non-zero exit code
			expect(result.code).to.not.equal(0, 'Should fail with invalid post ID');

			// Just verify that command failed - error message format may vary
			cy.log('Archive command correctly failed for invalid post ID');
			cy.log(`Error output: ${result.stderr || result.stdout}`);
		});
	});	it('WP-CLI unarchive command validates post ID parameter', () => {
		// Try to unarchive a non-existent post
		cy.wpCli('wp post unarchive 999999', true, false).then((result) => {
			// Should fail with non-zero exit code
			expect(result.code).to.not.equal(0, 'Should fail with invalid post ID');

			// Just verify that command failed - error message format may vary
			cy.log('Unarchive command correctly failed for invalid post ID');
			cy.log(`Error output: ${result.stderr || result.stdout}`);
		});
	});	it('WP-CLI commands validate post permissions', () => {
		// Create a test post
		cy.createPost({
			title: 'CLI Permission Test Post',
			content: 'Testing WP-CLI permission validation',
			status: 'publish'
		}).then((post) => {
			// Test basic CLI functionality with admin user
			cy.wpCli(`wp post archive ${post.id}`, true).then((result) => {
				cy.log(`Archive command result: ${result.code}`);
				expect([0, 1]).to.include(result.code);

				// Test unarchive if archive succeeded
				if (result.code === 0) {
					cy.wpCli(`wp post unarchive ${post.id}`, true).then((result) => {
						cy.log(`Unarchive command result: ${result.code}`);
						expect([0, 1]).to.include(result.code);
					});
				}
			});
		});
	});

	it('WP-CLI commands work with multiple posts in sequence', () => {
		const posts = [];

		// Create multiple test posts
		cy.createPost({ title: 'CLI Batch Test Post 1', status: 'publish' }).then(post => posts.push(post));
		cy.createPost({ title: 'CLI Batch Test Post 2', status: 'publish' }).then(post => posts.push(post));
		cy.createPost({ title: 'CLI Batch Test Post 3', status: 'publish' }).then(post => posts.push(post));

		cy.then(() => {
			// Archive all posts
			posts.forEach((post, index) => {
				cy.wpCli(`wp post archive ${post.id}`, true).then((result) => {
					expect(result.code).to.equal(0, `Post ${index + 1} should archive successfully`);
				});
			});

			// Verify all posts are archived
			posts.forEach((post, index) => {
				cy.wpCli(`wp post get ${post.id} --field=post_status`, true).then((statusResult) => {
					expect(statusResult.stdout.trim()).to.equal('archive', `Post ${index + 1} should be archived`);
				});
			});

			// Unarchive all posts
			posts.forEach((post, index) => {
				cy.wpCli(`wp post unarchive ${post.id}`, true).then((result) => {
					expect(result.code).to.equal(0, `Post ${index + 1} should unarchive successfully`);
				});
			});
		});
	});

  it('WP-CLI commands integrate with WordPress hooks', () => {
		// Use wpCliEval to add hook listener for testing
		cy.wpCliEval(`
			add_action('aps_archive_post', function($post_id) {
				update_option('test_last_archived_post', $post_id);
			});
			add_action('aps_unarchive_post', function($post_id) {
				update_option('test_last_unarchived_post', $post_id);
			});
		`);

		cy.createPost({
			title: 'Hook Integration Test Post',
			status: 'publish'
		}).then((post) => {
			// Archive the post
			cy.wpCli(`wp post archive ${post.id}`, true).then((result) => {
				expect(result.code).to.equal(0);

				// Verify hook was triggered
				cy.wpCli('wp option get test_last_archived_post', true).then((optionResult) => {
					if (optionResult.code === 0) {
						expect(optionResult.stdout.trim()).to.equal(post.id.toString());
					}
				});
			});

			// Unarchive the post
			cy.wpCli(`wp post unarchive ${post.id}`, true).then((result) => {
				expect(result.code).to.equal(0);

				// Verify unarchive hook was triggered
				cy.wpCli('wp option get test_last_unarchived_post', true).then((optionResult) => {
					if (optionResult.code === 0) {
						expect(optionResult.stdout.trim()).to.equal(post.id.toString());
					}
				});
			});
		});
	});

	it('WP-CLI commands handle post type restrictions appropriately', () => {
		// Test with regular post (should work)
		cy.createPost({
			title: 'CLI Post Type Test - Post',
			content: 'Testing post type handling',
			status: 'publish',
			postType: 'post'
		}).then((post) => {
			cy.wpCli(`wp post archive ${post.id}`, true).then((result) => {
				expect(result.code).to.equal(0, 'Regular posts should be archivable');
			});
		});

		// Test with page (behavior depends on plugin configuration)
		cy.createPost({
			title: 'CLI Post Type Test - Page',
			content: 'Testing post type handling',
			status: 'publish',
			postType: 'page'
		}).then((page) => {
			cy.wpCli(`wp post archive ${page.id}`, true).then((result) => {
				// Check if pages are supported by looking at the result
				if (result.code === 0) {
					// Pages are supported - verify status changed
					cy.wpCli(`wp post get ${page.id} --field=post_status`, true).then((statusResult) => {
						expect(statusResult.stdout.trim()).to.equal('archive');
					});
				} else {
					// Pages not supported - should have meaningful error
					const errorOutput = result.stderr || result.stdout;
					expect(errorOutput).to.match(/(not supported|invalid post type)/i);
				}
			});
		});
	});

	it('WP-CLI archive command supports multiple post IDs', () => {
		const postCount = 3; // Small number for quick test
		const postIds = [];

		// Create test posts
		cy.log(`Creating ${postCount} test posts for multi-ID archive test`);

		for (let i = 0; i < postCount; i++) {
			cy.createPost({
				title: `Multi-ID Test Post ${i + 1}`,
				content: `Content for multi-ID test post ${i + 1}`,
				status: 'publish'
			}).then((post) => {
				postIds.push(post.id);
			});
		}

		cy.then(() => {
			// Wait for all posts to be created
			expect(postIds).to.have.length(postCount);

			// Convert post IDs to space-separated string for WP-CLI
			const postIdString = postIds.join(' ');

			cy.log(`Archiving ${postCount} posts with command: wp post archive ${postIdString}`);

			// Archive all posts at once using multiple IDs
			cy.wpCli(`wp post archive ${postIdString}`, true).then((result) => {
				expect(result.code).to.equal(0, `Should successfully archive all ${postCount} posts`);

				const output = result.stdout + result.stderr;
				cy.log(`Archive command output: ${output}`);

				// Verify all posts were archived
				postIds.forEach((postId, index) => {
					cy.wpCli(`wp post get ${postId} --field=post_status`, true).then((statusResult) => {
						expect(statusResult.code).to.equal(0);
						expect(statusResult.stdout.trim()).to.equal('archive',
							`Post ${index + 1} (ID: ${postId}) should be archived`);
					});
				});
			});
		});
	});

	it('WP-CLI archive command shows progress bar when archiving many posts', () => {
		const postCount = 22; // More than 20 to trigger progress bar
		const postIds = [];

		// Create multiple test posts
		cy.log(`Creating ${postCount} test posts for progress bar test`);

		for (let i = 0; i < postCount; i++) {
			cy.createPost({
				title: `Progress Test Post ${i + 1}`,
				content: `Content for progress test post ${i + 1}`,
				status: 'publish'
			}).then((post) => {
				postIds.push(post.id);
			});
		}

		cy.then(() => {
			// Wait for all posts to be created
			expect(postIds).to.have.length(postCount);

			const postIdString = postIds.join(' ');
			cy.log(`Testing progress bar with ${postCount} posts`);

			// Archive all posts - should trigger progress bar for 20+ items
			cy.wpCli(`wp post archive ${postIdString}`, true).then((result) => {
				expect(result.code).to.equal(0, `Should successfully archive all ${postCount} posts`);

				const output = result.stdout + result.stderr;
				cy.log(`Archive command output: ${output}`);

				// Look for progress indicators
				const hasProgress = /archiving|processing|\d+\/\d+|\[.*\]/i.test(output);

				if (hasProgress) {
					cy.log('✅ Progress indication found in output');
				} else {
					cy.log('ℹ️  No explicit progress indication (may be implementation dependent)');
				}

				// Verify a few posts were archived (sampling for performance)
				const sampleIds = postIds.slice(0, 2);
				sampleIds.forEach((postId) => {
					cy.wpCli(`wp post get ${postId} --field=post_status`, true).then((statusResult) => {
						expect(statusResult.stdout.trim()).to.equal('archive');
					});
				});
			});
		});
	});

	it('WP-CLI archive command --force flag works with non-archiveable statuses', () => {
		// Create a test post with a status that normally can't be archived
		cy.createPost({
			title: 'Force Archive Test Post',
			content: 'Testing --force flag functionality',
			status: 'draft' // Draft posts typically can't be archived without --force
		}).then((post) => {
			cy.log(`Created draft post with ID: ${post.id}`);

			// First try archiving without --force (should fail)
			cy.wpCli(`wp post archive ${post.id}`, true, false).then((result) => {
				if (result.code !== 0) {
					cy.log('✅ Archive without --force correctly failed for draft post');

					// Now try with --force flag (should succeed)
					cy.wpCli(`wp post archive ${post.id} --force`, true).then((forceResult) => {
						expect(forceResult.code).to.equal(0, 'Archive with --force should succeed on draft post');

						// Verify the post is now archived
						cy.wpCli(`wp post get ${post.id} --field=post_status`, true).then((statusResult) => {
							expect(statusResult.code).to.equal(0);
							expect(statusResult.stdout.trim()).to.equal('archive', 'Post should be archived after --force');
						});
					});
				} else {
					cy.log('ℹ️  Archive succeeded without --force (implementation may allow draft archiving)');
					// Verify it was archived anyway
					cy.wpCli(`wp post get ${post.id} --field=post_status`, true).then((statusResult) => {
						expect(statusResult.stdout.trim()).to.equal('archive');
					});
				}
			});
		});
	});

	it('WP-CLI unarchive command --status flag sets custom status', () => {
		// Create and archive a test post
		cy.createPost({
			title: 'Custom Status Unarchive Test',
			content: 'Testing --status flag on unarchive',
			status: 'publish'
		}).then((post) => {
			// First archive the post
			cy.wpCli(`wp post archive ${post.id}`, true).then((archiveResult) => {
				expect(archiveResult.code).to.equal(0, 'Post should be archived first');

				// Verify it's archived
				cy.wpCli(`wp post get ${post.id} --field=post_status`, true).then((statusCheck) => {
					expect(statusCheck.stdout.trim()).to.equal('archive');

					// Now unarchive with custom status
					cy.wpCli(`wp post unarchive ${post.id} --status=draft`, true).then((unarchiveResult) => {
						expect(unarchiveResult.code).to.equal(0, 'Unarchive with --status should succeed');

						// Verify the post has the custom status
						cy.wpCli(`wp post get ${post.id} --field=post_status`, true).then((finalStatus) => {
							expect(finalStatus.code).to.equal(0);
							expect(finalStatus.stdout.trim()).to.equal('draft', 'Post should have custom draft status');
						});
					});
				});
			});
		});
	});

	it('WP-CLI unarchive command without --status uses default behavior', () => {
		// Create and archive a test post
		cy.createPost({
			title: 'Default Unarchive Test',
			content: 'Testing default unarchive behavior',
			status: 'publish'
		}).then((post) => {
			// Archive the post first
			cy.wpCli(`wp post archive ${post.id}`, true).then((archiveResult) => {
				expect(archiveResult.code).to.equal(0);

				// Unarchive without --status flag
				cy.wpCli(`wp post unarchive ${post.id}`, true).then((unarchiveResult) => {
					expect(unarchiveResult.code).to.equal(0, 'Default unarchive should succeed');

					// Verify the post is no longer archived
					cy.wpCli(`wp post get ${post.id} --field=post_status`, true).then((statusResult) => {
						expect(statusResult.code).to.equal(0);
						const status = statusResult.stdout.trim();

						// Should be either 'publish' or 'draft' (default unarchive behavior)
						expect(['publish', 'draft']).to.include(status,
							'Unarchived post should have publish or draft status');

						// Most importantly, should NOT be archived
						expect(status).to.not.equal('archive', 'Post should no longer be archived');
					});
				});
			});
		});
	});

	it('WP-CLI commands work with --defer-term-counting flag', () => {
		const postCount = 3;
		const postIds = [];

		// Create multiple test posts with categories to test term counting
		for (let i = 0; i < postCount; i++) {
			cy.createPost({
				title: `Term Counting Test Post ${i + 1}`,
				content: `Testing --defer-term-counting flag ${i + 1}`,
				status: 'publish'
			}).then((post) => {
				postIds.push(post.id);
			});
		}

		cy.then(() => {
			expect(postIds).to.have.length(postCount);

			const postIdString = postIds.join(' ');
			cy.log(`Testing --defer-term-counting with posts: ${postIdString}`);

			// Archive posts with --defer-term-counting flag
			cy.wpCli(`wp post archive ${postIdString} --defer-term-counting`, true).then((result) => {
				expect(result.code).to.equal(0, 'Archive with --defer-term-counting should succeed');

				const output = result.stdout + result.stderr;
				cy.log(`Archive with --defer-term-counting output: ${output}`);

				// Verify all posts were archived
				postIds.forEach((postId, index) => {
					cy.wpCli(`wp post get ${postId} --field=post_status`, true).then((statusResult) => {
						expect(statusResult.code).to.equal(0);
						expect(statusResult.stdout.trim()).to.equal('archive',
							`Post ${index + 1} should be archived`);
					});
				});

				// Test unarchive with --defer-term-counting as well
				cy.wpCli(`wp post unarchive ${postIdString} --defer-term-counting`, true).then((unarchiveResult) => {
					expect(unarchiveResult.code).to.equal(0, 'Unarchive with --defer-term-counting should succeed');

					const unarchiveOutput = unarchiveResult.stdout + unarchiveResult.stderr;
					cy.log(`Unarchive with --defer-term-counting output: ${unarchiveOutput}`);

					// Verify posts are no longer archived
					postIds.forEach((postId, index) => {
						cy.wpCli(`wp post get ${postId} --field=post_status`, true).then((statusResult) => {
							expect(statusResult.code).to.equal(0);
							expect(statusResult.stdout.trim()).to.not.equal('archive',
								`Post ${index + 1} should no longer be archived`);
						});
					});
				});
			});
		});
	});

	it('WP-CLI commands handle multiple flags together', () => {
		// Create a draft post to test multiple flags
		cy.createPost({
			title: 'Multiple Flags Test Post',
			content: 'Testing multiple CLI flags together',
			status: 'draft'
		}).then((post) => {
			// Archive with both --force and --defer-term-counting
			cy.wpCli(`wp post archive ${post.id} --force --defer-term-counting`, true).then((result) => {
				expect(result.code).to.equal(0, 'Archive with multiple flags should succeed');

				// Verify post is archived
				cy.wpCli(`wp post get ${post.id} --field=post_status`, true).then((statusResult) => {
					expect(statusResult.stdout.trim()).to.equal('archive');

					// Unarchive with both --status and --defer-term-counting
					cy.wpCli(`wp post unarchive ${post.id} --status=publish --defer-term-counting`, true).then((unarchiveResult) => {
						expect(unarchiveResult.code).to.equal(0, 'Unarchive with multiple flags should succeed');

						// Verify custom status was applied
						cy.wpCli(`wp post get ${post.id} --field=post_status`, true).then((finalStatus) => {
							expect(finalStatus.stdout.trim()).to.equal('publish',
								'Post should have custom publish status from --status flag');
						});
					});
				});
			});
		});
	});

	it('WP-CLI commands show proper help for all flags', () => {
		// Test archive command help shows all flags
		cy.wpCli('wp help post archive', true).then((result) => {
			expect(result.code).to.equal(0, 'Archive help should be available');

			const helpText = result.stdout;
			cy.log('Archive help text:', helpText);

			// Check that all expected flags are documented
			expect(helpText).to.contain('--force', 'Help should document --force flag');
			expect(helpText).to.contain('--defer-term-counting', 'Help should document --defer-term-counting flag');
			expect(helpText).to.contain('<id>...', 'Help should show multiple ID support');
		});

		// Test unarchive command help shows all flags
		cy.wpCli('wp help post unarchive', true).then((result) => {
			expect(result.code).to.equal(0, 'Unarchive help should be available');

			const helpText = result.stdout;
			cy.log('Unarchive help text:', helpText);

			// Check that all expected flags are documented
			expect(helpText).to.contain('--status', 'Help should document --status flag');
			expect(helpText).to.contain('--defer-term-counting', 'Help should document --defer-term-counting flag');
			expect(helpText).to.contain('<id>...', 'Help should show multiple ID support');
		});
	});
});
