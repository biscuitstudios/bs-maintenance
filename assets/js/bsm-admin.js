/**
 * Maintenance — admin behavior.
 *
 * Per docs/PLUGIN_PRIMER.md §9: no server-controlled string is ever passed to
 * .html(). Every dynamic value below goes through .text(), .val(), or .attr().
 */
( function ( $ ) {
    'use strict';

    /**
     * Show/hide blocks that depend on another control.
     *
     * A checkbox source means "shown while checked". Any other control pairs
     * with data-depends-value and is shown only on an exact match, which is how
     * the mode and source selects swap their panels.
     *
     * Read with .attr(), not .data() — jQuery coerces data attributes, so a
     * value like "0" would come back as a number and never match a string.
     */
    function syncDependents() {
        $( '.bsm-dependent' ).each( function () {
            var $block  = $( this );
            var $source = $( '#' + $block.attr( 'data-depends-on' ) );
            if ( ! $source.length ) return;

            var want = $block.attr( 'data-depends-value' );
            var show;

            if ( $source.is( ':checkbox' ) ) {
                show = $source.is( ':checked' );
            } else if ( undefined === want ) {
                show = '' !== String( $source.val() || '' );
            } else {
                show = String( $source.val() ) === String( want );
            }

            $block.prop( 'hidden', ! show );
        } );
    }

    /** 12 chars from the CSPRNG — matches the entropy floor in the primer. */
    function generateSecret() {
        var alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        var bytes    = new Uint8Array( 12 );
        window.crypto.getRandomValues( bytes );

        var out = '';
        for ( var i = 0; i < bytes.length; i++ ) {
            out += alphabet.charAt( bytes[ i ] % alphabet.length );
        }
        return out;
    }

    function secretUrl( key ) {
        return key ? bsmAdmin.homeUrl + '?' + encodeURIComponent( key ) : '';
    }

    /**
     * Copy by selecting the visible read-only field.
     *
     * The Clipboard API is unavailable on non-secure origins (a plain http
     * Local site) and can reject when the document isn't focused, so this
     * always has to work as a fallback. Selecting a real, visible, focusable
     * field is far more reliable than the usual off-screen textarea.
     */
    function legacyCopy( $field ) {
        if ( ! $field.length ) return false;

        var el = $field[ 0 ];
        var wasReadOnly = el.readOnly;
        el.readOnly = false;             // iOS refuses to select read-only inputs
        el.focus();
        el.select();
        if ( el.setSelectionRange ) el.setSelectionRange( 0, el.value.length );

        var ok = false;
        try { ok = document.execCommand( 'copy' ); } catch ( e ) { ok = false; }

        el.readOnly = wasReadOnly;
        return ok;
    }

    function copyText( text, $field ) {
        if ( navigator.clipboard && window.isSecureContext ) {
            return navigator.clipboard.writeText( text ).then(
                function () { return true; },
                function () { return legacyCopy( $field ); }   // rejected: fall back
            );
        }
        return Promise.resolve( legacyCopy( $field ) );
    }

    $( function () {
        var $form = $( '.bsm-wrap form' );

        syncDependents();
        $( document ).on( 'change', '.bsm-wrap input[type="checkbox"], .bsm-wrap select', syncDependents );

        // ------------------------------------------------------ secret link

        var $key     = $( '#bsm-secret-key' );
        var $preview = $( '#bsm-secret-url' );
        var $copy    = $( '.bsm-copy' );

        function refreshSecret() {
            var url = secretUrl( $.trim( $key.val() ) );
            $copy.attr( 'data-url', url ).prop( 'disabled', ! url );
            $preview.val( url ).prop( 'hidden', ! url );
        }

        $key.on( 'input', refreshSecret );

        $( '.bsm-generate' ).on( 'click', function () {
            $key.val( generateSecret() ).trigger( 'input' ).trigger( 'focus' );
        } );

        $copy.on( 'click', function () {
            var $btn = $( this );
            var url  = $btn.attr( 'data-url' );
            if ( ! url ) return;

            copyText( url, $preview ).then( function ( ok ) {
                $btn.text( ok ? bsmAdmin.copied : bsmAdmin.copyFailed );
                window.setTimeout( function () {
                    $btn.text( bsmAdmin.copyLink );
                }, ok ? 1500 : 4000 );
            } );
        } );

        // -------------------------------------------- unsaved-changes guard

        var baseline   = null;
        var submitting = false;

        /**
         * Serializing the whole form covers every control at once, including
         * ones added later. tinyMCE.triggerSave() first, or the rich-text
         * editor's unflushed content never reaches the textarea it shadows.
         */
        function formState() {
            if ( window.tinyMCE && window.tinyMCE.triggerSave ) {
                try { window.tinyMCE.triggerSave(); } catch ( e ) { /* editor not up yet */ }
            }
            return $form.serialize();
        }

        // Captured after load so TinyMCE has initialized and its textarea is
        // populated — otherwise the page looks dirty the moment it settles.
        function captureBaseline() { baseline = formState(); }

        if ( 'complete' === document.readyState ) {
            window.setTimeout( captureBaseline, 300 );
        } else {
            $( window ).on( 'load', function () { window.setTimeout( captureBaseline, 300 ); } );
        }

        $form.on( 'submit', function () { submitting = true; } );

        $( window ).on( 'beforeunload', function ( e ) {
            if ( submitting || null === baseline ) return;
            if ( formState() === baseline ) return;

            // Browsers show their own wording and ignore custom text; both the
            // preventDefault and the returnValue assignment are still needed
            // for the prompt to appear across engines.
            e.preventDefault();
            e.returnValue = '';
            return '';
        } );
    } );

}( jQuery ) );
