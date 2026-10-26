/**
 * Content Powered > AI Crawler Controls page behaviour. Only loaded on that page.
 *
 * Everything here is a convenience on top of plain forms, which work without
 * JavaScript: "Block all" / "Allow all" per group, live group counts and an
 * unsaved-changes hint, the chart's tooltip, and a confirm before Clear log.
 *
 * ai_crawler_controls_admin.groupCount is passed in from PHP (see
 * AI_Crawler_Controls_Admin::enqueue_assets).
 */

/* global ai_crawler_controls_admin */
( function () {
	'use strict';

	var data = window.ai_crawler_controls_admin || {};

	function format( template, values ) {
		var i = 0;

		return String( template || '' ).replace( /%(\d)\$s|%s/g, function ( match, n ) {
			var value = n ? values[ parseInt( n, 10 ) - 1 ] : values[ i++ ];
			return value === undefined ? match : String( value );
		} );
	}

	function setupCrawlers() {
		var form = document.querySelector( '[data-aicc-crawlers]' );

		if ( ! form ) {
			return;
		}

		function refreshGroup( group ) {
			var boxes = group.querySelectorAll( 'input[type="checkbox"][role="switch"]' );
			var count = group.querySelector( '[data-aicc-group-count]' );
			var blocked = 0;

			boxes.forEach( function ( box ) {
				var row = box.closest( '.aicc-bot' );

				if ( box.checked ) {
					blocked++;
				}
				if ( row ) {
					row.classList.toggle( 'is-blocked', box.checked );
				}
			} );

			if ( count && data.groupCount ) {
				count.textContent = format( data.groupCount, [ blocked.toLocaleString(), boxes.length.toLocaleString() ] );
			}
		}

		form.querySelectorAll( '[data-aicc-group]' ).forEach( function ( group ) {
			var actions = group.querySelector( '[data-aicc-group-actions]' );

			if ( actions ) {
				actions.hidden = false;
				actions.addEventListener( 'click', function ( event ) {
					var button = event.target.closest( '[data-aicc-set]' );

					if ( ! button ) {
						return;
					}

					var on = '1' === button.getAttribute( 'data-aicc-set' );

					group.querySelectorAll( 'input[type="checkbox"][role="switch"]' ).forEach( function ( box ) {
						box.checked = on;
					} );
					refreshGroup( group );
					form.classList.add( 'is-dirty' );
				} );
			}

			group.addEventListener( 'change', function () {
				refreshGroup( group );
			} );
		} );

		form.addEventListener( 'input', function () {
			form.classList.add( 'is-dirty' );
		} );
		form.addEventListener( 'change', function () {
			form.classList.add( 'is-dirty' );
		} );
	}

	function setupChart() {
		var chart = document.querySelector( '[data-aicc-chart]' );
		var tooltip = chart ? chart.querySelector( '[data-aicc-tooltip]' ) : null;

		if ( ! chart || ! tooltip ) {
			return;
		}

		function show( col ) {
			var box = chart.getBoundingClientRect();
			var rect = col.getBoundingClientRect();
			var bar = col.querySelector( '.aicc-col-bar > .aicc-seg:last-child' );
			var top = bar ? bar.getBoundingClientRect().top : rect.bottom;
			var left = rect.left + rect.width / 2 - box.left;

			tooltip.textContent = col.getAttribute( 'data-tip' );
			tooltip.hidden = false;

			// Keep it inside the chart at both ends.
			var half = tooltip.offsetWidth / 2;
			left = Math.max( half, Math.min( box.width - half, left ) );

			tooltip.style.left = left + 'px';
			tooltip.style.top = Math.max( tooltip.offsetHeight, top - box.top - 8 ) + 'px';
		}

		function hide() {
			tooltip.hidden = true;
		}

		chart.querySelectorAll( '.aicc-col' ).forEach( function ( col ) {
			col.addEventListener( 'pointerenter', function () {
				show( col );
			} );
			col.addEventListener( 'focus', function () {
				show( col );
			} );
			col.addEventListener( 'pointerleave', hide );
			col.addEventListener( 'blur', hide );
		} );
	}

	function setupConfirm() {
		document.querySelectorAll( '[data-aicc-confirm]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				// eslint-disable-next-line no-alert
				if ( ! window.confirm( button.getAttribute( 'data-aicc-confirm' ) ) ) {
					event.preventDefault();
				}
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		setupCrawlers();
		setupChart();
		setupConfirm();
	} );
} )();
