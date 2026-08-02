/**
 * Behavior tests for the shipped block-editor.js — the module under test is
 * imported, never re-implemented, so a regression in the real source fails
 * here. (The rendered button is additionally covered end-to-end by the
 * Playwright editor specs.)
 */
const {
	apsArchiveButton,
	apsRegisterArchiveButton,
} = require( './block-editor' );

describe( 'block-editor: archive button', () => {
	let globals;

	beforeEach( () => {
		globals = {
			wp: {
				element: {
					// Record the element tree as plain objects so structure
					// and props are directly assertable.
					createElement: jest.fn( ( type, props, ...children ) => ( {
						type,
						props,
						children,
					} ) ),
				},
				plugins: { registerPlugin: jest.fn() },
				editPost: { PluginPostStatusInfo: 'PluginPostStatusInfo' },
				i18n: { __: jest.fn( ( text ) => text ) },
			},
			archivedPostStatus: {
				canArchive: true,
				archiveUrl:
					'http://example.com/wp-admin/post.php?post=1&action=archive&_wpnonce=abc',
			},
			confirm: jest.fn( () => true ),
		};
	} );

	test( 'renders nothing when the user cannot archive', () => {
		globals.archivedPostStatus.canArchive = false;

		expect( apsArchiveButton( globals ) ).toBeNull();
		expect( globals.wp.element.createElement ).not.toHaveBeenCalled();
	} );

	test( 'renders the button inside the post-status panel with the archive URL', () => {
		const tree = apsArchiveButton( globals );

		expect( tree.type ).toBe( 'PluginPostStatusInfo' );

		const anchor = tree.children[ 0 ];
		expect( anchor.type ).toBe( 'a' );
		expect( anchor.props.href ).toBe(
			globals.archivedPostStatus.archiveUrl
		);
		expect( anchor.props.className ).toBe(
			'components-button editor-post-archive is-destructive is-primary'
		);
		expect( anchor.children[ 0 ] ).toBe( 'Archive' );
	} );

	test( 'dismissing the confirm blocks the navigation', () => {
		globals.confirm.mockReturnValue( false );
		const anchor = apsArchiveButton( globals ).children[ 0 ];
		const event = { preventDefault: jest.fn() };

		anchor.props.onClick( event );

		expect( globals.confirm ).toHaveBeenCalledWith(
			'Are you sure you want to archive this post?'
		);
		expect( event.preventDefault ).toHaveBeenCalled();
		expect( globals.wp.i18n.__ ).toHaveBeenCalledWith(
			'Are you sure you want to archive this post?',
			'archived-post-status'
		);
	} );

	test( 'accepting the confirm lets the navigation proceed', () => {
		globals.confirm.mockReturnValue( true );
		const anchor = apsArchiveButton( globals ).children[ 0 ];
		const event = { preventDefault: jest.fn() };

		anchor.props.onClick( event );

		expect( event.preventDefault ).not.toHaveBeenCalled();
	} );

	test( 'labels are translated with the plugin text domain', () => {
		apsArchiveButton( globals );

		expect( globals.wp.i18n.__ ).toHaveBeenCalledWith(
			'Archive',
			'archived-post-status'
		);
	} );

	test( 'registerPlugin wires a render that reflects live capability data', () => {
		apsRegisterArchiveButton( globals );

		expect( globals.wp.plugins.registerPlugin ).toHaveBeenCalledWith(
			'archive-button',
			expect.objectContaining( { render: expect.any( Function ) } )
		);

		const { render } =
			globals.wp.plugins.registerPlugin.mock.calls[ 0 ][ 1 ];

		expect( render().type ).toBe( 'PluginPostStatusInfo' );

		globals.archivedPostStatus.canArchive = false;
		expect( render() ).toBeNull();
	} );
} );
