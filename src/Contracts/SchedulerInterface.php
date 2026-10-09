<?php
namespace EvoMembers\Contracts;

defined( 'ABSPATH' ) || exit;

interface SchedulerInterface {
    public function single( int $timestamp, string $hook, array $args = array(), string $group = 'evoxup-membership' ): bool;
    public function unschedule( string $hook, array $args = array(), string $group = 'evoxup-membership' ): void;
}
