<?php
/**
 * Attribution report screen.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Table of attribution rows with totals by utm_source and by status,
 * filterable by event and date range, with nonce-protected CSV export.
 */
final class AttributionReport {

	private const PER_PAGE = 100;

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_post_eex_export_attribution', [ $this, 'export_csv' ] );
	}

	/**
	 * Add the report page.
	 */
	public function add_menu(): void {
		add_submenu_page(
			'options-general.php',
			__( 'emailexpert Events attribution', 'emailexpert-events' ),
			__( 'EEX Attribution', 'emailexpert-events' ),
			'manage_options',
			'emailexpert-events-attribution',
			[ $this, 'render' ]
		);
	}

	/**
	 * Current filters from the query string.
	 *
	 * @return array{event:string,from:string,to:string,paged:int}
	 */
	private function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		return [
			'event' => isset( $_GET['event'] ) ? sanitize_text_field( wp_unslash( $_GET['event'] ) ) : '',
			'from'  => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
			'to'    => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
			'paged' => isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1,
		];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Fetch rows for the current filters.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @param int                 $limit   Limit (0 = all, for export).
	 * @return array<int,array<string,mixed>>
	 */
	private function rows( array $filters, int $limit = self::PER_PAGE ): array {
		global $wpdb;

		$where  = ' WHERE 1=1';
		$params = [];

		if ( '' !== $filters['event'] ) {
			$where   .= ' AND event_hs_id = %s';
			$params[] = $filters['event'];
		}
		if ( '' !== $filters['from'] && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['from'] ) ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $filters['from'] . ' 00:00:00';
		}
		if ( '' !== $filters['to'] && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['to'] ) ) {
			$where   .= ' AND created_at <= %s';
			$params[] = $filters['to'] . ' 23:59:59';
		}

		$sql = "SELECT * FROM {$wpdb->prefix}eex_attribution{$where} ORDER BY id DESC";

		if ( $limit > 0 ) {
			$offset   = ( (int) $filters['paged'] - 1 ) * $limit;
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $limit;
			$params[] = $offset;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery -- custom table; $where built from fixed fragments and placeholders only.
		return (array) $wpdb->get_results(
			empty( $params ) ? $sql : $wpdb->prepare( $sql, $params ),
			ARRAY_A
		);
		// phpcs:enable
	}

	/**
	 * Render the report.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}

		$filters  = $this->filters();
		$rows     = $this->rows( $filters );
		$all_rows = $this->rows( $filters, 0 );

		$by_source = [];
		$by_status = [];
		foreach ( $all_rows as $row ) {
			$source = (string) ( $row['utm_source'] ?: '(none)' );
			$status = (string) $row['status'];

			$by_source[ $source ] = ( $by_source[ $source ] ?? 0 ) + 1;
			$by_status[ $status ] = ( $by_status[ $status ] ?? 0 ) + 1;
		}
		arsort( $by_source );

		$export_url = wp_nonce_url(
			add_query_arg(
				array_filter(
					[
						'action' => 'eex_export_attribution',
						'event'  => $filters['event'],
						'from'   => $filters['from'],
						'to'     => $filters['to'],
					]
				),
				admin_url( 'admin-post.php' )
			),
			'eex_export_attribution'
		);
		?>
		<div class="wrap eex-attribution">
			<h1><?php esc_html_e( 'Registration attribution', 'emailexpert-events' ); ?></h1>

			<form method="get">
				<input type="hidden" name="page" value="emailexpert-events-attribution" />
				<label><?php esc_html_e( 'Event ID', 'emailexpert-events' ); ?> <input type="text" name="event" value="<?php echo esc_attr( $filters['event'] ); ?>" size="8" /></label>
				<label><?php esc_html_e( 'From', 'emailexpert-events' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>" /></label>
				<label><?php esc_html_e( 'To', 'emailexpert-events' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>" /></label>
				<?php submit_button( __( 'Filter', 'emailexpert-events' ), 'secondary', '', false ); ?>
				<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'emailexpert-events' ); ?></a>
			</form>

			<h2><?php esc_html_e( 'Totals', 'emailexpert-events' ); ?></h2>
			<table class="widefat striped" style="max-width:480px">
				<thead><tr><th><?php esc_html_e( 'Status', 'emailexpert-events' ); ?></th><th><?php esc_html_e( 'Count', 'emailexpert-events' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $by_status as $status => $count ) : ?>
						<tr><td><?php echo esc_html( (string) $status ); ?></td><td><?php echo (int) $count; ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<table class="widefat striped" style="max-width:480px;margin-top:8px">
				<thead><tr><th><?php esc_html_e( 'UTM source', 'emailexpert-events' ); ?></th><th><?php esc_html_e( 'Count', 'emailexpert-events' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $by_source as $source => $count ) : ?>
						<tr><td><?php echo esc_html( (string) $source ); ?></td><td><?php echo (int) $count; ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Rows', 'emailexpert-events' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time (UTC)', 'emailexpert-events' ); ?></th>
						<th><?php esc_html_e( 'Event', 'emailexpert-events' ); ?></th>
						<th><?php esc_html_e( 'Status', 'emailexpert-events' ); ?></th>
						<th>utm_source</th>
						<th>utm_medium</th>
						<th>utm_campaign</th>
						<th><?php esc_html_e( 'Referrer', 'emailexpert-events' ); ?></th>
						<th><?php esc_html_e( 'Ticket', 'emailexpert-events' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'emailexpert-events' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="9"><?php esc_html_e( 'No attribution rows match.', 'emailexpert-events' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
							<td><?php echo esc_html( (string) $row['event_hs_id'] ); ?></td>
							<td><?php echo esc_html( (string) $row['status'] ); ?></td>
							<td><?php echo esc_html( (string) $row['utm_source'] ); ?></td>
							<td><?php echo esc_html( (string) $row['utm_medium'] ); ?></td>
							<td><?php echo esc_html( (string) $row['utm_campaign'] ); ?></td>
							<td><?php echo esc_html( (string) $row['referer_domain'] ); ?></td>
							<td><?php echo esc_html( (string) $row['ticket_name'] ); ?></td>
							<td><?php echo esc_html( (string) $row['amount_gross'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Stream the filtered rows as CSV.
	 */
	public function export_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}

		check_admin_referer( 'eex_export_attribution' );

		$filters = $this->filters();
		$rows    = $this->rows( $filters, 0 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="eex-attribution.csv"' );

		$columns = [ 'created_at', 'event_hs_id', 'attendee_hs_id', 'email_hash', 'status', 'utm_source', 'utm_medium', 'utm_campaign', 'referer_domain', 'affiliate_email', 'ticket_name', 'amount_gross' ];

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, $columns );
		foreach ( $rows as $row ) {
			$line = [];
			foreach ( $columns as $column ) {
				$line[] = (string) ( $row[ $column ] ?? '' );
			}
			fputcsv( $out, $line );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming CSV to output.

		exit;
	}
}
