<?php

require_once dirname( __DIR__ ) . '/theme/functions.php';

/**
 * Fetches GitHub data defined in ./config.yml and stores it in in ./github-data.
 *
 * ## OPTIONS
 *
 * [--only=<section>]
 * : Only rebuild a specific category of data.
 * ---
 * options:
 *   - data
 *   - contributors
 *   - repositories
 * ---
 *
 * [--force]
 * : Forcefully overwrite any existing data.
 *
 * @when before_fp_load
 */
function fp_cli_dashboard_fetch_github_data( $args, $assoc_args ) {

	$config_file = FP_CLI_DASHBOARD_BASE_DIR . '/config.yml';
	if ( ! file_exists( $config_file ) ) {
		FP_CLI::error( 'Unable to load ./config.yml' );
	}

	$config = Spyc::YAMLLoad( $config_file );
	if ( empty( $config['github_data'] ) ) {
		FP_CLI::error( 'No \'github_data\' attribute found in ./config.yml' );
	}

	if ( ! getenv( 'GITHUB_TOKEN' ) ) {
		FP_CLI::error( 'GITHUB_TOKEN environment variable must be set.' );
	}

	if ( ! is_dir( FP_CLI_DASHBOARD_BASE_DIR . '/data' ) ) {
		mkdir( FP_CLI_DASHBOARD_BASE_DIR . '/data' );
	}

	$headers = array(
		'Accept'        => 'application/vnd.github.v3+json',
		'User-Agent'    => 'FP-CLI',
		'Authorization' => 'token ' . getenv( 'GITHUB_TOKEN' ),
	);

	if ( empty( $assoc_args['only'] ) || 'data' === $assoc_args['only'] ) {
		FP_CLI::log( sprintf( 'Fetching %d GitHub data points...', count( $config['github_data'] ) ) );
		foreach ( $config['github_data'] as $key => $meta ) {

			if ( empty( $meta['search'] ) ) {
				FP_CLI::warning( sprintf( 'Invalid \'search\' for %s', $key ) );
				continue;
			}

			$time = date( 'Y-m-d-H-00' );
			$path = FP_CLI_DASHBOARD_BASE_DIR . '/github-data/' . $key . '/' . $time;
			if ( file_exists( $path ) && empty( $assoc_args['force'] ) ) {
				FP_CLI::log( sprintf( 'Skipping: Data already exists for %s on %s', $key, $time ) );
				continue;
			}

			$query = array(
				'q' => $meta['search'],
			);
			$response = FP_CLI\Utils\http_request( 'GET', 'https://api.github.com/search/issues', $query, $headers );
			if ( 20 !== (int) substr( $response->status_code, 0, 2 ) ) {
				FP_CLI::error(
					sprintf(
						"Failed request. GitHub API returned: %s (HTTP code %d)",
						$response->body,
						$response->status_code
					)
				);
			}
			$data = json_decode( $response->body );
			$total_count = $data->total_count;

			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0777, true );
			}
			file_put_contents( $path, $total_count );
			FP_CLI::log( sprintf( 'Saved: Total count for %s on %s: %d', $key, $time, $total_count ) );
		}
	}

	if ( empty( $assoc_args['only'] ) || 'contributors' === $assoc_args['only'] ) {
		FP_CLI::log( sprintf( 'Fetching GitHub contributor data for %d repositories...', count( $config['github_repositories'] ) ) );
		foreach ( $config['github_repositories'] as $repo ) {

			$actors           = array();
			$repo_short       = str_replace( 'fp-cli/', '', $repo );
			$most_recent_date = strtotime( '2 weeks ago' );

			FP_CLI::log( sprintf( ' - %s', $repo ) );

			$query      = array(
				'per_page' => 100,
				'state'    => 'all',
				'since'    => gmdate( DATE_ATOM, $most_recent_date ),
			);
			$issues_api = sprintf( 'https://api.github.com/repos/%s/issues', $repo ) . '?' . http_build_query( $query );
			do {
				$response = FP_CLI\Utils\http_request( 'GET', $issues_api, array(), $headers, array(
					'timeout' => 30,
				) );
				if ( 20 !== (int) substr( $response->status_code, 0, 2 ) ) {
					FP_CLI::warning(
						sprintf(
							"Failed request. GitHub API returned: %s (HTTP code %d)",
							$response->body,
							$response->status_code
						)
					);
					break;
				}
				preg_match( "/<(.*)>; rel=\"next\"/", $response->headers['link'], $matches );
				$issues_api = ! empty( $matches ) ? $matches[1] : '';
				$issues = json_decode( $response->body );
				foreach ( $issues as $issue ) {
					if ( empty( $issue->user ) || false !== stripos( $issue->user->login, '[bot]' ) ) {
						continue;
					}
					if ( ! isset( $actors[ $issue->user->login ] ) ) {
						$actors[ $issue->user->login ] = array();
					}
					$event_time                      = strtotime( $issue->created_at );
					$actors[ $issue->user->login ][] = gmdate( 'Y-m-d', $event_time );

					$comments_api = $issue->comments_url . '?' . http_build_query( array( 'per_page' => 100 ) );
					do {
						$response = FP_CLI\Utils\http_request( 'GET', $comments_api, array(), $headers, array(
							'timeout' => 30,
						) );
						if ( 20 !== (int) substr( $response->status_code, 0, 2 ) ) {
							FP_CLI::warning(
								sprintf(
									"Failed request. GitHub API returned: %s (HTTP code %d)",
									$response->body,
									$response->status_code
								)
							);
							break;
						}
						$comments = json_decode( $response->body );
						foreach ( $comments as $comment ) {
							if ( empty( $comment->user ) ) {
								continue;
							}
							if ( ! isset( $actors[ $comment->user->login ] ) ) {
								$actors[ $comment->user->login ] = array();
							}
							$event_time                      = strtotime( $comment->created_at );
							$actors[ $comment->user->login ][] = gmdate( 'Y-m-d', $event_time );
						}
						preg_match( "/<(.*)>; rel=\"next\"/", $response->headers['link'], $matches );
						$comments_api = ! empty( $matches ) ? $matches[1] : '';
					} while ( $comments_api );
				}
			} while ( $issues_api );

			foreach ( $actors as $login => $dates ) {
				$path     = FP_CLI_DASHBOARD_BASE_DIR . '/github-data/contributors/' . $login;
				$existing = array();
				if ( file_exists( $path ) ) {
					$existing = explode( PHP_EOL, file_get_contents( $path ) );
				}
				$dates = array_merge( $existing, $dates );
				rsort( $dates );
				$dates = array_unique( $dates );
				if ( ! is_dir( dirname( $path ) ) ) {
					mkdir( dirname( $path ), 0777, true );
				}
				file_put_contents( $path, implode( PHP_EOL, $dates ) );
			}

		}
	}

	if ( empty( $assoc_args['only'] ) || 'repositories' === $assoc_args['only'] ) {
		FP_CLI::log( sprintf( 'Fetching %d GitHub repository data...', count( $config['github_repositories'] ) ) );
		foreach ( $config['github_repositories'] as $repo ) {

			$repo_short = str_replace( 'fp-cli/', '', $repo );

			$path = FP_CLI_DASHBOARD_BASE_DIR . '/github-data/repositories/' . $repo_short . '.json';
			$repository_data = array(
				'open_issues'        => null,
				'open_pull_requests' => null,
				'active_milestone'   => null,
				'latest_release'     => null,
			);

			$response = FP_CLI\Utils\http_request( 'GET', sprintf( 'https://api.github.com/repos/%s', $repo ), array(), $headers, array(
				'timeout' => 30,
			) );
			if ( 20 !== (int) substr( $response->status_code, 0, 2 ) ) {
				FP_CLI::error(
					sprintf(
						"Failed request. GitHub API returned: %s (HTTP code %d)",
						$response->body,
						$response->status_code
					)
				);
			}
			$data = json_decode( $response->body );
			$repository_data['open_issues'] = $data->open_issues;

			$query = array(
				'per_page' => 100,
			);
			$response = FP_CLI\Utils\http_request( 'GET', sprintf( 'https://api.github.com/repos/%s/pulls', $repo ), $query, $headers, array(
				'timeout' => 30,
			) );
			if ( 20 !== (int) substr( $response->status_code, 0, 2 ) ) {
				FP_CLI::error(
					sprintf(
						"Failed request. GitHub API returned: %s (HTTP code %d)",
						$response->body,
						$response->status_code
					)
				);
			}
			$data = json_decode( $response->body );
			$repository_data['open_pull_requests'] = count( $data );
			$repository_data['open_issues'] = $repository_data['open_issues'] - $repository_data['open_pull_requests'];

			$response = FP_CLI\Utils\http_request( 'GET', sprintf( 'https://api.github.com/repos/%s/milestones', $repo ), array(), $headers, array(
				'timeout' => 30,
			) );
			if ( 20 !== (int) substr( $response->status_code, 0, 2 ) ) {
				FP_CLI::error(
					sprintf(
						"Failed request. GitHub API returned: %s (HTTP code %d)",
						$response->body,
						$response->status_code
					)
				);
			}
			$data = json_decode( $response->body );
			if ( ! empty( $data ) ) {
				$repository_data['active_milestone'] = array_shift( $data );
			}

			$response = FP_CLI\Utils\http_request( 'GET', sprintf( 'https://api.github.com/repos/%s/releases', $repo ), array(), $headers, array(
				'timeout' => 30,
			) );
			if ( 20 !== (int) substr( $response->status_code, 0, 2 ) ) {
				FP_CLI::error(
					sprintf(
						"Failed request. GitHub API returned: %s (HTTP code %d)",
						$response->body,
						$response->status_code
					)
				);
			}
			$data = json_decode( $response->body );
			if ( ! empty( $data ) ) {
				$repository_data['latest_release'] = array_shift( $data );
			}

			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0777, true );
			}
			file_put_contents( $path, json_encode( $repository_data ) );
			FP_CLI::log( sprintf( 'Saved: %s', $repo ) );
		}
	}

	FP_CLI::success( 'Fetch data complete.' );
}

FP_CLI::add_command( 'dashboard fetch-github-data', 'fp_cli_dashboard_fetch_github_data' );
