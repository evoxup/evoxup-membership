<?php
namespace EvoMembers\Core;

defined( 'ABSPATH' ) || exit;

final class ModuleCompatibility {
    public function check( array $manifest ): array {
        $errors = array();
        $warnings = array();
        $id = sanitize_key( (string) ( $manifest['id'] ?? '' ) );
        if ( '' === $id || $id !== (string) ( $manifest['id'] ?? '' ) ) {
            $errors[] = __( 'Extension ID must contain only lowercase letters, numbers, hyphens or underscores.', 'evoxup-membership' );
        }
        if ( empty( $manifest['name'] ) || empty( $manifest['version'] ) || empty( $manifest['bootstrap'] ) ) {
            $errors[] = __( 'The extension manifest is missing required fields: name, version or bootstrap.', 'evoxup-membership' );
        }
        if ( ! empty( $manifest['version'] ) && ! $this->is_version( (string) $manifest['version'] ) ) {
            $errors[] = __( 'Extension version must use a semantic numeric version such as 1.2.3.', 'evoxup-membership' );
        }
        $bootstrap = (string) ( $manifest['bootstrap'] ?? '' );
        if ( '' !== $bootstrap && ( basename( $bootstrap ) !== $bootstrap || str_contains( $bootstrap, '..' ) ) ) {
            $errors[] = __( 'Extension bootstrap must be a file in the extension root.', 'evoxup-membership' );
        }
        if ( 'extension' === (string) ( $manifest['extension_format'] ?? '' ) && empty( $manifest['class'] ) ) {
            $errors[] = __( 'First-class extensions must declare a bootstrap class.', 'evoxup-membership' );
        }
        if ( ! empty( $manifest['requires_php'] ) && version_compare( PHP_VERSION, (string) $manifest['requires_php'], '<' ) ) {
            /* translators: %s: required PHP version. */
            $errors[] = sprintf( __( 'Requires PHP %s or newer.', 'evoxup-membership' ), $manifest['requires_php'] );
        }
        if ( ! empty( $manifest['requires_wp'] ) && version_compare( get_bloginfo( 'version' ), (string) $manifest['requires_wp'], '<' ) ) {
            /* translators: %s: required WordPress version. */
            $errors[] = sprintf( __( 'Requires WordPress %s or newer.', 'evoxup-membership' ), $manifest['requires_wp'] );
        }
        if ( ! empty( $manifest['requires_evo'] ) && version_compare( EVOMEMBERS_VERSION, (string) $manifest['requires_evo'], '<' ) ) {
            /* translators: %s: required EVO version. */
            $errors[] = sprintf( __( 'Requires EVO %s or newer.', 'evoxup-membership' ), $manifest['requires_evo'] );
        }
        if ( ! empty( $manifest['requires_extension_api'] ) && version_compare( EVOMEMBERS_EXTENSION_API_VERSION, (string) $manifest['requires_extension_api'], '<' ) ) {
            /* translators: %s: required EVO Extension API version. */
            $errors[] = sprintf( __( 'Requires EVO Extension API %s or newer.', 'evoxup-membership' ), $manifest['requires_extension_api'] );
        }
        if ( ! empty( $manifest['max_extension_api'] ) && version_compare( EVOMEMBERS_EXTENSION_API_VERSION, (string) $manifest['max_extension_api'], '>' ) ) {
            /* translators: %s: maximum supported EVO Extension API version. */
            $errors[] = sprintf( __( 'Supports EVO Extension API only up to %s.', 'evoxup-membership' ), $manifest['max_extension_api'] );
        }
        if ( ! empty( $manifest['tested_extension_api'] ) && version_compare( EVOMEMBERS_EXTENSION_API_VERSION, (string) $manifest['tested_extension_api'], '>' ) ) {
            $warnings[] = __( 'This extension has not declared testing against the installed EVO Extension API version.', 'evoxup-membership' );
        }
        $required_plugins = isset( $manifest['requires_plugins'] ) && is_array( $manifest['requires_plugins'] ) ? $manifest['requires_plugins'] : array();
        if ( $required_plugins ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            foreach ( $required_plugins as $plugin_file ) {
                $plugin_file = sanitize_text_field( (string) $plugin_file );
                if ( '' !== $plugin_file && ! is_plugin_active( $plugin_file ) ) {
                    /* translators: %s: required plugin file. */
                    $errors[] = sprintf( __( 'Required plugin is not active: %s', 'evoxup-membership' ), $plugin_file );
                }
            }
        }
        if ( ! empty( $manifest['tested_evo'] ) && version_compare( EVOMEMBERS_VERSION, (string) $manifest['tested_evo'], '>' ) ) {
            $warnings[] = __( 'This extension has not declared compatibility with the installed EVO version.', 'evoxup-membership' );
        }
        foreach ( array( 'requires_extensions', 'conflicts_extensions' ) as $key ) {
            if ( isset( $manifest[ $key ] ) && ! is_array( $manifest[ $key ] ) ) {
                $errors[] = sprintf( '%s must be an object or array in extension.json.', $key );
            }
        }
        return array( 'compatible' => empty( $errors ), 'errors' => $errors, 'warnings' => $warnings );
    }

    private function is_version( string $version ): bool {
        return 1 === preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', trim( $version ) );
    }
}
