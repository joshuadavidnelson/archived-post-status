describe("Admin can login and de/activate the plugin", () => {
	beforeEach(() => {
		cy.login();
	});

	it("Can activate plugin and deactivate plugin", () => {
		cy.activatePlugin("archived-post-status");
		cy.deactivatePlugin("archived-post-status");
		cy.activatePlugin("archived-post-status");
	});
});
