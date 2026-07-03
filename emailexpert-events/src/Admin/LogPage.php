<?php
/**
 * Sync log viewer.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Paginated view of the eex_log table, filterable by context and level.
 */
final class LogPage {

	private const PER_PAGE = 50;

	/**
	 * Render the log screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}

		global $wpdb;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$context = isset( $_GET['context'] ) ? sanitize_key( $_GET['context'] ) : '';
		$level   = isset( $_GET['level'] ) ? sanitize_key( $_GET['level'] ) : '';
		$paged   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$where  = ' WHERE 1=1';
		$params = [];

		if ( in_array( $context, [ 'sync', 'webhook', 'api' ], true ) ) {
			$where   .= ' AND context = %s';
			$params[] = $context;
		}

		if ( in_array( $level, [ 'info', 'warning', 'error' ], true ) ) {
			$where   .= ' AND level = %s';
			$params[] = $level;
		}

		$offset   = ( $paged - 1 ) * self::PER_PAGE;
		$params[] = self::PER_PAGE;
		$params[] = $offset;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery -- custom table; $where is built from fixed strings and placeholders only.
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}eex_log{$where} ORDER BY id DESC LIMIT %d OFFSET %d", $params ),
			ARRAY_A
		);
		$total = (int) $wpdb->get_var(
			empty( array_slice( $params, 0, -2 ) )
				? "SELECT COUNT(*) FROM {$wpdb->prefix}eex_log"
				: $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}eex_log{$where}", array_slice( $params, 0, -2 ) )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery

		$base_url = admin_url( 'options-general.php?page=emailexpert-events-log' );
		?>
		<div class="wrap eex-log">
			<h1><?php esc_html_e( 'emailexpert Events sync log', 'emailexpert-events' ); ?></h1>

			<form method="get">
				<input type="hidden" name="page" value="emailexpert-events-log" />
				<select name="context">
					<option value=""><?php esc_html_e( 'All contexts', 'emailexpert-events' ); ?></option>
					<?php foreach ( [ 'sync', 'webhook', 'api' ] as $option ) : ?>
						<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $context, $option ); ?>><?php echo esc_html( $option ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="level">
					<option value=""><?php esc_html_e( 'All levels', 'emailexpert-events' ); ?></option>
					<?php foreach ( [ 'info', 'warning', 'error' ] as $option ) : ?>
						<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $level, $option ); ?>><?php echo esc_html( $option ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Filter', 'emailexpert-events' ), 'secondary', '', false ); ?>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'emailexpert-events' ); ?></th>
						<th><?php esc_html_e( 'Time (UTC)', 'emailexpert-events' ); ?></th>
						<th><?php esc_html_e( 'Context', 'emailexpert-events' ); ?></th>
						<th><?php esc_html_e( 'Level', 'emailexpert-events' ); ?></th>
						<th><?php esc_html_e( 'Message', 'emailexpert-events' ); ?></th>
						<th><?php esc_html_e( 'Data', 'emailexpert-events' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No log entries match.', 'emailexpert-events' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( (array) $rows as $row ) : ?>
						<tr>
							<td><?php echo (int) $row['id']; ?></td>
							<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
							<td><?php echo esc_html( (string) $row['context'] ); ?></td>
							<td><?php echo esc_html( (string) $row['level'] ); ?></td>
							<td><?php echo esc_html( (string) $row['message'] ); ?></td>
							<td>
								<?php if ( ! empty( $row['data'] ) ) : ?>
									<details><summary><?php esc_html_e( 'View', 'emailexpert-events' ); ?></summary><pre><?php echo esc_html( (string) $row['data'] ); ?></pre></details>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$pages = (int) ceil( $total / self::PER_PAGE );
			if ( $pages > 1 ) {
				echo '<p class="eex-pagination">';
				for ( $i = 1; $i <= $pages; $i++ ) {
					$url = add_query_arg(
						array_filter(
							[
								'context' => $context,
								'level'   => $level,
								'paged'   => $i,
							]
						),
						$base_url
					);
					printf(
						'<a href="%s" %s>%d</a> ',
						esc_url( $url ),
						$i === $paged ? 'aria-current="page" class="current"' : '',
						(int) $i
					);
				}
				echo '</p>';
			}
			?>
		</div>
		<?php
	}
}
