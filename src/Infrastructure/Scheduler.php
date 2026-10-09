<?php
namespace EvoMembers\Infrastructure;

use EvoMembers\Contracts\SchedulerInterface;

defined( 'ABSPATH' ) || exit;

final class Scheduler implements SchedulerInterface {
    public function single( int $timestamp, string $hook, array $args = array(), string $group = 'evoxup-membership' ): bool {
        $timestamp = max( time(), $timestamp );
        if ( function_exists( 'as_schedule_single_action' ) ) {
            return (int) as_schedule_single_action( $timestamp, $hook, $args, $group, true ) > 0;
        }
        if ( wp_next_scheduled( $hook, $args ) ) {
            return true;
        }
        return (bool) wp_schedule_single_event( $timestamp, $hook, $args );
    }

    public function unschedule( string $hook, array $args = array(), string $group = 'evoxup-membership' ): void {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( $hook, $args, $group );
            return;
        }
        wp_clear_scheduled_hook( $hook, $args );
    }
}
