<?php
namespace EvoMembers\Core;

use EvoMembers\Contracts\ExtensionInterface;

 defined( 'ABSPATH' ) || exit;

/**
 * Extension loader with dependency-aware boot ordering.
 *
 * Legacy module.json packages remain supported. First-class extension.json
 * packages may declare requires_extensions/conflicts_extensions; Core resolves
 * them deterministically before boot so load order is never filesystem-dependent.
 */
final class ModuleLoader {
    private array $installed = array();

    public function discover(): array {
        $this->installed = array();
        // WordPress.org build: Core only boots modules bundled in this release.
        // Separately distributed executable add-ons must be normal WordPress
        // plugins and bootstrap themselves through the standard plugin loader.
        $roots = array( EVOMEMBERS_PATH . 'modules/' );
        foreach ( $roots as $root ) {
            $dirs = glob( $root . '*', GLOB_ONLYDIR );
            if ( ! is_array( $dirs ) ) { continue; }
            foreach ( $dirs as $dir ) {
                $manifest = $this->manifest_for_directory( $dir );
                if ( ! $manifest ) { continue; }
                $file = $manifest['file'];
                $data = json_decode( (string) file_get_contents( $file ), true );
                if ( ! is_array( $data ) || empty( $data['id'] ) || empty( $data['bootstrap'] ) ) { continue; }
                $id = sanitize_key( (string) $data['id'] );
                if ( isset( $this->installed[ $id ] ) ) { continue; }
                $data['path'] = trailingslashit( $dir );
                $data['manifest_file'] = basename( $file );
                $data['extension_format'] = 'extension.json' === basename( $file ) ? 'extension' : 'legacy_module';
                $data['compatibility'] = ( new ModuleCompatibility() )->check( $data );
                $this->installed[ $id ] = $data;
            }
        }
        $this->apply_installed_dependency_checks();
        return $this->installed;
    }

    public function boot_active(): void {
        $extensions = $this->discover();
        $active = get_option( 'evomembers_active_modules', array() );
        $active = is_array( $active ) ? array_values( array_unique( array_map( 'sanitize_key', $active ) ) ) : array();
        [ $order, $dependency_failures ] = $this->resolve_boot_order( $extensions, $active );

        foreach ( $dependency_failures as $id => $message ) {
            $this->disable_with_recovery( $id, $extensions[ $id ] ?? array(), $active, $message );
            $active = array_values( array_diff( $active, array( $id ) ) );
        }

        foreach ( $order as $id ) {
            $extension = $extensions[ $id ] ?? null;
            if ( ! is_array( $extension ) || ! in_array( $id, $active, true ) || empty( $extension['compatibility']['compatible'] ) ) { continue; }
            foreach ( $this->dependency_map( $extension['requires_extensions'] ?? array() ) as $dependency_id => $constraint ) {
                if ( ! in_array( $dependency_id, $active, true ) ) {
                    $this->disable_with_recovery( $id, $extension, $active, sprintf( 'Required extension %s failed or became inactive before this extension could boot.', $dependency_id ) );
                    $active = array_values( array_diff( $active, array( $id ) ) );
                    continue 2;
                }
            }
            $bootstrap = $extension['path'] . sanitize_file_name( (string) $extension['bootstrap'] );
            if ( ! is_readable( $bootstrap ) ) {
                $this->disable_with_recovery( $id, $extension, $active, 'The extension bootstrap file could not be read.' );
                $active = array_values( array_diff( $active, array( $id ) ) );
                continue;
            }
            try {
                require_once $bootstrap;
                $class = ltrim( (string) ( $extension['class'] ?? '' ), '\\' );
                $instance = null;

                if ( 'extension' === (string) ( $extension['extension_format'] ?? '' ) ) {
                    if ( '' === $class || ! class_exists( $class ) ) {
                        throw new \RuntimeException( 'The extension class declared in extension.json could not be loaded.' );
                    }
                    $instance = new $class();
                    if ( ! $instance instanceof ExtensionInterface ) {
                        throw new \RuntimeException( 'First-class EVO extensions must implement EvoMembers\\Contracts\\ExtensionInterface.' );
                    }
                    $instance->boot( new ExtensionContext( $id, $extension ) );
                } elseif ( '' !== $class && class_exists( $class ) ) {
                    $instance = new $class();
                    if ( method_exists( $instance, 'boot' ) ) { $instance->boot(); }
                }

                delete_option( 'evomembers_module_recovery_' . $id );
                \EvoMembers\Core\Hooks::do_action( 'extension_loaded', $id, $instance, $extension );
                \EvoMembers\Core\Hooks::do_action( 'module_loaded', $id, $instance );
            } catch ( \Throwable $e ) {
                $this->disable_with_recovery( $id, $extension, $active, $e->getMessage(), $e );
                $active = array_values( array_diff( $active, array( $id ) ) );
            }
        }
    }

    private function apply_installed_dependency_checks(): void {
        foreach ( $this->installed as $id => &$extension ) {
            $compat = is_array( $extension['compatibility'] ?? null ) ? $extension['compatibility'] : array( 'compatible' => true, 'errors' => array(), 'warnings' => array() );
            foreach ( $this->dependency_map( $extension['requires_extensions'] ?? array() ) as $dependency_id => $constraint ) {
                if ( ! isset( $this->installed[ $dependency_id ] ) ) {
                    $compat['errors'][] = sprintf( 'Required Evoxup extension is not installed: %s.', $dependency_id );
                    continue;
                }
                $version = (string) ( $this->installed[ $dependency_id ]['version'] ?? '0.0.0' );
                if ( ! VersionConstraint::matches( $version, $constraint ) ) {
                    $compat['errors'][] = sprintf( 'Required extension %1$s does not satisfy version constraint %2$s (installed %3$s).', $dependency_id, $constraint, $version );
                }
            }
            $compat['compatible'] = empty( $compat['errors'] );
            $extension['compatibility'] = $compat;
        }
        unset( $extension );
    }

    /** @return array{0:array<int,string>,1:array<string,string>} */
    private function resolve_boot_order( array $extensions, array $active ): array {
        $active_map = array_fill_keys( $active, true );
        $state = array();
        $order = array();
        $failures = array();
        $stack = array();

        $visit = function ( string $id ) use ( &$visit, &$state, &$order, &$failures, &$stack, $extensions, $active_map ): void {
            if ( isset( $failures[ $id ] ) || 2 === ( $state[ $id ] ?? 0 ) ) { return; }
            if ( 1 === ( $state[ $id ] ?? 0 ) ) {
                $cycle_start = array_search( $id, $stack, true );
                $cycle = false === $cycle_start ? array( $id ) : array_slice( $stack, $cycle_start );
                $cycle[] = $id;
                $message = 'Extension dependency cycle detected: ' . implode( ' -> ', $cycle );
                foreach ( array_unique( $cycle ) as $cycle_id ) { $failures[ $cycle_id ] = $message; }
                return;
            }
            $extension = $extensions[ $id ] ?? null;
            if ( ! is_array( $extension ) ) { return; }
            if ( empty( $extension['compatibility']['compatible'] ) ) {
                $failures[ $id ] = implode( ' ', (array) ( $extension['compatibility']['errors'] ?? array( 'Extension is incompatible.' ) ) );
                return;
            }
            foreach ( $this->dependency_map( $extension['conflicts_extensions'] ?? array() ) as $conflict_id => $constraint ) {
                if ( isset( $active_map[ $conflict_id ], $extensions[ $conflict_id ] ) && VersionConstraint::matches( (string) ( $extensions[ $conflict_id ]['version'] ?? '0.0.0' ), $constraint ) ) {
                    $failures[ $id ] = sprintf( 'Extension conflicts with active extension %1$s (%2$s).', $conflict_id, $constraint );
                    return;
                }
            }
            $state[ $id ] = 1;
            $stack[] = $id;
            foreach ( $this->dependency_map( $extension['requires_extensions'] ?? array() ) as $dependency_id => $constraint ) {
                if ( ! isset( $active_map[ $dependency_id ] ) ) {
                    $failures[ $id ] = sprintf( 'Required extension %s is installed but not active.', $dependency_id );
                    continue;
                }
                $visit( $dependency_id );
                if ( isset( $failures[ $dependency_id ] ) ) {
                    $failures[ $id ] = sprintf( 'Required extension %s could not boot safely.', $dependency_id );
                }
            }
            array_pop( $stack );
            $state[ $id ] = 2;
            if ( ! isset( $failures[ $id ] ) && ! in_array( $id, $order, true ) ) { $order[] = $id; }
        };

        foreach ( $active as $id ) { if ( isset( $extensions[ $id ] ) ) { $visit( $id ); } }
        return array( $order, $failures );
    }

    /** @return array<string,string> */
    private function dependency_map( mixed $value ): array {
        if ( ! is_array( $value ) ) { return array(); }
        $map = array();
        foreach ( $value as $key => $constraint ) {
            if ( is_int( $key ) ) { $id = sanitize_key( (string) $constraint ); $constraint = '*'; }
            else { $id = sanitize_key( (string) $key ); $constraint = trim( (string) $constraint ) ?: '*'; }
            if ( '' !== $id ) { $map[ $id ] = $constraint; }
        }
        return $map;
    }

    private function disable_with_recovery( string $id, array $extension, array $active, string $message, ?\Throwable $exception = null ): void {
        $active = array_values( array_diff( $active, array( $id ) ) );
        update_option( 'evomembers_active_modules', $active, false );
        $recovery = array(
            'message' => sanitize_text_field( $message ),
            'time'    => current_time( 'mysql', true ),
            'version' => sanitize_text_field( (string) ( $extension['version'] ?? '' ) ),
        );
        update_option( 'evomembers_module_recovery_' . $id, $recovery, false );
        if ( $exception ) { \EvoMembers\Core\Hooks::do_action( 'extension_failed', $id, $exception, $recovery ); }
        else { \EvoMembers\Core\Hooks::do_action( 'extension_dependency_failed', $id, $recovery ); }
    }

    private function manifest_for_directory( string $dir ): ?array {
        $dir = trailingslashit( $dir );
        foreach ( array( 'extension.json', 'module.json' ) as $name ) {
            $file = $dir . $name;
            if ( is_readable( $file ) ) { return array( 'file' => $file, 'name' => $name ); }
        }
        return null;
    }
}
