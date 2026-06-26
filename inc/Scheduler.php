<?php
/**
 * Scheduler Class.
 *
 * @package Codeinwp\HyveLite
 */

namespace ThemeIsle\HyveLite;

/**
 * Schedules background jobs through Action Scheduler when it is available,
 * falling back to WP-Cron otherwise.
 *
 * Action Scheduler is not bundled with Hyve. When another plugin on the site
 * provides it (e.g. WooCommerce), Hyve's background work runs on it for better
 * reliability and visibility; otherwise the WP-Cron behaviour is preserved.
 *
 * @since 1.3.4
 */
class Scheduler {

	/**
	 * Action Scheduler group for Hyve actions.
	 *
	 * @since 1.3.4
	 * @var string
	 */
	const GROUP = 'hyve';

	/**
	 * Schedule a job to run as soon as possible.
	 *
	 * Replaces `wp_schedule_single_event( time(), ... )`.
	 *
	 * @since 1.3.4
	 *
	 * @param string      $hook The hook to fire.
	 * @param list<mixed> $args Arguments to pass to the hook.
	 *
	 * @return int|bool Action ID when using Action Scheduler, otherwise the WP-Cron result.
	 */
	public static function enqueue_async( $hook, $args = [] ) {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			return as_enqueue_async_action( $hook, $args, self::GROUP );
		}

		return wp_schedule_single_event( time(), $hook, $args );
	}

	/**
	 * Schedule a job to run once at a given time.
	 *
	 * Replaces `wp_schedule_single_event( time() + $delay, ... )`.
	 *
	 * @since 1.3.4
	 *
	 * @param int         $timestamp Unix timestamp when the job should run.
	 * @param string      $hook      The hook to fire.
	 * @param list<mixed> $args      Arguments to pass to the hook.
	 *
	 * @return int|bool Action ID when using Action Scheduler, otherwise the WP-Cron result.
	 */
	public static function schedule_single( $timestamp, $hook, $args = [] ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			return as_schedule_single_action( $timestamp, $hook, $args, self::GROUP );
		}

		return wp_schedule_single_event( $timestamp, $hook, $args );
	}

	/**
	 * Ensure a recurring job is scheduled exactly once.
	 *
	 * Replaces the `wp_next_scheduled()` guard around `wp_schedule_event()`.
	 *
	 * Self-heals if Action Scheduler later appears or disappears: when it is
	 * available, any legacy WP-Cron entry is removed and the job runs on Action
	 * Scheduler; otherwise it runs on WP-Cron.
	 *
	 * @since 1.3.4
	 *
	 * @param string      $hook             The hook to fire.
	 * @param int         $interval_seconds Interval between runs, in seconds (Action Scheduler).
	 * @param string      $recurrence       WP-Cron schedule name used for the fallback (e.g. `hourly`).
	 * @param list<mixed> $args             Arguments to pass to the hook.
	 *
	 * @return void
	 */
	public static function ensure_recurring( $hook, $interval_seconds, $recurrence, $args = [] ) {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( wp_next_scheduled( $hook, $args ) ) {
				wp_clear_scheduled_hook( $hook, $args );
			}

			if ( ! as_has_scheduled_action( $hook, ! empty( $args ) ? $args : null, self::GROUP ) ) {
				as_schedule_recurring_action( time(), $interval_seconds, $hook, $args, self::GROUP );
			}

			return;
		}

		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_event( time(), $recurrence, $hook, $args );
		}
	}
}
