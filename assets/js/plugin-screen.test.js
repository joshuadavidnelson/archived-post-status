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

	/**
	 * Render a plugins.php-like bulk-action form: the shared
	 * `#bulk-action-form`, the top and bottom bulk-action `<select>`s
	 * WordPress core renders, and one row per requested slug with its
	 * checkbox — mirroring `WP_Plugins_List_Table`'s real markup
	 * (`checked[]` checkboxes, `data-slug` rows, `action`/`action2` selects).
	 *
	 * @param {Object}   [options]
	 * @param {string[]} [options.slugs]   Plugin row slugs to render.
	 * @param {?string}  [options.checked] Slug whose checkbox starts checked;
	 *                                     null checks none.
	 * @return {HTMLFormElement} The bulk-action form.
	 */
	function renderBulkActionsForm(
		{ slugs = [ 'archived-post-status' ], checked = 'archived-post-status' } = {}
	) {
		const rows = slugs
			.map(
				( slug ) => `
					<tr data-slug="${ slug }">
						<th class="check-column">
							<input type="checkbox" name="checked[]" value="${ slug }/${ slug }.php" ${
					slug === checked ? 'checked' : ''
				} />
						</th>
						<td class="deactivate"><a href="/wp-admin/plugins.php?action=deactivate&plugin=${ slug }">Deactivate</a></td>
					</tr>`
			)
			.join( '' );

		document.body.innerHTML = `
			<form method="post" id="bulk-action-form">
				<select name="action" id="bulk-action-selector-top">
					<option value="-1">Bulk actions</option>
					<option value="deactivate-selected">Deactivate</option>
					<option value="activate-selected">Activate</option>
				</select>
				<select name="action2" id="bulk-action-selector-bottom">
					<option value="-1">Bulk actions</option>
					<option value="deactivate-selected">Deactivate</option>
					<option value="activate-selected">Activate</option>
				</select>
				<table><tbody>${ rows }</tbody></table>
			</form>
		`;

		return document.getElementById( 'bulk-action-form' );
	}

	/**
	 * Select a bulk action in the given dropdown and submit the form,
	 * reporting whether the submit was prevented.
	 *
	 * @param {HTMLFormElement} form       The bulk-action form.
	 * @param {string}          action     Bulk action value to select.
	 * @param {string}          selectName 'action' (top) or 'action2' (bottom).
	 * @return {boolean} True when preventDefault() was called.
	 */
	function submitBulkAction( form, action, selectName = 'action' ) {
		form.elements[ selectName ].value = action;

		const event = new window.Event( 'submit', {
			bubbles: true,
			cancelable: true,
		} );
		form.dispatchEvent( event );

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

	describe( 'Bulk Actions -> Deactivate', () => {
		test( 'checking this plugin and choosing Deactivate triggers the same confirm', () => {
			const form = renderBulkActionsForm();
			globals.confirm.mockReturnValue( false );

			apsBindDeactivationWarning( document, globals );

			expect( submitBulkAction( form, 'deactivate-selected' ) ).toBe( true );
			expect( globals.confirm ).toHaveBeenCalledWith(
				expect.stringContaining( WARNING_FRAGMENT )
			);
		} );

		test( 'accepting the bulk confirm lets the submit proceed', () => {
			const form = renderBulkActionsForm();
			globals.confirm.mockReturnValue( true );

			apsBindDeactivationWarning( document, globals );

			expect( submitBulkAction( form, 'deactivate-selected' ) ).toBe(
				false
			);
		} );

		test( "not checking this plugin's row does not confirm", () => {
			const form = renderBulkActionsForm( {
				slugs: [ 'archived-post-status', 'some-other-plugin' ],
				checked: 'some-other-plugin',
			} );

			apsBindDeactivationWarning( document, globals );

			expect( submitBulkAction( form, 'deactivate-selected' ) ).toBe(
				false
			);
			expect( globals.confirm ).not.toHaveBeenCalled();
		} );

		test( 'choosing a different bulk action on this (checked) plugin does not confirm', () => {
			const form = renderBulkActionsForm();

			apsBindDeactivationWarning( document, globals );

			expect( submitBulkAction( form, 'activate-selected' ) ).toBe(
				false
			);
			expect( globals.confirm ).not.toHaveBeenCalled();
		} );

		test( 'choosing Deactivate from the bottom Apply button (action2) is also intercepted', () => {
			const form = renderBulkActionsForm();
			globals.confirm.mockReturnValue( false );

			apsBindDeactivationWarning( document, globals );

			expect(
				submitBulkAction( form, 'deactivate-selected', 'action2' )
			).toBe( true );
			expect( globals.confirm ).toHaveBeenCalledTimes( 1 );
		} );

		test( 'with no archived content, bulk Deactivate needs no confirmation', () => {
			const form = renderBulkActionsForm();
			globals.archivedPostStatus.hasArchivedPosts = false;

			apsBindDeactivationWarning( document, globals );

			expect( submitBulkAction( form, 'deactivate-selected' ) ).toBe(
				false
			);
			expect( globals.confirm ).not.toHaveBeenCalled();
		} );
	} );

	describe( 'network admin', () => {
		beforeEach( () => {
			globals.archivedPostStatus.isNetworkAdmin = true;
			// The network branch never runs the single-site query — a
			// falsy hasArchivedPosts here proves the warning fires
			// independent of it, not because of it.
			globals.archivedPostStatus.hasArchivedPosts = false;
		} );

		test( 'the Deactivate link warns even though no archived content was checked locally', () => {
			const link = renderPluginRow();
			globals.confirm.mockReturnValue( false );

			apsBindDeactivationWarning( document, globals );

			expect( click( link ) ).toBe( true );
			expect( globals.confirm ).toHaveBeenCalledWith(
				expect.stringContaining( 'Any site on this network' )
			);
		} );

		test( 'accepting the network warning lets deactivation proceed', () => {
			const link = renderPluginRow();
			globals.confirm.mockReturnValue( true );

			apsBindDeactivationWarning( document, globals );

			expect( click( link ) ).toBe( false );
		} );

		test( 'bulk Deactivate of this plugin also gets the network-wide message', () => {
			const form = renderBulkActionsForm();
			globals.confirm.mockReturnValue( false );

			apsBindDeactivationWarning( document, globals );

			expect( submitBulkAction( form, 'deactivate-selected' ) ).toBe(
				true
			);
			expect( globals.confirm ).toHaveBeenCalledWith(
				expect.stringContaining( 'Any site on this network' )
			);
		} );

		test( 'the network message is translated through wp.i18n with the plugin text domain', () => {
			const link = renderPluginRow();
			globals.confirm.mockReturnValue( false );

			apsBindDeactivationWarning( document, globals );
			click( link );

			expect( globals.wp.i18n.__ ).toHaveBeenCalledWith(
				expect.stringContaining( 'Any site on this network' ),
				'archived-post-status'
			);
		} );
	} );
} );
