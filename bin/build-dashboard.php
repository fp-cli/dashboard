<?php

require_once dirname( __DIR__ ) . '/theme/functions.php';

/**
 * Builds the FP-CLI committer dashboard.
 *
 * @when before_fp_load
 */
function fp_cli_dashboard_build_dashboard( $args, $assoc_args ) {

	$html = fp_cli_dashboard_get_template_part( 'index' );

	file_put_contents( FP_CLI_DASHBOARD_BASE_DIR . '/index.html', trim( $html ) );
	FP_CLI::success( 'Dashboard built.' );
}

FP_CLI::add_command( 'dashboard build', 'fp_cli_dashboard_build_dashboard' );
