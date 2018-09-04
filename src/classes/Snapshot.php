<?php
/**
 * Handle snapshot actions
 *
 * @package wpsnapshots
 */

namespace WPSnapshots;

use Symfony\Component\Console\Output\OutputInterface;
use \Exception;
use WPSnapshots\Utils;
use WPSnapshots\Log;

/**
 * Create, download, save, push, and pull snapshots
 */
class Snapshot {
	/**
	 * Snapshot id
	 *
	 * @var string
	 */
	public $id;

	/**
	 * Snapshot meta data
	 *
	 * @var array
	 */
	public $meta = [];

	/**
	 * Snapshot constructor. Snapshot must already exist locally in $path.
	 *
	 * @param string $id Snapshot id
	 * @param array  $meta Snapshot meta data
	 * @throws Exception Throw exception if files don't exist.
	 */
	public function __construct( $id, $meta ) {
		$this->id   = $id;
		$this->meta = $meta;

		if ( ! file_exists( Utils\get_snapshot_directory() . $id . '/data.sql.gz' ) || ! file_exists( Utils\get_snapshot_directory() . $id . '/files.tar.gz' ) ) {
			throw new Exception( 'Snapshot data or files do not exist locally.' );
		}
	}

	/**
	 * Given an ID, create a WP Snapshots object
	 *
	 * @param  string $id Snapshot ID
	 * @return Snapshot
	 */
	public static function get( $id ) {
		if ( file_exists( Utils\get_snapshot_directory() . $id . '/meta.json' ) ) {
			$meta_file_contents = file_get_contents( Utils\get_snapshot_directory() . $id . '/meta.json' );
			$meta               = json_decode( $meta_file_contents, true );
		} else {
			$meta = [];
		}

		return new self( $id, $meta );
	}

	/**
	 * Create a snapshot.
	 *
	 * @param array $args List of arguments
	 * @return bool|Snapshot
	 */
	public static function create( $args ) {
		/**
		 * Define snapshot ID
		 */
		$id = Utils\generate_snapshot_id();

		$path = Utils\normalize_path( $args['path'] );

		$create_dir = Utils\create_snapshot_directory( $id );

		if ( ! $create_dir ) {
			Log::instance()->write( 'Cannot create necessary snapshot directories.', 0, 'error' );

			return false;
		}

		if ( ! Utils\is_wp_present( $path ) ) {
			Log::instance()->write( 'This is not a WordPress install.', 0, 'error' );

			return false;
		}

		if ( ! Utils\locate_wp_config( $path ) ) {
			Log::instance()->write( 'No wp-config.php file present.', 0, 'error' );

			return false;
		}

		$extra_config_constants = [
			'WP_CACHE' => false,
		];

		if ( ! empty( $args['db_host'] ) ) {
			$extra_config_constants['DB_HOST'] = $args['db_host'];
		} if ( ! empty( $args['db_name'] ) ) {
			$extra_config_constants['DB_NAME'] = $args['db_name'];
		} if ( ! empty( $args['db_user'] ) ) {
			$extra_config_constants['DB_USER'] = $args['db_user'];
		} if ( ! empty( $args['db_password'] ) ) {
			$extra_config_constants['DB_PASSWORD'] = $args['db_password'];
		}

		Log::instance()->write( 'Bootstrapping WordPress...', 1 );

		$wp = WordPressBridge::instance()->load( $path, $extra_config_constants );

		if ( Utils\is_error( $wp ) ) {
			Log::instance()->write( 'Could not connect to WordPress database.', 0, 'error' );

			return false;
		}

		global $wpdb;

		$meta = [
			'author'      => [],
			'description' => $args['description'],
			'project'     => $args['project'],
		];

		$config = Config::instance()->get();

		if ( ! empty( $config['name'] ) ) {
			$meta['author']['name'] = $config['name'];
		}

		if ( ! empty( $config['email'] ) ) {
			$meta['author']['email'] = $config['email'];
		}

		$meta['multisite']         = false;
		$meta['subdomain_install'] = false;
		$meta['sites']             = [];

		if ( is_multisite() ) {
			$meta['multisite'] = true;

			if ( defined( 'SUBDOMAIN_INSTALL' ) && SUBDOMAIN_INSTALL ) {
				$meta['subdomain_install'] = true;
			}

			$sites = get_sites( [ 'number' => 500 ] );

			foreach ( $sites as $site ) {
				$meta['sites'][] = [
					'blog_id'  => $site->blog_id,
					'domain'   => $site->domain,
					'path'     => $site->path,
					'site_url' => get_site_url( $site->blog_id ),
					'home_url' => get_home_url( $site->blog_id ),
				];
			}
		} else {
			$meta['sites'][] = [
				'site_url' => get_site_url(),
				'home_url' => get_home_url(),
			];
		}

		$meta['table_prefix'] = $GLOBALS['table_prefix'];

		global $wp_version;

		$meta['wp_version'] = ( ! empty( $wp_version ) ) ? $wp_version : '';

		/**
		 * Dump sql to .wpsnapshots/data.sql
		 */
		$command          = '/usr/bin/env mysqldump --no-defaults %s';
		$command_esc_args = array( DB_NAME );
		$command         .= ' --tables';

		/**
		 * We only export tables with WP prefix
		 */
		Log::instance()->write( 'Getting WordPress tables...', 1 );

		$tables = Utils\get_tables();

		foreach ( $tables as $table ) {
			// We separate the users table for scrubbing
			if ( ! $args['no_scrub'] && $GLOBALS['table_prefix'] . 'users' === $table ) {
				continue;
			}

			$command           .= ' %s';
			$command_esc_args[] = trim( $table );
		}

		$snapshot_path = Utils\get_snapshot_directory() . $id . '/';

		$mysql_args = [
			'host'        => DB_HOST,
			'pass'        => DB_PASSWORD,
			'user'        => DB_USER,
			'result-file' => $snapshot_path . 'data.sql',
		];

		if ( defined( 'DB_CHARSET' ) && constant( 'DB_CHARSET' ) ) {
			$mysql_args['default-character-set'] = constant( 'DB_CHARSET' );
		}

		$escaped_command = call_user_func_array( '\WPSnapshots\Utils\esc_cmd', array_merge( array( $command ), $command_esc_args ) );

		Log::instance()->write( 'Exporting database...' );

		Utils\run_mysql_command( $escaped_command, $mysql_args );

		if ( ! $args['no_scrub'] ) {
			$command = '/usr/bin/env mysqldump --no-defaults %s';

			$command_esc_args = array( DB_NAME );

			$command           .= ' --tables %s';
			$command_esc_args[] = $GLOBALS['table_prefix'] . 'users';

			$mysql_args = [
				'host'        => DB_HOST,
				'pass'        => DB_PASSWORD,
				'user'        => DB_USER,
				'result-file' => $snapshot_path . 'data-users.sql',
			];

			$escaped_command = call_user_func_array( '\WPSnapshots\Utils\esc_cmd', array_merge( array( $command ), $command_esc_args ) );

			Log::instance()->write( 'Exporting users...', 1 );

			Utils\run_mysql_command( $escaped_command, $mysql_args );

			Log::instance()->write( 'Scrubbing user database...' );

			$all_hashed_passwords = [];

			Log::instance()->write( 'Getting users...', 1 );

			$passwords = $wpdb->get_results( "SELECT user_pass FROM $wpdb->users", ARRAY_A );

			foreach ( $passwords as $password_row ) {
				$all_hashed_passwords[] = $password_row['user_pass'];
			}

			$sterile_password = wp_hash_password( 'password' );

			Log::instance()->write( 'Opening users export...', 1 );

			$users_handle = @fopen( $snapshot_path . 'data-users.sql', 'r' );
			$data_handle  = @fopen( $snapshot_path . 'data.sql', 'a' );

			if ( ! $users_handle || ! $data_handle ) {
				Log::instance()->write( 'Could not scrub users.', 0, 'error' );

				return false;
			}

			$buffer = '';
			$i      = 0;

			Log::instance()->write( 'Writing scrubbed user data and merging exports...', 1 );

			while ( ! feof( $users_handle ) ) {
				$chunk = fread( $users_handle, 4096 );

				foreach ( $all_hashed_passwords as $password ) {
					$chunk = str_replace( "'$password'", "'$sterile_password'", $chunk );
				}

				$buffer .= $chunk;

				if ( 0 === $i % 10000 ) {
					fwrite( $data_handle, $buffer );
					$buffer = '';
				}

				$i++;
			}

			if ( ! empty( $buffer ) ) {
				fwrite( $data_handle, $buffer );
				$buffer = '';
			}

			fclose( $data_handle );
			fclose( $users_handle );

			Log::instance()->write( 'Removing old SQL...', 1 );

			unlink( $snapshot_path . 'data-users.sql' );
		}

		$verbose_pipe = ( Log::instance()->isVerbose() ) ? '> /dev/null' : '';

		/**
		 * Create file back up of wp-content in .wpsnapshots/files.tar.gz
		 */

		Log::instance()->write( 'Saving file back up...' );

		$excludes = '';

		if ( $args['exclude_uploads'] ) {
			$excludes .= ' --exclude="./uploads"';
		}

		Log::instance()->write( 'Compressing files...', 1 );

		exec( 'cd ' . escapeshellarg( WP_CONTENT_DIR ) . '/ && tar ' . $excludes . ' -zcf ' . Utils\escape_shell_path( $snapshot_path ) . 'files.tar.gz . ' . $verbose_pipe );

		Log::instance()->write( 'Compressing database backup...', 1 );

		exec( 'gzip -9 ' . Utils\escape_shell_path( $snapshot_path ) . 'data.sql ' . $verbose_pipe );

		$meta['size'] = filesize( $snapshot_path . 'data.sql.gz' ) + filesize( $snapshot_path . 'files.tar.gz' );

		/**
		 * Finally save snapshot meta to meta.json
		 */
		$meta_handle = @fopen( $snapshot_path . 'meta.json', 'x' ); // Create file and fail if it exists.

		if ( ! $users_handle || ! $data_handle ) {
			Log::instance()->write( 'Could not create .wpsnapshots/SNAPSHOT_ID/meta.json.', 0, 'error' );

			return false;
		}

		fwrite( $meta_handle, json_encode( $meta, JSON_PRETTY_PRINT ) );

		$snapshot = new self( $id, $meta );

		return $snapshot;
	}

	/**
	 * Download snapshot.
	 *
	 * @param string $id Snapshot id
	 * @return  bool|Snapshot
	 */
	public static function download( $id ) {
		if ( Utils\is_snapshot_cached( $id ) ) {
			Log::instance()->write( 'Snapshot found in cache.' );

			return self::get( $id );
		}

		$create_dir = Utils\create_snapshot_directory( $id );

		if ( ! $create_dir ) {
			Log::instance()->write( 'Cannot create necessary snapshot directories.', 0, 'error' );

			return false;
		}

		Log::instance()->write( 'Getting snapshot information...' );

		$snapshot = Connection::instance()->db->getSnapshot( $id );

		if ( Utils\is_error( $snapshot ) ) {
			Log::instance()->write( 'Could not get snapshot from database.', 0, 'error' );

			if ( is_array( $snapshot->data ) ) {
				if ( 'AccessDeniedException' === $snapshot->data['aws_error_code'] ) {
					Log::instance()->write( 'Access denied. You might not have access to this project.', 0, 'error' );
				}

				Log::instance()->write( 'Error Message: ' . $snapshot->data['message'], 1, 'error' );
				Log::instance()->write( 'AWS Request ID: ' . $snapshot->data['aws_request_id'], 1, 'error' );
				Log::instance()->write( 'AWS Error Type: ' . $snapshot->data['aws_error_type'], 1, 'error' );
				Log::instance()->write( 'AWS Error Code: ' . $snapshot->data['aws_error_code'], 1, 'error' );
			}

			return false;
		}

		if ( empty( $snapshot ) || empty( $snapshot['project'] ) ) {
			Log::instance()->write( 'Missing critical snapshot data.', 0, 'error' );
			return false;
		}

		Log::instance()->write( 'Downloading snapshot files and database...' );

		$snapshot_path = Utils\get_snapshot_directory() . $id . '/';

		$download = Connection::instance()->s3->downloadSnapshot( $id, $snapshot['project'], $snapshot_path . 'data.sql.gz', $snapshot_path . 'files.tar.gz' );

		if ( Utils\is_error( $download ) ) {
			Log::instance()->write( 'Failed to download snapshot.', 0, 'error' );

			if ( is_array( $download->data ) ) {
				if ( 'AccessDenied' === $download->data['aws_error_code'] ) {
					Log::instance()->write( 'Access denied. You might not have access to this project.', 0, 'error' );
				}

				Log::instance()->write( 'Error Message: ' . $download->data['message'], 1, 'error' );
				Log::instance()->write( 'AWS Request ID: ' . $download->data['aws_request_id'], 1, 'error' );
				Log::instance()->write( 'AWS Error Type: ' . $download->data['aws_error_type'], 1, 'error' );
				Log::instance()->write( 'AWS Error Code: ' . $download->data['aws_error_code'], 1, 'error' );
			}

			return false;
		}

		/**
		 * Finally save snapshot meta to meta.json
		 */
		$meta_handle = @fopen( $snapshot_path . 'meta.json', 'x' ); // Create file and fail if it exists.

		if ( ! $meta_handle ) {
			Log::instance()->write( 'Could not create meta.json.', 0, 'error' );

			return false;
		}

		fwrite( $meta_handle, json_encode( $snapshot, JSON_PRETTY_PRINT ) );

		return new self( $id, $snapshot );
	}
}