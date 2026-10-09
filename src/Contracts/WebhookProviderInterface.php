<?php
namespace EvoMembers\Contracts;

use WP_REST_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

interface WebhookProviderInterface {
    public function slug(): string;
    public function verify( WP_REST_Request $request, array $integration ): bool|WP_Error;
    public function normalize( WP_REST_Request $request, array $integration ): array|WP_Error;
}
