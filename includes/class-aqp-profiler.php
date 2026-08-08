<?php
/**
 * The profiler engine: attributes each database query on an admin request back
 * to the plugin that caused it.
 *
 * @package AdminQueryProfilerForWooCommerce
 *
 * Query Monitor shows a developer a raw list of queries in a browser panel.
 * What a store owner needs is a verdict: "plugin X fired 412 queries to render
 * 20 rows, and that is why this screen takes 40 seconds." Producing that means
 * attributing each query to a plugin, which is the part nobody does for
 * store owners.
 *
 * Method: with SAVEQUERIES on, WordPress records every query in $wpdb->queries
 * along with a backtrace summary of function names. Reflecting each function
 * back to its defining file yields the owning plugin directory. The reflection
 * step is what makes attribution possible - the backtrace alone only gives
 * function names, not files.
 *
 * Profiling is OPT-IN per request. Capturing a backtrace for every query is
 * expensive, and SAVEQUERIES roughly doubles the memory cost of a page load, so
 * neither may be left on for ordinary visitors.
 *
 * Two acquisition modes:
 *
 *   SAVEQUERIES on  - read $wpdb->queries, which carries per-query timing.
 *   SAVEQUERIES off - hook the core `query` filter, which fires for every query
 *                     and yields count + backtrace but NO timing (there is no
 *                     matching post-execution hook in core).
 *
 * The second mode is what makes this shippable. SAVEQUERIES can only be set in
 * wp-config.php before WordPress boots - wpdb tests the constant at query time,
 * so a plugin cannot switch it on. Query Monitor works around that by force
 * defining it inside a db.php dropin, but WordPress permits exactly one db.php,
 * so doing the same would make this plugin incompatible with Query Monitor.
 * N+1 detection only needs counts and attribution, so timing is a bonus.
 */

defined( 'ABSPATH' ) || exit;

class AQP_Profiler {

	/**
	 * Optional directory for plain-text report dumps.
	 *
	 * Off unless AQP_DEBUG_DIR is defined. A shipped plugin has no business
	 * writing files anywhere by default; this exists so the test harness can
	 * read machine-parsable output.
	 */
	private static function out_dir() {
		return ( defined( 'AQP_DEBUG_DIR' ) && AQP_DEBUG_DIR ) ? AQP_DEBUG_DIR : '';
	}

	/**
	 * Hard cap on captured queries. A runaway page can fire tens of thousands,
	 * and each captured backtrace costs memory - the profiler must never be the
	 * reason a site runs out of it.
	 */
	const MAX_QUERIES = 8000;

	/** Queries captured via the `query` filter when SAVEQUERIES is unavailable. */
	private static $captured = array();

	/** True once we have decided this request is being profiled. */
	private static $armed = false;

	/** Cache of token => file, since the same functions recur constantly. */
	private static $file_cache = array();

	/**
	 * Components that must never be blamed for a query.
	 *
	 * Query Monitor installs a db.php dropin that wraps $wpdb, so its frames
	 * sit innermost in EVERY backtrace. Without this list it is credited with
	 * 100% of queries and the report is worthless. The probe itself is excluded
	 * for the same reason.
	 */
	private static $ignore = array( 'query-monitor', 'admin-query-profiler-for-woocommerce' );

	/**
	 * Should this request be profiled?
	 *
	 * Deliberately restrictive. Profiling is expensive, exposes raw SQL, and
	 * must never run for an ordinary visitor or an unauthenticated request.
	 *
	 *   AQP_ALWAYS - lab escape hatch, so the harness scripts can
	 *                           profile without juggling nonces.
	 *   ?aqp_profile=<nonce>  - how a real user triggers a scan, from a button.
	 */
	private static function should_profile() {
		if ( defined( 'AQP_ALWAYS' ) && AQP_ALWAYS ) {
			return true;
		}

		if ( empty( $_GET['aqp_profile'] ) ) {
			return false;
		}

		// Raw SQL and file paths are sensitive; require an admin-level cap.
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		return (bool) wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_GET['aqp_profile'] ) ),
			'aqp_profile'
		);
	}

	/** True when wpdb is recording queries itself, so timings are available. */
	private static function have_savequeries() {
		return defined( 'SAVEQUERIES' ) && SAVEQUERIES;
	}

	public static function init() {
		// plugins_loaded is the earliest point at which capability checks and
		// nonce verification are reliable, and it still precedes essentially
		// every query that matters on an admin screen.
		add_action( 'plugins_loaded', array( __CLASS__, 'arm' ), 0 );

		// The button is always available - profiling is opt-in, so there has to
		// be something to opt in with.
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_button' ), 100 );
	}

	public static function arm() {
		if ( ! self::should_profile() ) {
			return;
		}
		self::$armed = true;

		// Without SAVEQUERIES, wpdb keeps no log of its own, so capture through
		// the `query` filter instead. It fires before execution, which is why
		// this path has no per-query timing.
		if ( ! self::have_savequeries() ) {
			add_filter( 'query', array( __CLASS__, 'capture' ) );
		}

		// Late priority so queries from other shutdown handlers are counted too.
		add_action( 'shutdown', array( __CLASS__, 'report' ), PHP_INT_MAX );

		// The panel has to render while the document is still open; shutdown is
		// too late. Counts here are a hair lower than the file report for that
		// reason - a handful of queries run after the footer.
		add_action( 'admin_footer', array( __CLASS__, 'render_panel' ), PHP_INT_MAX );
	}

	/**
	 * Record a query and who asked for it. Must return $sql untouched - this is
	 * a filter on the statement about to run, not an observer.
	 */
	public static function capture( $sql ) {
		if ( count( self::$captured ) >= self::MAX_QUERIES ) {
			return $sql;
		}
		self::$captured[] = array(
			'sql'   => $sql,
			'ms'    => null, // no post-execution hook exists in core
			'trace' => wp_debug_backtrace_summary(),
		);
		return $sql;
	}

	/**
	 * Normalise the two acquisition modes into one shape:
	 * array( array( sql, ms|null, trace ), ... )
	 */
	private static function collect() {
		global $wpdb;

		if ( self::have_savequeries() && ! empty( $wpdb->queries ) ) {
			$rows = array();
			foreach ( array_slice( $wpdb->queries, 0, self::MAX_QUERIES ) as $q ) {
				$rows[] = array(
					'sql'   => isset( $q[0] ) ? $q[0] : '',
					'ms'    => isset( $q[1] ) ? ( (float) $q[1] ) * 1000 : null,
					'trace' => isset( $q[2] ) ? $q[2] : '',
				);
			}
			return $rows;
		}

		return self::$captured;
	}

	/**
	 * Resolve a backtrace token ("Foo->bar", "Foo::bar", "some_function") to the
	 * file that defines it.
	 */
	private static function token_file( $token ) {
		$token = trim( preg_replace( '/\(.*$/', '', $token ) );
		if ( '' === $token ) {
			return null;
		}
		if ( isset( self::$file_cache[ $token ] ) ) {
			return self::$file_cache[ $token ];
		}

		$file = null;
		try {
			if ( false !== strpos( $token, '->' ) || false !== strpos( $token, '::' ) ) {
				$sep = ( false !== strpos( $token, '->' ) ) ? '->' : '::';
				list( $class, $method ) = explode( $sep, $token, 2 );
				if ( class_exists( $class ) && method_exists( $class, $method ) ) {
					$r    = new ReflectionMethod( $class, $method );
					$file = $r->getFileName();
				}
			} elseif ( function_exists( $token ) ) {
				$r    = new ReflectionFunction( $token );
				$file = $r->getFileName();
			}
		} catch ( Throwable $e ) {
			$file = null;
		}

		self::$file_cache[ $token ] = $file;
		return $file;
	}

	/**
	 * Core functions that hand control from WordPress into plugin code.
	 * A frame sitting directly inside one of these IS a plugin's callback.
	 */
	private static $dispatchers = array(
		'WP_Hook->apply_filters',
		'WP_Hook->do_action',
		'do_action',
		'do_action_ref_array',
		'apply_filters',
		'apply_filters_ref_array',
		'call_user_func',
		'call_user_func_array',
	);

	/**
	 * Which component owns a backtrace frame: a plugin slug, 'theme:x',
	 * 'mu:x', 'core', or null when the frame cannot be resolved to a file.
	 */
	private static function component_of( $token ) {
		$file = self::token_file( $token );
		if ( ! $file ) {
			return null;
		}
		$file = str_replace( '\\', '/', $file );

		if ( preg_match( '#/wp-content/plugins/([^/]+)/#', $file, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '#/wp-content/mu-plugins/([^/]+)#', $file, $m ) ) {
			return 'mu:' . preg_replace( '/\.php$/', '', $m[1] );
		}
		if ( preg_match( '#/wp-content/themes/([^/]+)/#', $file, $m ) ) {
			return 'theme:' . $m[1];
		}
		return 'core';
	}

	/**
	 * Find the component that CAUSED a query, not merely the one that ran it.
	 *
	 * The naive approach - walk inward-out, blame the first plugin frame - is
	 * wrong whenever a query is routed through a shared data store, which on
	 * this screen is most of them. A third-party plugin calling wc_get_order()
	 * inside a column callback produces a trace whose innermost plugin frames
	 * all belong to WooCommerce, so WooCommerce takes the blame and the plugin
	 * that actually caused the work is exonerated. Measured against a
	 * deactivate-and-remeasure control, that method over-attributed 3.0
	 * queries/row to WooCommerce when its true cost was 1.0.
	 *
	 * What we actually want is the last point at which control passed from
	 * WordPress into plugin code: the INNERMOST frame that a hook dispatcher
	 * invoked. In the example above that is the plugin's column callback -
	 * correct - while for a query WooCommerce genuinely raises on its own the
	 * nearest such boundary is a WooCommerce callback, also correct.
	 *
	 * Falls back to the innermost identifiable plugin frame when no hook
	 * boundary appears in the trace (direct calls, cron, CLI).
	 */
	private static function blame( $backtrace ) {
		$frames = array_map( 'trim', explode( ',', (string) $backtrace ) );
		// wp_debug_backtrace_summary() returns outermost-first; we want to
		// search from the query outwards.
		$frames = array_reverse( $frames );

		$fallback = null;

		foreach ( $frames as $i => $frame ) {
			$component = self::component_of( $frame );
			if ( null === $component ) {
				continue;
			}
			if ( in_array( $component, self::$ignore, true )
				|| in_array( str_replace( 'mu:', '', $component ), self::$ignore, true ) ) {
				continue;
			}

			// Remember the innermost real plugin in case no hook boundary exists.
			if ( null === $fallback && 'core' !== $component ) {
				$fallback = $component;
			}

			// Is the frame immediately outside this one a hook dispatcher? If
			// so, this frame is a callback - the initiator we are looking for.
			$outer = isset( $frames[ $i + 1 ] )
				? trim( preg_replace( '/\(.*$/', '', $frames[ $i + 1 ] ) )
				: '';

			if ( in_array( $outer, self::$dispatchers, true ) && 'core' !== $component ) {
				return $component;
			}
			// A core callback at a boundary tells us nothing - keep going out.
		}

		return $fallback ? $fallback : 'core';
	}

	/**
	 * Collapse a query to its shape so repeats can be counted.
	 *
	 * "SELECT * FROM x WHERE id = 412" and "... id = 9987" are the same query
	 * fired twice. Without normalising, a 300x loop looks like 300 unique
	 * queries and the repetition - the actual finding - is invisible.
	 */
	private static function normalize( $sql ) {
		$s = preg_replace( '/\s+/', ' ', trim( (string) $sql ) );
		$s = preg_replace( "/'[^']*'/", "'?'", $s );
		$s = preg_replace( '/\bIN\s*\([^)]*\)/i', 'IN (...)', $s );
		$s = preg_replace( '/\b\d+\b/', 'N', $s );
		return $s;
	}

	/**
	 * Crunch the captured queries into the analysis that both renderers use.
	 * Returns null when there is nothing worth reporting.
	 */
	private static function analyze() {
		if ( ! self::$armed ) {
			return null;
		}

		// Only profile admin screens - that is where the complaint lives.
		if ( ! is_admin() ) {
			return null;
		}

		$queries = self::collect();
		if ( empty( $queries ) ) {
			return null;
		}

		$timed  = self::have_savequeries();
		$capped = count( $queries ) >= self::MAX_QUERIES;

		$screen = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : basename( $_SERVER['SCRIPT_NAME'] );

		$by_plugin = array();
		$patterns  = array();
		$slowest   = array();
		$total_ms  = 0.0;

		foreach ( $queries as $q ) {
			$sql   = $q['sql'];
			$trace = $q['trace'];
			// null means this mode has no timing - count it as zero rather than
			// inventing a number, and suppress the timing columns below.
			$ms    = ( null === $q['ms'] ) ? 0.0 : (float) $q['ms'];

			$who = self::blame( $trace );

			if ( ! isset( $by_plugin[ $who ] ) ) {
				$by_plugin[ $who ] = array( 'count' => 0, 'ms' => 0.0, 'sample' => '' );
			}
			$by_plugin[ $who ]['count']++;
			$by_plugin[ $who ]['ms'] += $ms;
			$total_ms += $ms;

			// Keep an illustrative example: the slowest when timed, otherwise
			// simply the first seen.
			if ( '' === $by_plugin[ $who ]['sample'] && ( ! $timed || $ms > 1.0 ) ) {
				$by_plugin[ $who ]['sample'] = trim( preg_replace( '/\s+/', ' ', substr( $sql, 0, 220 ) ) );
			}

			$shape = self::normalize( $sql );
			$key   = $who . '|' . $shape;
			if ( ! isset( $patterns[ $key ] ) ) {
				$patterns[ $key ] = array( 'who' => $who, 'shape' => $shape, 'count' => 0, 'ms' => 0.0 );
			}
			$patterns[ $key ]['count']++;
			$patterns[ $key ]['ms'] += $ms;

			$slowest[] = array(
				'ms'  => $ms,
				'who' => $who,
				'sql' => trim( preg_replace( '/\s+/', ' ', $sql ) ),
			);
		}

		uasort( $by_plugin, function ( $a, $b ) { return $b['count'] <=> $a['count']; } );

		// How many rows were on screen - the denominator for every "per row"
		// figure, and therefore the number most capable of producing a confident
		// wrong answer. Each source below is tried in order of how much it can
		// be trusted, and `$rows_exact` records whether we counted or inferred.
		$rows       = 0;
		$rows_exact = false;

		// 1. What actually rendered. Ground truth when the list table exposes it.
		if ( isset( $GLOBALS['wp_list_table'] )
			&& is_object( $GLOBALS['wp_list_table'] )
			&& isset( $GLOBALS['wp_list_table']->items )
			&& is_array( $GLOBALS['wp_list_table']->items ) ) {
			$rows       = count( $GLOBALS['wp_list_table']->items );
			$rows_exact = $rows > 0;
		}

		// 2. THIS screen's own per-page option. Every list screen registers its
		//    own ('edit_product_per_page', 'users_per_page', ...), so it must be
		//    read from the current screen rather than assumed. Hardcoding the
		//    orders one made every other screen report the orders page size.
		if ( ! $rows && function_exists( 'get_current_screen' ) ) {
			$screen_obj = get_current_screen();
			if ( $screen_obj ) {
				$option = $screen_obj->get_option( 'per_page', 'option' );
				if ( $option ) {
					$rows = (int) get_user_option( $option );
				}
				if ( ! $rows ) {
					$rows = (int) $screen_obj->get_option( 'per_page', 'default' );
				}
			}
		}

		// 3. WooCommerce's HPOS orders screen reads 'edit_shop_order_per_page'
		//    and ignores any ?per_page= parameter, so check it explicitly - but
		//    only on that screen.
		if ( ! $rows && isset( $_GET['page'] ) && 'wc-orders' === $_GET['page'] ) {
			$rows = (int) get_user_option( 'edit_shop_order_per_page' );
		}

		if ( ! $rows ) {
			$rows = 20; // WordPress default page size.
		}

		// Count the normalised set, not $wpdb->queries - the latter is empty in
		// the `query` filter acquisition mode, and counting null is fatal on PHP 8.
		$total_q = count( $queries );
		$peak_mb = round( memory_get_peak_usage( true ) / 1048576, 1 );
		$wall    = round( ( microtime( true ) - ( defined( 'WP_START_TIMESTAMP' ) ? WP_START_TIMESTAMP : $_SERVER['REQUEST_TIME_FLOAT'] ) ), 2 );

		return array(
			'rows_exact' => $rows_exact,
			'screen'    => $screen,
			'timed'     => $timed,
			'capped'    => $capped,
			'by_plugin' => $by_plugin,
			'patterns'  => $patterns,
			'slowest'   => $slowest,
			'total_ms'  => $total_ms,
			'rows'      => $rows,
			'total_q'   => $total_q,
			'peak_mb'   => $peak_mb,
			'wall'      => $wall,
		);
	}

	/**
	 * Plain-text report, written to disk for the harness scripts.
	 */
	public static function report() {
		$a = self::analyze();
		if ( ! $a ) {
			return;
		}

		$screen    = $a['screen'];
		$timed     = $a['timed'];
		$capped    = $a['capped'];
		$by_plugin = $a['by_plugin'];
		$patterns  = $a['patterns'];
		$slowest   = $a['slowest'];
		$total_ms  = $a['total_ms'];
		$rows      = $a['rows'];
		$total_q   = $a['total_q'];
		$peak_mb   = $a['peak_mb'];
		$wall      = $a['wall'];

		$out   = array();
		$out[] = str_repeat( '=', 78 );
		$out[] = sprintf( '  SCREEN   %s', $screen );
		if ( $timed ) {
			$out[] = sprintf( '  TIME     %ss wall, %.0fms in SQL', $wall, $total_ms );
		} else {
			$out[] = sprintf( '  TIME     %ss wall (per-query timing needs SAVEQUERIES)', $wall );
		}
		$out[] = sprintf( '  QUERIES  %s total across %d rows on screen', number_format( $total_q ), $rows );
		$out[] = sprintf( '  MEMORY   %s MB peak', $peak_mb );
		if ( $capped ) {
			$out[] = sprintf( '  NOTE     capped at %s queries - counts are a floor', number_format( self::MAX_QUERIES ) );
		}
		$out[] = str_repeat( '=', 78 );
		$out[] = '';
		$out[] = sprintf( '  %-38s %8s %10s %9s', 'COMPONENT', 'QUERIES', $timed ? 'SQL TIME' : '', 'PER ROW' );
		$out[] = '  ' . str_repeat( '-', 74 );

		foreach ( $by_plugin as $who => $d ) {
			$out[] = sprintf(
				'  %-38s %8s %10s %9s',
				substr( $who, 0, 38 ),
				number_format( $d['count'] ),
				$timed ? sprintf( '%.0fms', $d['ms'] ) : '',
				$rows > 0 ? sprintf( '%.1f', $d['count'] / $rows ) : '-'
			);
		}

		$out[] = '';

		// The most-repeated query shapes. This is the part a developer acts on:
		// "who is slow" only narrows it down, "this exact query ran 300 times"
		// points at the line of code.
		uasort( $patterns, function ( $a, $b ) { return $b['count'] <=> $a['count']; } );
		$top = array_slice( $patterns, 0, 5 );

		// Slowest individual queries. Repetition and expense are different
		// problems: 300 cheap repeats and one 150ms table scan can cost the same
		// wall time, but they have completely different fixes. A report that
		// only shows repeats hides every single-query problem.
		//
		// Only meaningful when we have real timings - without SAVEQUERIES every
		// row would read 0ms, which looks like a finding rather than a gap.
		if ( $timed ) {
			usort( $slowest, function ( $a, $b ) { return $b['ms'] <=> $a['ms']; } );

			$out[] = '  SLOWEST INDIVIDUAL QUERIES';
			$out[] = '  ' . str_repeat( '-', 74 );
			foreach ( array_slice( $slowest, 0, 5 ) as $s ) {
				$out[] = sprintf( '  %6.0fms  %s', $s['ms'], $s['who'] );
				$out[] = '        ' . substr( $s['sql'], 0, 150 );
			}
			$out[] = '';
		}

		$out[] = '  MOST REPEATED QUERIES';
		$out[] = '  ' . str_repeat( '-', 74 );
		foreach ( $top as $p ) {
			if ( $p['count'] < 2 ) {
				continue;
			}
			$out[] = sprintf( '  %sx  %-28s %.0fms total', str_pad( (string) $p['count'], 5, ' ', STR_PAD_LEFT ), $p['who'], $p['ms'] );
			$out[] = '        ' . substr( $p['shape'], 0, 150 );
		}

		$out[] = '';

		// The headline. Anything at roughly one query per rendered row is
		// looping over orders instead of batching them.
		$suspects = array();
		if ( $rows > 0 ) {
			foreach ( $by_plugin as $who => $d ) {
				if ( 'core' === $who ) {
					continue;
				}
				if ( ( $d['count'] / $rows ) >= 0.9 ) {
					$suspects[ $who ] = $d;
				}
			}
		}

		if ( $suspects ) {
			$out[] = '  LIKELY N+1 - query count scales with rows on screen:';
			$out[] = '';
			foreach ( $suspects as $who => $d ) {
				$out[] = sprintf(
					'    * %s - %s queries for %d rows (%.1f per row)',
					$who, number_format( $d['count'] ), $rows, $d['count'] / $rows
				);
				if ( $d['sample'] ) {
					$out[] = sprintf( '        %s', $d['sample'] );
				}
			}
			$out[] = '';
			$out[] = '  Confirm: reload at a different page size. If the per-row figure holds';
			$out[] = '  steady while the total scales, it is a real N+1.';
		} else {
			$out[] = '  No per-row query pattern detected on this screen.';
		}

		$out[] = '';

		$text = implode( PHP_EOL, $out ) . PHP_EOL;

		// Everything below is diagnostic output for the test harness. A shipped
		// plugin writes nothing and echoes nothing - the UI panel is the product.
		$dir = self::out_dir();
		if ( ! $dir ) {
			return;
		}

		// Machine-readable twin, for compare.ps1. A single run cannot tell a
		// per-row cost from a fixed cost - only the slope between two different
		// page sizes can - so the numbers have to survive into a second run.
		$json = array(
			'screen'     => $screen,
			'rows'       => $rows,
			'queries'    => $total_q,
			'sql_ms'     => round( $total_ms, 1 ),
			'wall_s'     => $wall,
			'peak_mb'    => $peak_mb,
			'components' => array(),
		);
		foreach ( $by_plugin as $who => $d ) {
			$json['components'][ $who ] = array(
				'count' => $d['count'],
				'ms'    => round( $d['ms'], 1 ),
			);
		}
		@file_put_contents( $dir . '/latest.json', wp_json_encode( $json, JSON_PRETTY_PRINT ) );

		// Write unconditionally and report the outcome. A silently skipped write
		// is worse than a failed one - it looks like the probe never ran.
		$written = @file_put_contents( $dir . '/latest.txt', $text );
		if ( false === $written ) {
			$err  = error_get_last();
			$text .= sprintf(
				'  [aqp] could not write %s/latest.txt: %s' . PHP_EOL,
				$dir,
				$err ? $err['message'] : 'unknown error'
			);
		} else {
			@file_put_contents(
				$dir . '/profile-' . gmdate( 'Ymd-His' ) . '-' . preg_replace( '/[^a-z0-9_-]/i', '', $screen ) . '.txt',
				$text
			);
			$text .= sprintf( '  [aqp] wrote %s/latest.txt (%d bytes)' . PHP_EOL, $dir, $written );
		}

		// Also inline it, so it is visible straight from curl.
		echo "\n<!-- AQP-PROFILER\n" . $text . "-->\n";
	}

	// -----------------------------------------------------------------
	// Admin UI
	// -----------------------------------------------------------------

	/**
	 * "Scan this screen" control. Always present for capable users - the whole
	 * point is that profiling is something you turn on deliberately.
	 */
	public static function admin_bar_button( $bar ) {
		if ( ! is_admin() ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Strip our own arg first, or repeated scans stack stale nonces.
		$url = add_query_arg(
			'aqp_profile',
			wp_create_nonce( 'aqp_profile' ),
			remove_query_arg( 'aqp_profile' )
		);

		// The label always says what CLICKING does, never what state the request
		// is in. "Query scan: on" read as a status line and people looked
		// straight past it - including, in testing, the person who wrote it.
		$bar->add_node( array(
			'id'    => 'aqp-scan',
			'title' => esc_html__( 'Scan this screen', 'admin-query-profiler-for-woocommerce' ),
			'href'  => $url,
			'meta'  => array(
				'title' => esc_attr__( 'Profile the database queries this screen runs', 'admin-query-profiler-for-woocommerce' ),
			),
		) );
	}

	/**
	 * Remember this scan so the next one at a different page size can be
	 * compared against it.
	 *
	 * A single scan genuinely cannot tell a per-row cost from a fixed cost, so
	 * the UI must not pretend otherwise. Two scans at different row counts give
	 * the slope, and only the slope is an N+1.
	 */
	private static function remember( $screen, $rows, array $by_plugin ) {
		$counts = array();
		foreach ( $by_plugin as $who => $d ) {
			$counts[ $who ] = (int) $d['count'];
		}

		$key   = 'aqp_prev_' . get_current_user_id() . '_' . md5( $screen );
		$store = get_transient( $key );
		if ( ! is_array( $store ) ) {
			$store = array();
		}

		// Keep several recent scans at DISTINCT page sizes, most recent first,
		// rather than just the last one. Storing only the last scan meant the
		// verdict appeared once and then vanished on refresh: scanning 100 then
		// 20 compared fine, but the next reload at 20 had only a 20-row scan to
		// compare against and fell back to "scan again". A result that
		// disappears when you reload the page is not a result.
		$prev      = null;
		$prev_rows = 0;
		foreach ( $store as $entry ) {
			if ( isset( $entry['rows'] ) && (int) $entry['rows'] !== (int) $rows ) {
				$prev      = $entry;
				$prev_rows = (int) $entry['rows'];
				break;
			}
		}

		// Replace any existing entry for this page size, then push to the front.
		$kept = array();
		foreach ( $store as $entry ) {
			if ( isset( $entry['rows'] ) && (int) $entry['rows'] !== (int) $rows ) {
				$kept[] = $entry;
			}
		}
		array_unshift( $kept, array( 'rows' => (int) $rows, 'counts' => $counts ) );
		set_transient( $key, array_slice( $kept, 0, 3 ), HOUR_IN_SECONDS );

		if ( ! $prev ) {
			return null;
		}

		$delta_rows = (int) $rows - $prev_rows;
		$names      = array_unique( array_merge( array_keys( $counts ), array_keys( $prev['counts'] ) ) );
		$slopes     = array();

		foreach ( $names as $who ) {
			$now    = isset( $counts[ $who ] ) ? $counts[ $who ] : 0;
			$before = isset( $prev['counts'][ $who ] ) ? $prev['counts'][ $who ] : 0;
			$slope  = ( $now - $before ) / $delta_rows;
			$slopes[ $who ] = array(
				'slope' => $slope,
				'fixed' => (int) round( $now - ( $slope * $rows ) ),
				'a'     => $before,
				'b'     => $now,
			);
		}

		uasort( $slopes, function ( $x, $y ) { return $y['slope'] <=> $x['slope']; } );

		return array(
			'rows_a' => $prev_rows,
			'rows_b' => (int) $rows,
			'slopes' => $slopes,
		);
	}

	/**
	 * The in-page panel. Rendered on admin_footer rather than shutdown, because
	 * shutdown fires after the document is finished and anything echoed there
	 * lands outside the page.
	 */
	public static function render_panel() {
		$a = self::analyze();
		if ( ! $a ) {
			return;
		}

		$compare = self::remember( $a['screen'], $a['rows'], $a['by_plugin'] );

		// Headline. Say only what the evidence supports.
		if ( $compare ) {
			$worst = null;
			foreach ( $compare['slopes'] as $who => $s ) {
				if ( 'core' === $who ) {
					continue;
				}
				if ( $s['slope'] >= 0.5 ) {
					$worst = array( 'who' => $who ) + $s;
					break;
				}
			}
			if ( $worst ) {
				$headline = sprintf(
					'%s runs about %s queries for every order shown. At %d rows that is roughly %d queries from this one plugin.',
					esc_html( $worst['who'] ),
					esc_html( number_format( $worst['slope'], 1 ) ),
					(int) $compare['rows_b'],
					(int) round( $worst['slope'] * $compare['rows_b'] + $worst['fixed'] )
				);
				$tone = 'bad';
			} else {
				$headline = 'No plugin runs extra queries per order row. Slowness on this screen is fixed overhead, not a per-row problem.';
				$tone     = 'good';
			}
		} else {
			$headline = sprintf(
				'Scanned %s queries across %d rows. Change the page size (Screen Options) and scan again - one scan cannot tell a per-row cost from a fixed one.',
				number_format( $a['total_q'] ),
				(int) $a['rows']
			);
			$tone = 'info';
		}

		$border = 'bad' === $tone ? '#b32d2e' : ( 'good' === $tone ? '#00794c' : '#2271b1' );

		// Hidden until the script below relocates it - see the note at the end of
		// this method for why it cannot simply render where it is echoed.
		echo '<div id="aqp-panel" style="display:none;margin:20px 20px 20px 0;padding:0;border:1px solid #c3c4c7;border-left:4px solid ' . esc_attr( $border ) . ';background:#fff;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">';
		echo '<div style="padding:12px 16px;border-bottom:1px solid #f0f0f1;">';
		echo '<strong style="font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:#50575e;">Query scan</strong>';
		echo '<p style="margin:6px 0 0;font-size:14px;line-height:1.5;color:#1d2327;">' . wp_kses_post( $headline ) . '</p>';
		echo '</div>';

		echo '<div style="overflow-x:auto;padding:12px 16px;">';
		echo '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
		echo '<tr style="text-align:left;color:#50575e;font-size:11px;text-transform:uppercase;letter-spacing:.05em;">';
		echo '<th style="padding:4px 8px 4px 0;">Plugin</th><th style="padding:4px 8px;text-align:right;">Queries</th>';
		if ( $a['timed'] ) {
			echo '<th style="padding:4px 8px;text-align:right;">Time</th>';
		}
		if ( $compare ) {
			echo '<th style="padding:4px 8px;text-align:right;">Per row</th>';
		}
		echo '</tr>';

		$shown = 0;
		foreach ( $a['by_plugin'] as $who => $d ) {
			if ( $shown++ >= 8 ) {
				break;
			}
			$slope = ( $compare && isset( $compare['slopes'][ $who ] ) ) ? $compare['slopes'][ $who ]['slope'] : null;
			$flag  = ( null !== $slope && $slope >= 0.5 && 'core' !== $who );

			echo '<tr style="border-top:1px solid #f0f0f1;' . ( $flag ? 'background:#fcf0f1;' : '' ) . '">';
			echo '<td style="padding:6px 8px 6px 0;' . ( $flag ? 'font-weight:600;' : '' ) . '">' . esc_html( $who ) . '</td>';
			echo '<td style="padding:6px 8px;text-align:right;font-variant-numeric:tabular-nums;">' . esc_html( number_format( $d['count'] ) ) . '</td>';
			if ( $a['timed'] ) {
				echo '<td style="padding:6px 8px;text-align:right;font-variant-numeric:tabular-nums;">' . esc_html( round( $d['ms'] ) ) . 'ms</td>';
			}
			if ( $compare ) {
				echo '<td style="padding:6px 8px;text-align:right;font-variant-numeric:tabular-nums;">' . ( null === $slope ? '&ndash;' : esc_html( number_format( $slope, 1 ) ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</table>';

		if ( $compare ) {
			printf(
				'<p style="margin:10px 0 0;font-size:12px;color:#646970;">Compared %d rows against %d rows. Only a cost that grows with the row count is an N+1.</p>',
				(int) $compare['rows_a'],
				(int) $compare['rows_b']
			);
		}
		if ( empty( $a['rows_exact'] ) ) {
			// Say so. A per-row figure computed against an inferred denominator
			// is exactly how this tool would produce a confident wrong answer.
			echo '<p style="margin:6px 0 0;font-size:12px;color:#646970;">Row count inferred from this screen&rsquo;s page-size setting rather than counted directly, so per-row figures are approximate.</p>';
		}
		if ( ! $a['timed'] ) {
			echo '<p style="margin:6px 0 0;font-size:12px;color:#646970;">Query timings need <code>SAVEQUERIES</code> in wp-config.php. Counts and attribution do not.</p>';
		}
		echo '</div></div>';

		// admin_footer fires OUTSIDE #wpbody-content, so anything echoed here
		// renders underneath the admin sidebar with its left edge clipped. There
		// is no hook that runs after a list table but inside the content column,
		// so move the node into place instead. Doing it in JS rather than with a
		// hardcoded margin keeps it correct when the menu is collapsed, on
		// mobile, and in RTL.
		?>
		<script>
		( function () {
			var panel  = document.getElementById( 'aqp-panel' );
			var target = document.getElementById( 'wpbody-content' );
			if ( ! panel ) { return; }
			if ( target ) { target.appendChild( panel ); }
			panel.style.display = '';
		} )();
		</script>
		<?php
	}
}

