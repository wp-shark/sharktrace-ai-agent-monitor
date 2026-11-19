/**
 * SharkTrace AI Agent Monitor — control room.
 *
 * Phase 1 uses polling. SSE replaces the poller in Phase 1.5 without changing
 * the rendering path: both feed events into `prepend()`.
 */
( function ( wp, config ) {
	'use strict';

	if ( ! wp || ! wp.apiFetch ) {
		return;
	}

	var apiFetch = wp.apiFetch;
	var i18n = config.i18n;

	var state = {
		live: true,
		latestId: 0,
		loading: false,
		generation: 0,
		selected: null,
		events: [],
		timer: null,
	};

	var el = {
		feed: document.getElementById( 'staiam-feed' ),
		empty: document.getElementById( 'staiam-empty' ),
		detail: document.getElementById( 'staiam-detail' ),
		stats: document.getElementById( 'staiam-stats' ),
		live: document.getElementById( 'staiam-live' ),
		liveLabel: document.getElementById( 'staiam-live-label' ),
		clear: document.getElementById( 'staiam-clear' ),
		search: document.getElementById( 'staiam-search' ),
		source: document.getElementById( 'staiam-source' ),
		risk: document.getElementById( 'staiam-risk' ),
		status: document.getElementById( 'staiam-status' ),
	};

	if ( ! el.feed ) {
		return;
	}

	/* ------------------------------------------------------------------ */
	/* Data                                                                */
	/* ------------------------------------------------------------------ */

	function filters() {
		return {
			search: el.search.value.trim(),
			source: el.source.value,
			risk_level: el.risk.value,
			status: el.status.value,
		};
	}

	function query( extra ) {
		var params = Object.assign( filters(), extra || {} );
		var parts = [];

		Object.keys( params ).forEach( function ( key ) {
			if ( params[ key ] !== '' && params[ key ] !== undefined && params[ key ] !== null ) {
				parts.push( encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] ) );
			}
		} );

		return parts.length ? '?' + parts.join( '&' ) : '';
	}

	function get( path ) {
		return apiFetch( { path: config.namespace + path } );
	}

	function loadEvents( reset ) {
		if ( reset ) {
			// A new generation makes any request still in flight stale.
			state.generation++;
			state.latestId = 0;
			state.events = [];
			el.feed.innerHTML = '';
		} else if ( state.loading ) {
			// A poll while the previous request is pending would fetch the same rows twice.
			return Promise.resolve();
		}

		var generation = state.generation;
		var extra = state.latestId ? { since_id: state.latestId } : { per_page: 50 };

		state.loading = true;

		function done() {
			if ( generation === state.generation ) {
				state.loading = false;
			}
		}

		return get( '/events' + query( extra ) )
			.then( function ( response ) {
				if ( generation !== state.generation ) {
					return;
				}

				var events = ( response && response.events ) || [];

				if ( events.length ) {
					var isNew = state.latestId > 0;
					var after = state.latestId;

					// API returns newest first; render oldest-of-batch first so order stays right.
					events.slice().reverse().forEach( function ( event ) {
						if ( event.id > after ) {
							prepend( event, isNew );
						}
					} );

					state.latestId = Math.max.apply(
						null,
						events.map( function ( event ) {
							return event.id;
						} ).concat( [ state.latestId ] )
					);
				}

				updateEmptyState();
			} )
			.catch( function () {
				if ( generation !== state.generation ) {
					return;
				}
				el.empty.textContent = i18n.loadError;
				el.empty.hidden = false;
			} )
			.then( done, done );
	}

	function loadStats() {
		return get( '/stats' )
			.then( function ( stats ) {
				el.stats.querySelectorAll( '[data-stat]' ).forEach( function ( node ) {
					var key = node.getAttribute( 'data-stat' );
					node.textContent = String( stats[ key ] !== undefined ? stats[ key ] : 0 );
				} );
			} )
			.catch( function () {} );
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                           */
	/* ------------------------------------------------------------------ */

	function tag( text, modifier ) {
		var span = document.createElement( 'span' );
		span.className = 'staiam-tag' + ( modifier ? ' staiam-tag--' + modifier : '' );
		span.textContent = text;
		return span;
	}

	function sourceLabel( event ) {
		if ( event.source === 'agent' ) {
			return event.agent_name || 'AI agent';
		}
		if ( event.source === 'human' ) {
			return event.user_login ? event.user_login : 'Human';
		}
		return i18n.unattributed;
	}

	function prepend( event, isNew ) {
		var item = document.createElement( 'li' );
		item.className = 'staiam-event' + ( isNew ? ' staiam-event--new' : '' );
		item.setAttribute( 'role', 'button' );
		item.setAttribute( 'tabindex', '0' );
		item.setAttribute( 'aria-selected', 'false' );
		item.dataset.id = event.id;

		var time = document.createElement( 'span' );
		time.className = 'staiam-event__time';
		time.textContent = event.time_local || '';

		var body = document.createElement( 'div' );

		var label = document.createElement( 'p' );
		label.className = 'staiam-event__label';
		label.textContent = event.label;

		var meta = document.createElement( 'div' );
		meta.className = 'staiam-event__meta';
		meta.appendChild( tag( sourceLabel( event ), event.source ) );
		meta.appendChild( tag( event.action ) );

		if ( event.risk_level !== 'low' ) {
			meta.appendChild( tag( event.risk_level, 'risk-' + event.risk_level ) );
		}

		if ( event.status !== 'success' ) {
			meta.appendChild( tag( event.status, 'status-' + event.status ) );
		}

		if ( event.transport ) {
			meta.appendChild( tag( event.transport ) );
		}

		body.appendChild( label );
		body.appendChild( meta );
		item.appendChild( time );
		item.appendChild( body );

		el.feed.insertBefore( item, el.feed.firstChild );
		state.events.unshift( event );

		// Keep the DOM bounded on busy sites.
		while ( el.feed.children.length > 200 ) {
			el.feed.removeChild( el.feed.lastChild );
			state.events.pop();
		}
	}

	function updateEmptyState() {
		var isEmpty = el.feed.children.length === 0;
		el.empty.hidden = ! isEmpty;

		if ( isEmpty ) {
			el.empty.textContent = i18n.empty;
		}
	}

	function row( field, before, after ) {
		var tr = document.createElement( 'tr' );

		[
			[ field, '' ],
			[ before, 'staiam-diff__before' ],
			[ after, 'staiam-diff__after' ],
		].forEach( function ( cell ) {
			var td = document.createElement( 'td' );
			td.className = cell[ 1 ];
			td.textContent = cell[ 0 ];
			tr.appendChild( td );
		} );

		return tr;
	}

	function stringify( value ) {
		if ( value === null || value === undefined ) {
			return '—';
		}
		if ( typeof value === 'object' ) {
			return JSON.stringify( value );
		}
		return String( value );
	}

	function renderDetail( event ) {
		el.detail.innerHTML = '';

		var title = document.createElement( 'h3' );
		title.textContent = event.label;
		el.detail.appendChild( title );

		var facts = [
			[ 'Source', sourceLabel( event ) ],
			[ 'Transport', event.transport || '—' ],
			[ 'Ability', event.ability || '—' ],
			[ 'Action', event.action ],
			[ 'Target', event.object_type ? event.object_type + ' ' + event.object_id : '—' ],
			[ 'Status', event.status ],
			[ 'Risk', event.risk_level ],
			[ 'Duration', event.duration_ms ? event.duration_ms + ' ms' : '—' ],
			[ 'User', event.user_login || '—' ],
			[ 'Time', event.date_local ],
			[ 'Session', event.session_id ? event.session_id.slice( 0, 8 ) : '—' ],
		];

		var dl = document.createElement( 'dl' );

		facts.forEach( function ( fact ) {
			var dt = document.createElement( 'dt' );
			dt.textContent = fact[ 0 ];
			var dd = document.createElement( 'dd' );
			dd.textContent = fact[ 1 ];
			dl.appendChild( dt );
			dl.appendChild( dd );
		} );

		el.detail.appendChild( dl );

		if ( event.error ) {
			var error = document.createElement( 'div' );
			error.className = 'staiam-error-box';
			error.textContent = event.error;
			el.detail.appendChild( error );
		}

		var before = event.before_data || {};
		var after = event.after_data || {};
		var keys = Object.keys( before ).concat( Object.keys( after ) ).filter( function ( key, index, all ) {
			return all.indexOf( key ) === index;
		} );

		if ( keys.length ) {
			var heading = document.createElement( 'p' );
			heading.className = 'staiam-detail__section-title';
			heading.textContent = 'Before / after';
			el.detail.appendChild( heading );

			var table = document.createElement( 'table' );
			table.className = 'staiam-diff';

			var head = document.createElement( 'tr' );
			[ 'Field', 'Before', 'After' ].forEach( function ( text ) {
				var th = document.createElement( 'th' );
				th.textContent = text;
				head.appendChild( th );
			} );
			table.appendChild( head );

			keys.forEach( function ( key ) {
				table.appendChild( row( key, stringify( before[ key ] ), stringify( after[ key ] ) ) );
			} );

			el.detail.appendChild( table );
		}
	}

	function select( id ) {
		var event = state.events.filter( function ( candidate ) {
			return String( candidate.id ) === String( id );
		} )[ 0 ];

		if ( ! event ) {
			return;
		}

		el.feed.querySelectorAll( '.staiam-event' ).forEach( function ( node ) {
			node.setAttribute( 'aria-selected', node.dataset.id === String( id ) ? 'true' : 'false' );
		} );

		state.selected = event.id;
		renderDetail( event );
	}

	/* ------------------------------------------------------------------ */
	/* Polling                                                             */
	/* ------------------------------------------------------------------ */

	function tick() {
		if ( ! state.live || document.hidden ) {
			return;
		}

		loadEvents( false );
		loadStats();
	}

	function startPolling() {
		if ( state.timer ) {
			return;
		}
		state.timer = window.setInterval( tick, config.pollMs || 5000 );
	}

	function setLive( live ) {
		state.live = live;
		el.live.setAttribute( 'aria-pressed', live ? 'true' : 'false' );
		el.liveLabel.textContent = live ? i18n.live : i18n.paused;

		if ( live ) {
			tick();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Events                                                              */
	/* ------------------------------------------------------------------ */

	el.feed.addEventListener( 'click', function ( event ) {
		var item = event.target.closest( '.staiam-event' );
		if ( item ) {
			select( item.dataset.id );
		}
	} );

	el.feed.addEventListener( 'keydown', function ( event ) {
		if ( event.key !== 'Enter' && event.key !== ' ' ) {
			return;
		}
		var item = event.target.closest( '.staiam-event' );
		if ( item ) {
			event.preventDefault();
			select( item.dataset.id );
		}
	} );

	el.live.addEventListener( 'click', function () {
		setLive( ! state.live );
	} );

	el.clear.addEventListener( 'click', function () {
		if ( ! window.confirm( i18n.confirmWipe ) ) {
			return;
		}

		apiFetch( { path: config.namespace + '/events', method: 'DELETE' } ).then( function () {
			loadEvents( true );
			loadStats();
		} );
	} );

	var debounce;
	[ el.search, el.source, el.risk, el.status ].forEach( function ( control ) {
		control.addEventListener( 'input', function () {
			window.clearTimeout( debounce );
			debounce = window.setTimeout( function () {
				loadEvents( true ).then( loadStats );
			}, 250 );
		} );
	} );

	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden ) {
			tick();
		}
	} );

	/* Boot. */
	loadEvents( true ).then( loadStats );
	setLive( true );
	startPolling();
}( window.wp, window.STAIAM || {} ) );
