<?php
/**
 * Action Scheduler function stubs.
 *
 * Action Scheduler is not a dependency of Hyve; it is used only when another
 * plugin on the site provides it. These stubs let PHPStan resolve the optional
 * `as_*` calls in `Scheduler`.
 *
 * @package Codeinwp\HyveLite
 *
 * phpcs:ignoreFile
 */

if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	/**
	 * @param string            $hook
	 * @param array<int, mixed> $args
	 * @param string            $group
	 * @param bool              $unique
	 * @param int               $priority
	 *
	 * @return int
	 */
	function as_enqueue_async_action( $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
		return 0;
	}
}

if ( ! function_exists( 'as_schedule_single_action' ) ) {
	/**
	 * @param int               $timestamp
	 * @param string            $hook
	 * @param array<int, mixed> $args
	 * @param string            $group
	 * @param bool              $unique
	 * @param int               $priority
	 *
	 * @return int
	 */
	function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
		return 0;
	}
}

if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
	/**
	 * @param int               $timestamp
	 * @param int               $interval_in_seconds
	 * @param string            $hook
	 * @param array<int, mixed> $args
	 * @param string            $group
	 * @param bool              $unique
	 * @param int               $priority
	 *
	 * @return int
	 */
	function as_schedule_recurring_action( $timestamp, $interval_in_seconds, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
		return 0;
	}
}

if ( ! function_exists( 'as_has_scheduled_action' ) ) {
	/**
	 * @param string                 $hook
	 * @param array<int, mixed>|null $args
	 * @param string                 $group
	 *
	 * @return bool
	 */
	function as_has_scheduled_action( $hook, $args = null, $group = '' ) {
		return false;
	}
}
