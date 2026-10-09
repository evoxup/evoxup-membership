<?php
namespace EvoMembers\SDK;

use EvoMembers\Contracts\Api\EventApiInterface;
use EvoMembers\Services\EventBus;

defined( 'ABSPATH' ) || exit;

final class EventApi implements EventApiInterface {
    public function __construct( private readonly string $extension_id ) {}
    public function register( string $event, array $definition = array() ): bool { return EventCatalog::register( $event, $definition, $this->extension_id ); }
    public function definition( string $event ): ?array { return EventCatalog::definition( $event ); }
    public function catalog(): array { return EventCatalog::all(); }
    public function validate( string $event, array $payload ): true|\WP_Error { return EventCatalog::validate( $event, $payload ); }
    public function subscribe( string $event, callable $callback, int $priority = 10 ): bool {
        $parts = array_values( array_filter( array_map( 'sanitize_key', explode( '.', strtolower( trim( $event ) ) ) ) ) );
        if ( ! $parts ) { return false; }
        add_action( 'evomembers_event_' . implode( '_', $parts ), $callback, $priority, 4 );
        return true;
    }
    public function emit( string $event, array $payload = array(), string $aggregate_type = '', int $aggregate_id = 0, int $customer_id = 0 ): int|\WP_Error {
        $valid = EventCatalog::validate( $event, $payload );
        if ( is_wp_error( $valid ) ) { return $valid; }
        return EventBus::emit( $event, $payload, $aggregate_type, $aggregate_id, $customer_id, 'extension_' . sanitize_key( $this->extension_id ) );
    }
    public function recent( int $limit = 100 ): array { return EventBus::recent( $limit ); }
    public function for_customer( int $customer_id, int $limit = 100 ): array { return EventBus::for_customer( $customer_id, $limit ); }
}
