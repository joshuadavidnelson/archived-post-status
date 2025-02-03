jQuery( document ).ready( function( $ ) {
    $rows = $( '#the-list tr.status-archive' );

    $.each( $rows, function() {
        disallowEditing( $( this ) );
    } );
    $( '.inline-edit-row' ).on( 'remove', function() {
        var id   = $( this ).prop( 'id' ).replace( 'edit-', '' ),
            $row = $( '#post-' + id );

        if ( $row.hasClass( 'status-archive' ) ) {
            disallowEditing( $row );
        }
    } );

    function disallowEditing( $row ) {
        var title = $row.find( '.column-title a.row-title' ).text();

        $row.find( '.column-title a.row-title' ).replaceWith( title );
        $row.find( '.row-actions .edit' ).remove();
    }
} );
