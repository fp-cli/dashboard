<?php

/**
 * Generates a list of recent contributors
 * 
 * ## OPTIONS
 * 
 * [--months=<months>]
 * : Number of months back to look.
 * ---
 * default: 6
 * ---
 * 
 * [--format=<format>]
 * : Render output in a particular format.
 * ---
 * default: table
 * options:
 *  - table
 *  - csv
 *  - json
 * --- 
 *
 * @when before_fp_load
 */
function fp_cli_dashboard_recent_contributors( $args, $assoc_args ) {

	$recent_contributors = [];
	foreach ( glob( FP_CLI_DASHBOARD_BASE_DIR . '/github-data/contributors/*' ) as $file ) {
		$contributor   = basename( $file );
		$dates         = explode( PHP_EOL, file_get_contents( $file ) );
		$is_recent     = false;
		foreach ( $dates as $date ) {
			if ( strtotime( $date ) > strtotime( sprintf( '%d months ago', $assoc_args['months'] ) ) ) {
				$is_recent = true;
				break;
			}
		}
		if ( $is_recent ) {
			$recent_contributors[] = [
				'contributor' => $contributor,
			];
		}
	}

	FP_CLI\Utils\format_items( $assoc_args['format'], $recent_contributors, array( 'contributor' ) );
}

FP_CLI::add_command( 'dashboard recent-contributors', 'fp_cli_dashboard_recent_contributors' );