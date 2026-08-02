/**
 * Behavior tests for the shipped plugin-screen.js — the module under test is
 * imported, never re-implemented, so a regression in the real source fails
 * here.
 */
const { apsBindDeactivationWarning } = require( './plugin-screen' );

describe( 'plugin-screen: deactivation warning', () => {
	const WARNING_FRAGMENT = 'Deactivating this plugin';

	let globals;

	/**
	 * Render the plugins.php row and return its deactivate link.
	 *
	 * @param {string} slug Plugin row slug.
	 * @return {HTMLAnchorElement} The deactivate anchor.
	 */
	function renderPluginRow( slug = 'archived-post-status' ) {
		document.body.innerHTML = `
			<table><tbody>
				<tr data-slug="${ slug }">
					<td class="deactivate"><a href="/wp-admin/plugins.php?action=deactivate">Deactivate</a></td>
				</tr>
			</tbody></table>
		`;

		return document.querySelector( `tr[data-slug="${ slug }"] .deactivate a` );
	}

	/**
	 * Dispatch a cancelable click and report whether it was prevented.
	 *
	 * @param {Element} link Element to click.
	 * @return {boolean} True when preventDefault() was called.
	 */
	function click( link ) {
		const event = new window.MouseEvent( 'click', {
			bubbles: true,
			cancelable: true,
		} );
		link.dispatchEvent( event );

		return event.defaultPrevented;
	}

	beforeEach( () => {
		globals = {
			wp: { i18n: { __: jest.fn( ( text ) => text ) } },
			archivedPostStatus: { hasArchivedPosts: true },
			confirm: jest.fn( () => true ),
		};
	} );

	afterEach( () => {
		document.body.innerHTML = '';
	} );

	test( 'returns null and binds nothing when the plugin row is absent', () => {
		document.body.innerHTML = '<table><tbody></tbody></table>';

		expect( apsBindDeactivationWarning( document, globals ) ).toBeNull();
		expect( globals.confirm ).not.toHaveBeenCalled();
	} );

	test( "ignores other plugins' deactivate links", () => {
		const otherLink = renderPluginRow( 'some-other-plugin' );

		expect( apsBindDeactivationWarning( document, globals ) ).toBeNull();

		click( otherLink );
		expect( globals.confirm ).not.toHaveBeenCalled();
	} );

	test( 'binds only to the matching row when multiple plugin rows are present', () => {
		document.body.innerHTML = `
			<table><tbody>
				<tr data-slug="some-other-plugin">
					<td class="deactivate"><a href="/wp-admin/plugins.php?action=deactivate&plugin=other">Deactivate</a></td>
				</tr>
				<tr data-slug="archived-post-status">
					<td class="deactivate"><a href="/wp-admin/plugins.php?action=deactivate&plugin=aps">Deactivate</a></td>
				</tr>
			</tbody></table>
		`;
		const otherLink = document.querySelector(
			'tr[data-slug="some-other-plugin"] .deactivate a'
		);
		const ownLink = document.querySelector(
			'tr[data-slug="archived-post-status"] .deactivate a'
		);

		apsBindDeactivationWarning( document, globals );

		click( otherLink );
		expect( globals.confirm ).not.toHaveBeenCalled();

		click( ownLink );
		expect( globals.confirm ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'dismissing the confirm blocks deactivation', () => {
		const link = renderPluginRow();
		globals.confirm.mockReturnValue( false );

		apsBindDeactivationWarning( document, globals );

		expect( click( link ) ).toBe( true );
		expect( globals.confirm ).toHaveBeenCalledWith(
			expect.stringContaining( WARNING_FRAGMENT )
		);
	} );

	test( 'accepting the confirm lets deactivation proceed', () => {
		const link = renderPluginRow();
		globals.confirm.mockReturnValue( true );

		apsBindDeactivationWarning( document, globals );

		expect( click( link ) ).toBe( false );
	} );

	test( 'with no archived content there is no confirm at all', () => {
		const link = renderPluginRow();
		globals.archivedPostStatus.hasArchivedPosts = false;

		apsBindDeactivationWarning( document, globals );

		expect( click( link ) ).toBe( false );
		expect( globals.confirm ).not.toHaveBeenCalled();
	} );

	test( 'the warning is translated through wp.i18n with the plugin text domain', () => {
		const link = renderPluginRow();
		globals.confirm.mockReturnValue( false );

		apsBindDeactivationWarning( document, globals );
		click( link );

		expect( globals.wp.i18n.__ ).toHaveBeenCalledWith(
			expect.stringContaining( WARNING_FRAGMENT ),
			'archived-post-status'
		);
	} );
} );
