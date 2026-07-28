<?php
/**
 * Admin-post controller for queue actions: Process Queue Now and Retry.
 *
 * Same pattern as OAuth_Controller: nonce + capability gated actions that do
 * their work and redirect back to the Sync Log tab with a result notice.
 *
 * @package WTG
 */

namespace WTG\Admin;

use WTG\Queue\Sync_Queue;
use WTG\Queue\Sync_Processor;

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Queue_Controller
 */
class Queue_Controller {

	/**
	 * admin-post action: drain the queue immediately.
	 */
	const ACTION_PROCESS_NOW = 'wtg_process_now';

	/**
	 * admin-post action: reset one failed row back to pending.
	 */
	const ACTION_RETRY = 'wtg_retry_row';

	/**
	 * admin-post action: delete finished rows from the log.
	 */
	const ACTION_CLEAR_LOG = 'wtg_clear_log';

	/**
	 * Register the handlers.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_post_' . self::ACTION_PROCESS_NOW, array( $this, 'handle_process_now' ) );
		add_action( 'admin_post_' . self::ACTION_RETRY, array( $this, 'handle_retry' ) );
		add_action( 'admin_post_' . self::ACTION_CLEAR_LOG, array( $this, 'handle_clear_log' ) );
	}

	/**
	 * Nonced URL for the Process Queue Now button.
	 *
	 * @return string
	 */
	public static function process_now_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_PROCESS_NOW ), self::ACTION_PROCESS_NOW );
	}

	/**
	 * Nonced URL for a row's Retry link.
	 *
	 * @param int $row_id Queue row ID.
	 * @return string
	 */
	public static function retry_url( $row_id ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_RETRY . '&row=' . (int) $row_id ),
			self::ACTION_RETRY
		);
	}

	/**
	 * Nonced URL for the Clear Log button.
	 *
	 * @return string
	 */
	public static function clear_log_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_CLEAR_LOG ), self::ACTION_CLEAR_LOG );
	}

	/**
	 * Delete finished rows from the log.
	 *
	 * Only success and failed rows go. Pending and processing rows are orders
	 * that have NOT reached the sheet yet — deleting those would lose them
	 * silently, which is exactly the kind of quiet data loss this plugin tries
	 * to avoid elsewhere.
	 *
	 * @return void
	 */
	public function handle_clear_log() {
		$this->authorize( self::ACTION_CLEAR_LOG );

		$deleted = Sync_Queue::clear(
			array( Sync_Queue::STATUS_SUCCESS, Sync_Queue::STATUS_FAILED )
		);

		$this->set_notice(
			'success',
			sprintf(
				/* translators: %d: number of log rows removed. */
				_n( 'Cleared %d entry from the log.', 'Cleared %d entries from the log.', $deleted, 'woo-to-gsheet' ),
				$deleted
			)
		);

		$this->redirect_to_log();
	}

	/**
	 * Run the processor now and report counts.
	 *
	 * @return void
	 */
	public function handle_process_now() {
		$this->authorize( self::ACTION_PROCESS_NOW );

		$counts = ( new Sync_Processor() )->process();

		$this->set_notice(
			'success',
			sprintf(
				/* translators: 1: processed, 2: succeeded, 3: retry, 4: failed. */
				__( 'Queue processed: %1$d attempted, %2$d succeeded, %3$d to retry, %4$d failed.', 'woo-to-gsheet' ),
				$counts['processed'],
				$counts['success'],
				$counts['retry'],
				$counts['failed']
			)
		);

		$this->redirect_to_log();
	}

	/**
	 * Reset a single failed row to pending so it is retried next run.
	 *
	 * @return void
	 */
	public function handle_retry() {
		$this->authorize( self::ACTION_RETRY );

		$row_id = isset( $_REQUEST['row'] ) ? (int) $_REQUEST['row'] : 0;
		if ( $row_id > 0 ) {
			Sync_Queue::reset_for_retry( $row_id );
			$this->set_notice( 'success', __( 'Row reset to pending; it will be retried on the next run.', 'woo-to-gsheet' ) );
		} else {
			$this->set_notice( 'error', __( 'Invalid row.', 'woo-to-gsheet' ) );
		}

		$this->redirect_to_log();
	}

	/* ---------------------------------------------------------------------- */

	/**
	 * Capability + nonce gate.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private function authorize( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'woo-to-gsheet' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Stash a one-time notice (rendered by OAuth_Controller::render_notice,
	 * which reads this same per-user transient key).
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Message.
	 * @return void
	 */
	private function set_notice( $type, $message ) {
		set_transient(
			'wtg_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
	}

	/**
	 * Redirect to the Sync Log tab and stop.
	 *
	 * @return void
	 */
	private function redirect_to_log() {
		// Settings_Page::url() owns the parent file — see the note there.
		wp_safe_redirect( Settings_Page::url( 'sync_log' ) );
		exit;
	}
}
