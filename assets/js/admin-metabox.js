/**
 * Agentic AI Feed — product metabox behaviours.
 *
 * Conversational Q&A editor: repeatable question/answer rows.
 * Rows are cloned from a <script type="text/html"> template; the __I__
 * placeholder is replaced with the next row index before insertion.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var rows = document.getElementById( 'acpfw-faq-rows' );
		var addBtn = document.getElementById( 'acpfw-add-faq' );
		var template = document.getElementById( 'acpfw-faq-template' );

		if ( ! rows || ! addBtn || ! template ) {
			return;
		}

		/**
		 * Insert one new Q&A row into the editor.
		 *
		 * @param {Event} event Click event.
		 */
		function onAdd( event ) {
			event.preventDefault();

			var index = parseInt( rows.getAttribute( 'data-index' ), 10 );
			if ( isNaN( index ) ) {
				index = -1;
			}
			index = index + 1;
			rows.setAttribute( 'data-index', String( index ) );

			var html = template.innerHTML.replace( /__I__/g, String( index ) );

			// insertAdjacentHTML parses the fragment correctly even when the
			// template starts with whitespace (a text node would make
			// appendChild( firstChild ) silently add nothing visible).
			rows.insertAdjacentHTML( 'beforeend', html );

			var added = rows.querySelectorAll( '.acpfw-faq-row' );
			var last = added.length ? added[ added.length - 1 ] : null;

			if ( last ) {
				var question = last.querySelector( 'input[type="text"]' );
				if ( question ) {
					question.focus();
				}
			}
		}

		/**
		 * Remove the clicked Q&A row.
		 *
		 * @param {Event} event Click event.
		 */
		function onRemove( event ) {
			var button = event.target.closest( '.acpfw-remove-faq' );

			if ( ! button || ! rows.contains( button ) ) {
				return;
			}

			event.preventDefault();

			var row = button.closest( '.acpfw-faq-row' );

			if ( row ) {
				row.remove();
			}
		}

		addBtn.addEventListener( 'click', onAdd );
		rows.addEventListener( 'click', onRemove );
	} );
} )();
