<?php
/**
 * Minimal WordPress function stubs backed by an in-memory state store, enough
 * to unit test the plugin's logic without a WordPress install. Every stub is
 * guarded so the suite can also run inside a real WordPress test environment.
 *
 * @package Emailexpert\Events\Tests
 */

// phpcs:ignoreFile -- test infrastructure, mirrors WordPress core signatures.

class EEX_Test_State {
	public static array $options    = [];
	public static array $transients = [];
	public static array $filters    = [];
	public static array $posts      = [];
	public static array $post_meta  = [];
	public static array $terms      = []; // taxonomy => [ slug => [term_id, name, slug] ].
	public static array $object_terms = []; // post_id => taxonomy => [term slugs].
	public static array $scheduled  = [];
	public static array $mail       = [];
	public static int $next_post_id = 1;
	public static int $next_term_id = 1;
	public static int $post_write_count = 0;

	public static function reset(): void {
		self::$options          = [];
		self::$transients       = [];
		self::$filters          = [];
		self::$posts            = [];
		self::$post_meta        = [];
		self::$terms            = [];
		self::$object_terms     = [];
		self::$scheduled        = [];
		self::$mail             = [];
		self::$next_post_id     = 1;
		self::$next_term_id     = 1;
		self::$post_write_count = 0;

		global $wpdb;
		$wpdb = new EEX_Fake_WPDB();
	}
}

/**
 * Fake $wpdb that stores rows for the two custom tables in memory and
 * supports the narrow query set the plugin uses.
 */
class EEX_Fake_WPDB {
	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public array $tables  = []; // table => rows.
	public array $queries = [];
	public string $options = 'wp_options';

	public function insert( string $table, array $data, $format = null ): int {
		$data['id']                = count( $this->tables[ $table ] ?? [] ) + 1;
		$this->tables[ $table ][]  = $data;
		$this->insert_id           = $data['id'];
		return 1;
	}

	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[sdf]/', is_int( $arg ) || is_float( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'", $query, 1 );
		}
		return $query;
	}

	public function esc_like( $text ): string {
		return addcslashes( (string) $text, '_%\\' );
	}

	/**
	 * Parse "FROM <table> [WHERE ...]" with simple AND-joined conditions:
	 * col = 'v' | col = v | col LIKE '%v%' | col < 'v' | col >= 'v' | col != 'v'.
	 */
	private function match_rows( string $query ): array {
		if ( ! preg_match( '/FROM\s+(\S+)/i', $query, $m ) ) {
			return [ '', [] ];
		}
		$table = rtrim( $m[1], ';' );
		$rows  = $this->tables[ $table ] ?? [];

		if ( preg_match( '/WHERE\s+(.*?)(ORDER BY|LIMIT|$)/is', $query, $w ) ) {
			$conditions = preg_split( '/\s+AND\s+/i', trim( $w[1] ) );
			foreach ( $conditions as $condition ) {
				$condition = trim( $condition );
				if ( '' === $condition || '1=1' === str_replace( ' ', '', $condition ) ) {
					continue;
				}
				if ( ! preg_match( "/^(\w+)\s*(=|!=|<>|<=|>=|<|>|LIKE)\s*'?([^']*)'?$/i", $condition, $c ) ) {
					continue;
				}
				[ , $col, $op, $val ] = $c;
				$rows = array_values( array_filter( $rows, function ( $row ) use ( $col, $op, $val ) {
					$cell = (string) ( $row[ $col ] ?? '' );
					switch ( strtoupper( $op ) ) {
						case '=':
							return $cell === $val;
						case '!=':
						case '<>':
							return $cell !== $val;
						case '<':
							return $cell < $val;
						case '<=':
							return $cell <= $val;
						case '>':
							return $cell > $val;
						case '>=':
							return $cell >= $val;
						case 'LIKE':
							$needle = trim( $val, '%' );
							return '' !== $needle && str_contains( $cell, $needle );
					}
					return true;
				} ) );
			}
		}

		return [ $table, $rows ];
	}

	public function query( string $query ): int {
		$this->queries[] = $query;
		if ( preg_match( '/^\s*DELETE\s/i', $query ) ) {
			[ $table, $matched ] = $this->match_rows( $query );
			if ( '' !== $table ) {
				$ids = array_column( $matched, 'id' );
				$before = count( $this->tables[ $table ] ?? [] );
				$this->tables[ $table ] = array_values( array_filter(
					$this->tables[ $table ] ?? [],
					fn( $row ) => ! in_array( $row['id'], $ids, true )
				) );
				return $before - count( $this->tables[ $table ] );
			}
		}
		return 0;
	}

	public function get_row( string $query, $output = OBJECT ) {
		$this->queries[] = $query;
		[ , $rows ] = $this->match_rows( $query );
		if ( empty( $rows ) ) {
			return null;
		}
		return ARRAY_A === $output ? $rows[0] : (object) $rows[0];
	}

	public function get_results( string $query, $output = OBJECT ): array {
		$this->queries[] = $query;
		[ , $rows ] = $this->match_rows( $query );
		return ARRAY_A === $output ? $rows : array_map( fn( $r ) => (object) $r, $rows );
	}

	public function get_var( string $query ) {
		$this->queries[] = $query;
		if ( stripos( $query, 'COUNT(*)' ) !== false ) {
			[ , $rows ] = $this->match_rows( $query );
			return count( $rows );
		}
		return null;
	}

	public function get_col( string $query ): array {
		$this->queries[] = $query;
		return [];
	}

	public function get_charset_collate(): string {
		return '';
	}
}

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

$GLOBALS['wpdb'] = new EEX_Fake_WPDB();

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public array $errors = [];
		public array $error_data = [];

		public function __construct( string $code = '', string $message = '', $data = null ) {
			if ( $code ) {
				$this->errors[ $code ][] = $message;
				if ( null !== $data ) {
					$this->error_data[ $code ] = $data;
				}
			}
		}

		public function get_error_code() {
			return array_key_first( $this->errors );
		}

		public function get_error_message( string $code = '' ): string {
			$code = $code ?: (string) $this->get_error_code();
			return $this->errors[ $code ][0] ?? '';
		}

		public function get_error_data( string $code = '' ) {
			$code = $code ?: (string) $this->get_error_code();
			return $this->error_data[ $code ] ?? null;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

// --- i18n / escaping (identity in tests). -----------------------------------
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( '_x' ) ) {
	function _x( $text, $context, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = 'default' ) {
		return 1 === (int) $number ? $single : $plural;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return filter_var( (string) $url, FILTER_SANITIZE_URL );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return filter_var( (string) $url, FILTER_SANITIZE_URL );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return esc_html( $text );
	}
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = 'default' ) {
		return esc_attr( $text );
	}
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = 'default' ) {
		echo esc_html( $text );
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $text ) {
		return (string) $text;
	}
}

// --- sanitisation. -----------------------------------------------------------
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( (string) $str ) ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		return trim( wp_strip_all_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string, $remove_breaks = false ) {
		$string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $string );
		$string = strip_tags( $string );
		return trim( $string );
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$title = strtolower( trim( (string) $title ) );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
		return trim( $title, '-' );
	}
}
if ( ! function_exists( 'sanitize_hex_color' ) ) {
	function sanitize_hex_color( $color ) {
		return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', (string) $color ) ? (string) $color : null;
	}
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		return filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) ?: '';
	}
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) ?: false;
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $number ) {
		return abs( (int) $number );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
	}
}

// --- options and transients. --------------------------------------------------
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default_value = false ) {
		return EEX_Test_State::$options[ $name ] ?? $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		EEX_Test_State::$options[ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( EEX_Test_State::$options[ $name ] );
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return EEX_Test_State::$transients[ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		EEX_Test_State::$transients[ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( EEX_Test_State::$transients[ $key ] );
		return true;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
		if ( array_key_exists( $name, EEX_Test_State::$options ) ) {
			return false;
		}
		EEX_Test_State::$options[ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_trash_post' ) ) {
	function wp_trash_post( $post_id ) {
		if ( isset( EEX_Test_State::$posts[ (int) $post_id ] ) ) {
			EEX_Test_State::$posts[ (int) $post_id ]->post_status = 'trash';
			return EEX_Test_State::$posts[ (int) $post_id ];
		}
		return false;
	}
}
if ( ! function_exists( 'wp_delete_term' ) ) {
	function wp_delete_term( $term_id, $taxonomy ) {
		foreach ( EEX_Test_State::$terms[ $taxonomy ] ?? [] as $slug => $term ) {
			if ( (int) ( ( (array) $term )['term_id'] ?? 0 ) === (int) $term_id ) {
				unset( EEX_Test_State::$terms[ $taxonomy ][ $slug ] );
			}
		}
		return true;
	}
}

// --- hooks. -------------------------------------------------------------------
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		EEX_Test_State::$filters[ $hook ][ $priority ][] = [ $callback, $accepted_args ];
		return true;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $hook, $callback, $priority, $accepted_args );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		$callbacks = EEX_Test_State::$filters[ $hook ] ?? [];
		ksort( $callbacks );
		foreach ( $callbacks as $priority_group ) {
			foreach ( $priority_group as [ $callback, $accepted ] ) {
				$all   = array_merge( [ $value ], $args );
				$value = call_user_func_array( $callback, array_slice( $all, 0, max( 1, $accepted ) ) );
			}
		}
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		$GLOBALS['eex_test_actions'][ $hook ][] = $args;
		$callbacks                              = EEX_Test_State::$filters[ $hook ] ?? [];
		ksort( $callbacks );
		foreach ( $callbacks as $priority_group ) {
			foreach ( $priority_group as [ $callback, $accepted ] ) {
				call_user_func_array( $callback, array_slice( $args, 0, max( 0, $accepted ) ) );
			}
		}
	}
}
if ( ! function_exists( 'remove_all_filters' ) ) {
	function remove_all_filters( $hook ) {
		unset( EEX_Test_State::$filters[ $hook ] );
		return true;
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook ) {
		return count( $GLOBALS['eex_test_actions'][ $hook ] ?? [] );
	}
}

// --- HTTP. --------------------------------------------------------------------
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = [] ) {
		$pre = apply_filters( 'pre_http_request', false, $args, $url );
		if ( false !== $pre ) {
			return $pre;
		}
		return new WP_Error( 'http_request_failed', 'No HTTP mock installed for ' . $url );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return '';
		}
		return $response['response']['code'] ?? '';
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return '';
		}
		return $response['body'] ?? '';
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( ...$args ) {
		if ( 2 === count( $args ) && is_array( $args[0] ) ) {
			[ $params, $url ] = $args;
		} else {
			[ $key, $value, $url ] = array_pad( $args, 3, '' );
			$params                = [ $key => $value ];
		}
		$separator = str_contains( $url, '?' ) ? '&' : '?';
		return $url . $separator . http_build_query( $params );
	}
}

// --- misc. --------------------------------------------------------------------
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = [] ) {
		if ( is_object( $args ) ) {
			$args = get_object_vars( $args );
		}
		return array_merge( $defaults, (array) $args );
	}
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$out   = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}
		return $out;
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = [] ) {
		foreach ( EEX_Test_State::$scheduled as $event ) {
			if ( $event['hook'] === $hook ) {
				return $event['timestamp'];
			}
		}
		return false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = [] ) {
		EEX_Test_State::$scheduled[] = compact( 'timestamp', 'recurrence', 'hook', 'args' );
		return true;
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $timestamp, $hook, $args = [] ) {
		EEX_Test_State::$scheduled[] = compact( 'timestamp', 'hook', 'args' );
		return true;
	}
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook, $args = [] ) {
		EEX_Test_State::$scheduled = array_values(
			array_filter( EEX_Test_State::$scheduled, fn( $e ) => $e['hook'] !== $hook )
		);
		return 0;
	}
}
if ( ! function_exists( 'spawn_cron' ) ) {
	function spawn_cron() {
		return true;
	}
}
if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = [] ) {
		EEX_Test_State::$mail[] = compact( 'to', 'subject', 'message' );
		return true;
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) {
		return match ( $show ) {
			'admin_email' => 'admin@example.test',
			'name'        => 'Test Site',
			default       => 'Test Site',
		};
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://example.test' . $path;
	}
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() {
		return new DateTimeZone( EEX_Test_State::$options['timezone_string'] ?? 'UTC' );
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) {
		if ( 'timestamp' === $type ) {
			return time();
		}
		return gmdate( 'Y-m-d H:i:s' );
	}
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null, $timezone = null ) {
		$datetime = new DateTimeImmutable( '@' . ( $timestamp ?? time() ) );
		return $datetime->setTimezone( $timezone ?? wp_timezone() )->format( $format );
	}
}
if ( ! function_exists( 'load_plugin_textdomain' ) ) {
	function load_plugin_textdomain( $domain, $deprecated = false, $path = false ) {
		return true;
	}
}
if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}

// --- posts and meta. -----------------------------------------------------------
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $postarr, $wp_error = false ) {
		$id                              = EEX_Test_State::$next_post_id++;
		$post                            = (object) array_merge(
			[
				'ID'           => $id,
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => '',
				'post_content' => '',
				'post_excerpt' => '',
				'post_name'    => sanitize_title( $postarr['post_title'] ?? (string) $id ),
			],
			$postarr,
			[ 'ID' => $id ]
		);
		EEX_Test_State::$posts[ $id ]    = $post;
		EEX_Test_State::$post_write_count++;

		foreach ( (array) ( $postarr['meta_input'] ?? [] ) as $key => $value ) {
			EEX_Test_State::$post_meta[ $id ][ $key ] = $value;
		}
		return $id;
	}
}
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $postarr, $wp_error = false ) {
		$id = (int) ( $postarr['ID'] ?? 0 );
		if ( ! isset( EEX_Test_State::$posts[ $id ] ) ) {
			return 0;
		}
		foreach ( $postarr as $key => $value ) {
			if ( 'meta_input' === $key ) {
				foreach ( (array) $value as $mk => $mv ) {
					EEX_Test_State::$post_meta[ $id ][ $mk ] = $mv;
				}
				continue;
			}
			EEX_Test_State::$posts[ $id ]->$key = $value;
		}
		EEX_Test_State::$post_write_count++;
		return $id;
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post = null ) {
		if ( is_object( $post ) ) {
			return $post;
		}
		return EEX_Test_State::$posts[ (int) $post ] ?? null;
	}
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $post ) {
		$post = get_post( is_object( $post ) ? $post->ID : $post );
		return $post->post_status ?? false;
	}
}
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = [] ) {
		$results = [];
		foreach ( EEX_Test_State::$posts as $post ) {
			if ( isset( $args['post_type'] ) && 'any' !== $args['post_type'] ) {
				$types = (array) $args['post_type'];
				if ( ! in_array( $post->post_type, $types, true ) ) {
					continue;
				}
			}
			$status = $args['post_status'] ?? 'publish';
			if ( 'any' !== $status && ! in_array( $post->post_status, (array) $status, true ) ) {
				continue;
			}
			if ( isset( $args['meta_key'] ) ) {
				$meta = EEX_Test_State::$post_meta[ $post->ID ][ $args['meta_key'] ] ?? null;
				if ( isset( $args['meta_value'] ) && (string) $meta !== (string) $args['meta_value'] ) {
					continue;
				}
				if ( ! isset( $args['meta_value'] ) && null === $meta ) {
					continue;
				}
			}
			if ( ! empty( $args['exclude'] ) && in_array( $post->ID, array_map( 'intval', (array) $args['exclude'] ), true ) ) {
				continue;
			}
			$results[] = $post;
		}
		$limit = (int) ( $args['numberposts'] ?? $args['posts_per_page'] ?? -1 );
		if ( $limit > 0 ) {
			$results = array_slice( $results, 0, $limit );
		}
		if ( 'ids' === ( $args['fields'] ?? '' ) ) {
			return array_map( fn( $p ) => $p->ID, $results );
		}
		return $results;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		if ( '' === $key ) {
			return EEX_Test_State::$post_meta[ (int) $post_id ] ?? [];
		}
		$value = EEX_Test_State::$post_meta[ (int) $post_id ][ $key ] ?? null;
		if ( $single ) {
			return null === $value ? '' : $value;
		}
		return null === $value ? [] : [ $value ];
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		EEX_Test_State::$post_meta[ (int) $post_id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $key, $value = '' ) {
		unset( EEX_Test_State::$post_meta[ (int) $post_id ][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( $value ) {
		return $value;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post = 0 ) {
		$post = get_post( is_object( $post ) ? $post->ID : $post );
		return $post ? 'https://example.test/?p=' . $post->ID : false;
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post = 0 ) {
		$post = get_post( is_object( $post ) ? $post->ID : $post );
		return $post->post_title ?? '';
	}
}

// --- terms. ---------------------------------------------------------------------
if ( ! function_exists( 'term_exists' ) ) {
	function term_exists( $term, $taxonomy = '' ) {
		$slug = sanitize_title( (string) $term );
		if ( isset( EEX_Test_State::$terms[ $taxonomy ][ $slug ] ) ) {
			$found = EEX_Test_State::$terms[ $taxonomy ][ $slug ];
			return [ 'term_id' => $found['term_id'], 'term_taxonomy_id' => $found['term_id'] ];
		}
		return null;
	}
}
if ( ! function_exists( 'wp_insert_term' ) ) {
	function wp_insert_term( $term, $taxonomy, $args = [] ) {
		$slug = $args['slug'] ?? sanitize_title( (string) $term );
		if ( isset( EEX_Test_State::$terms[ $taxonomy ][ $slug ] ) ) {
			return new WP_Error( 'term_exists', 'Term exists' );
		}
		$id = EEX_Test_State::$next_term_id++;
		EEX_Test_State::$terms[ $taxonomy ][ $slug ] = [
			'term_id' => $id,
			'name'    => (string) $term,
			'slug'    => $slug,
		];
		return [ 'term_id' => $id, 'term_taxonomy_id' => $id ];
	}
}
if ( ! function_exists( 'wp_update_term' ) ) {
	function wp_update_term( $term_id, $taxonomy, $args = [] ) {
		foreach ( EEX_Test_State::$terms[ $taxonomy ] ?? [] as $slug => $term ) {
			if ( $term['term_id'] === (int) $term_id && isset( $args['name'] ) ) {
				EEX_Test_State::$terms[ $taxonomy ][ $slug ]['name'] = (string) $args['name'];
			}
		}
		return [ 'term_id' => (int) $term_id, 'term_taxonomy_id' => (int) $term_id ];
	}
}
if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( $args = [] ) {
		$taxonomy = is_array( $args ) ? ( (array) ( $args['taxonomy'] ?? [] ) )[0] ?? '' : (string) $args;
		$out      = [];
		foreach ( EEX_Test_State::$terms[ $taxonomy ] ?? [] as $term ) {
			$obj           = (object) $term;
			$obj->taxonomy = $taxonomy;
			$out[]         = $obj;
		}
		return $out;
	}
}
if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $field, $value, $taxonomy ) {
		foreach ( EEX_Test_State::$terms[ $taxonomy ] ?? [] as $term ) {
			if ( (string) ( $term[ $field ] ?? '' ) === (string) $value ) {
				$obj           = (object) $term;
				$obj->taxonomy = $taxonomy;
				return $obj;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'wp_set_object_terms' ) ) {
	function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
		$slugs = [];
		foreach ( (array) $terms as $term ) {
			if ( is_int( $term ) ) {
				foreach ( EEX_Test_State::$terms[ $taxonomy ] ?? [] as $t ) {
					if ( $t['term_id'] === $term ) {
						$slugs[] = $t['slug'];
					}
				}
			} else {
				$slugs[] = (string) $term;
			}
		}
		if ( $append ) {
			$slugs = array_unique( array_merge( EEX_Test_State::$object_terms[ (int) $object_id ][ $taxonomy ] ?? [], $slugs ) );
		}
		EEX_Test_State::$object_terms[ (int) $object_id ][ $taxonomy ] = $slugs;
		return $slugs;
	}
}
if ( ! function_exists( 'wp_get_object_terms' ) ) {
	function wp_get_object_terms( $object_ids, $taxonomy, $args = [] ) {
		$out = [];
		foreach ( (array) $object_ids as $object_id ) {
			foreach ( EEX_Test_State::$object_terms[ (int) $object_id ][ $taxonomy ] ?? [] as $slug ) {
				$term = get_term_by( 'slug', $slug, $taxonomy );
				if ( $term ) {
					$out[] = $term;
				}
			}
		}
		if ( 'slugs' === ( $args['fields'] ?? '' ) ) {
			return array_map( fn( $t ) => $t->slug, $out );
		}
		return $out;
	}
}
if ( ! function_exists( 'get_the_terms' ) ) {
	function get_the_terms( $post, $taxonomy ) {
		$post_id = is_object( $post ) ? $post->ID : (int) $post;
		$terms   = wp_get_object_terms( [ $post_id ], $taxonomy );
		return empty( $terms ) ? false : $terms;
	}
}

// --- registration no-ops and misc additions. ------------------------------------
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}
if ( ! function_exists( 'register_post_type' ) ) {
	function register_post_type( $post_type, $args = [] ) {
		$GLOBALS['eex_test_post_types'][ $post_type ] = $args;
		return (object) [ 'name' => $post_type ];
	}
}
if ( ! function_exists( 'register_taxonomy' ) ) {
	function register_taxonomy( $taxonomy, $object_type, $args = [] ) {
		$GLOBALS['eex_test_taxonomies'][ $taxonomy ] = $args;
		return true;
	}
}
if ( ! function_exists( 'register_post_meta' ) ) {
	function register_post_meta( $post_type, $meta_key, $args = [] ) {
		$GLOBALS['eex_test_registered_meta'][ $post_type ][ $meta_key ] = $args;
		return true;
	}
}
if ( ! function_exists( 'update_term_meta' ) ) {
	function update_term_meta( $term_id, $key, $value ) {
		$GLOBALS['eex_test_term_meta'][ (int) $term_id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_term_meta' ) ) {
	function get_term_meta( $term_id, $key = '', $single = false ) {
		$value = $GLOBALS['eex_test_term_meta'][ (int) $term_id ][ $key ] ?? null;
		return $single ? ( $value ?? '' ) : ( null === $value ? [] : [ $value ] );
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, ...$args ) {
		return true;
	}
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ) {
		return 1;
	}
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		return 'testnonce';
	}
}

// --- media. ---------------------------------------------------------------------
if ( ! function_exists( 'set_post_thumbnail' ) ) {
	function set_post_thumbnail( $post, $thumbnail_id ) {
		$post_id = is_object( $post ) ? $post->ID : (int) $post;
		EEX_Test_State::$post_meta[ $post_id ]['_thumbnail_id'] = (int) $thumbnail_id;
		return true;
	}
}
if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( $post = null ) {
		$post_id = is_object( $post ) ? $post->ID : (int) $post;
		return (int) ( EEX_Test_State::$post_meta[ $post_id ]['_thumbnail_id'] ?? 0 );
	}
}
if ( ! function_exists( 'media_sideload_image' ) ) {
	function media_sideload_image( $file, $post_id = 0, $desc = null, $return_type = 'html' ) {
		$id = wp_insert_post( [ 'post_type' => 'attachment', 'post_title' => (string) $desc, 'guid' => $file ] );
		$GLOBALS['eex_test_sideloads'][] = [ 'url' => $file, 'post' => $post_id, 'attachment' => $id ];
		return 'id' === $return_type ? $id : '<img src="' . $file . '" />';
	}
}

// --- templates, shortcodes, misc front-end. --------------------------------------
if ( ! function_exists( 'locate_template' ) ) {
	function locate_template( $template_names, $load = false, $load_once = true, $args = [] ) {
		return '';
	}
}
if ( ! function_exists( 'load_template' ) ) {
	function load_template( $_template_file, $load_once = true, $args = [] ) {
		include $_template_file;
	}
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals );
	}
}
if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( $tag, $callback ) {
		$GLOBALS['eex_test_shortcodes'][ $tag ] = $callback;
	}
}
if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( $query_var, $default_value = '' ) {
		return $GLOBALS['eex_test_query_vars'][ $query_var ] ?? $default_value;
	}
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $filename ) {
		return preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $filename );
	}
}
if ( ! function_exists( 'wpautop' ) ) {
	function wpautop( $text, $br = true ) {
		return '<p>' . str_replace( "\n\n", '</p><p>', (string) $text ) . '</p>';
	}
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = [], $ver = false, $media = 'all' ) {
		$GLOBALS['eex_test_enqueued'][] = $handle;
	}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = [], $ver = false, $args = false ) {
		$GLOBALS['eex_test_enqueued'][] = $handle;
	}
}

// --- REST. ------------------------------------------------------------------------
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $route_namespace, $route, $args = [], $override = false ) {
		$GLOBALS['eex_test_rest_routes'][ $route_namespace . $route ] = $args;
		return true;
	}
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
	#[\AllowDynamicProperties]
	class WP_REST_Request implements ArrayAccess {
		private array $params;
		private array $json;

		public function __construct( array $params = [], array $json = [] ) {
			$this->params = $params;
			$this->json   = $json;
		}

		public function get_json_params() {
			return $this->json;
		}

		#[\ReturnTypeWillChange]
		public function offsetExists( $offset ): bool {
			return isset( $this->params[ $offset ] );
		}

		#[\ReturnTypeWillChange]
		public function offsetGet( $offset ) {
			return $this->params[ $offset ] ?? null;
		}

		#[\ReturnTypeWillChange]
		public function offsetSet( $offset, $value ): void {
			$this->params[ $offset ] = $value;
		}

		#[\ReturnTypeWillChange]
		public function offsetUnset( $offset ): void {
			unset( $this->params[ $offset ] );
		}
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public int $status;
		public array $headers = [];

		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function header( $key, $value ) {
			$this->headers[ $key ] = $value;
		}

		public function get_status(): int {
			return $this->status;
		}

		public function get_data() {
			return $this->data;
		}
	}
}
if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers() {}
}

// --- boot smoke support. -----------------------------------------------------------
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}
if ( ! function_exists( 'wp_register_style' ) ) {
	function wp_register_style( $handle, $src = '', $deps = [], $ver = false, $media = 'all' ) {
		return true;
	}
}
if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( $handle, $src = '', $deps = [], $ver = false, $args = false ) {
		return true;
	}
}
if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $object_name, $l10n ) {
		return true;
	}
}
if ( ! function_exists( 'wp_add_inline_style' ) ) {
	function wp_add_inline_style( $handle, $data ) {
		return true;
	}
}

// --- schema management. -------------------------------------------------------------
if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( $queries = '', $execute = true ) {
		global $wpdb;
		foreach ( (array) $queries as $sql ) {
			if ( preg_match( '/CREATE TABLE (\S+)/i', (string) $sql, $m ) ) {
				$wpdb->tables[ $m[1] ] = $wpdb->tables[ $m[1] ] ?? [];
				$GLOBALS['eex_test_dbdelta'][] = $m[1];
			}
		}
		return [];
	}
}
if ( ! function_exists( 'flush_rewrite_rules' ) ) {
	function flush_rewrite_rules( $hard = true ) {
		$GLOBALS['eex_test_rewrites_flushed'] = true;
	}
}

// --- theme / taxonomy helpers for module detection. ----------------------------------
if ( ! function_exists( 'get_object_taxonomies' ) ) {
	function get_object_taxonomies( $object_type, $output = 'names' ) {
		return $GLOBALS['eex_test_object_taxonomies'][ is_string( $object_type ) ? $object_type : '' ] ?? [];
	}
}
if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme( $stylesheet = '', $theme_root = '' ) {
		return new class() {
			public function get( $header ) {
				return 'test-theme-1.0';
			}
		};
	}
}
if ( ! function_exists( 'is_singular' ) ) {
	function is_singular( $post_types = '' ) {
		return false;
	}
}

// --- HTTP write methods. --------------------------------------------------------------
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = [] ) {
		$args['method'] = 'POST';
		$pre            = apply_filters( 'pre_http_request', false, $args, $url );
		if ( false !== $pre ) {
			return $pre;
		}
		return new WP_Error( 'http_request_failed', 'No HTTP mock installed for POST ' . $url );
	}
}
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = [] ) {
		$pre = apply_filters( 'pre_http_request', false, $args, $url );
		if ( false !== $pre ) {
			return $pre;
		}
		return new WP_Error( 'http_request_failed', 'No HTTP mock installed for ' . ( $args['method'] ?? 'GET' ) . ' ' . $url );
	}
}
if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen() {
		return $GLOBALS['eex_test_current_screen'] ?? null;
	}
}

// --- queried object / dashboard / archive links. ---------------------------------------
if ( ! function_exists( 'get_queried_object_id' ) ) {
	function get_queried_object_id() {
		return (int) ( $GLOBALS['eex_test_queried_object_id'] ?? 0 );
	}
}
if ( ! function_exists( 'get_queried_object' ) ) {
	function get_queried_object() {
		return get_post( get_queried_object_id() );
	}
}
if ( ! function_exists( 'get_post_type_archive_link' ) ) {
	function get_post_type_archive_link( $post_type ) {
		return 'https://example.test/' . $post_type . '/';
	}
}
if ( ! function_exists( 'wp_add_dashboard_widget' ) ) {
	function wp_add_dashboard_widget( $widget_id, $widget_name, $callback, $control_callback = null, $callback_args = null ) {
		$GLOBALS['eex_test_dashboard_widgets'][ $widget_id ] = $callback;
	}
}
if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( $term, $taxonomy = '' ) {
		$slug = is_object( $term ) ? $term->slug : (string) $term;
		return 'https://example.test/term/' . $slug . '/';
	}
}

// --- users. --------------------------------------------------------------------------
if ( ! class_exists( 'WP_User' ) ) {
	#[\AllowDynamicProperties]
	class WP_User {
		public int $ID;
		public string $user_login;
		public string $user_email;
		public string $display_name;
		public array $roles;

		public function __construct( int $id, string $login = '', string $email = '', array $roles = [], string $display_name = '' ) {
			$this->ID           = $id;
			$this->user_login   = $login;
			$this->user_email   = $email;
			$this->roles        = $roles;
			$this->display_name = $display_name ?: $login;
		}
	}
}
if ( ! function_exists( 'eex_test_create_user' ) ) {
	function eex_test_create_user( string $login, string $email, array $roles = [ 'subscriber' ] ): int {
		$id = count( $GLOBALS['eex_test_users'] ?? [] ) + 1;
		$GLOBALS['eex_test_users'][ $id ] = new WP_User( $id, $login, $email, $roles );
		return $id;
	}
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) {
		return $GLOBALS['eex_test_users'][ (int) $user_id ] ?? false;
	}
}
if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( $field, $value ) {
		foreach ( $GLOBALS['eex_test_users'] ?? [] as $user ) {
			if ( 'email' === $field && strtolower( $user->user_email ) === strtolower( (string) $value ) ) {
				return $user;
			}
			if ( 'login' === $field && $user->user_login === (string) $value ) {
				return $user;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'get_users' ) ) {
	function get_users( $args = [] ) {
		$users = array_values( $GLOBALS['eex_test_users'] ?? [] );
		if ( 'ID' === ( $args['fields'] ?? '' ) ) {
			return array_map( fn( $u ) => $u->ID, $users );
		}
		return $users;
	}
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key = '', $single = false ) {
		$value = $GLOBALS['eex_test_user_meta'][ (int) $user_id ][ $key ] ?? null;
		if ( $single ) {
			return null === $value ? '' : $value;
		}
		return null === $value ? [] : [ $value ];
	}
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $key, $value ) {
		$GLOBALS['eex_test_user_meta'][ (int) $user_id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_user_meta' ) ) {
	function delete_user_meta( $user_id, $key, $value = '' ) {
		unset( $GLOBALS['eex_test_user_meta'][ (int) $user_id ][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		return $GLOBALS['eex_test_users'][1] ?? new WP_User( 0, 'anonymous' );
	}
}

if ( ! function_exists( '__return_true' ) ) {
	function __return_true() {
		return true;
	}
}
if ( ! function_exists( '__return_false' ) ) {
	function __return_false() {
		return false;
	}
}
