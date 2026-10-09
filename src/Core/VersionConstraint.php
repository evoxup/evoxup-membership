<?php
namespace EvoMembers\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Small dependency constraint matcher used by the extension loader.
 * Supports AND-combined comparators, exact versions, caret, tilde and wildcards.
 */
final class VersionConstraint {
    public static function matches( string $version, string $constraint ): bool {
        $version = trim( $version );
        $constraint = trim( $constraint );
        if ( '' === $constraint || '*' === $constraint ) { return true; }
        $parts = preg_split( '/\s*,\s*|\s+/', $constraint ) ?: array();
        foreach ( $parts as $part ) {
            if ( '' !== $part && ! self::matches_part( $version, $part ) ) { return false; }
        }
        return true;
    }

    private static function matches_part( string $version, string $part ): bool {
        $semver = '\\d+(?:\\.\\d+){0,2}(?:[-+][0-9A-Za-z.-]+)?';
        if ( preg_match( '/^(>=|<=|>|<|=)?\s*(' . $semver . ')$/', $part, $m ) ) {
            $operator = $m[1] ?: '=';
            return version_compare( $version, self::normalize( $m[2] ), $operator );
        }
        if ( preg_match( '/^\^(' . $semver . ')$/', $part, $m ) ) {
            $base = self::normalize( $m[1] );
            [ $major, $minor, $patch ] = self::numeric_parts( $base );
            if ( $major > 0 ) { $upper = ( $major + 1 ) . '.0.0'; }
            elseif ( $minor > 0 ) { $upper = '0.' . ( $minor + 1 ) . '.0'; }
            else { $upper = '0.0.' . ( $patch + 1 ); }
            return version_compare( $version, $base, '>=' ) && version_compare( $version, $upper, '<' );
        }
        if ( preg_match( '/^~(' . $semver . ')$/', $part, $m ) ) {
            $raw = $m[1];
            $base = self::normalize( $raw );
            [ $major, $minor ] = self::numeric_parts( $base );
            $numeric = explode( '-', preg_replace( '/\+.*/', '', $raw ), 2 )[0];
            $segments = substr_count( $numeric, '.' ) + 1;
            $upper = $segments >= 3 ? $major . '.' . ( $minor + 1 ) . '.0' : ( $major + 1 ) . '.0.0';
            return version_compare( $version, $base, '>=' ) && version_compare( $version, $upper, '<' );
        }
        if ( preg_match( '/^(\d+)\.\*$/', $part, $m ) ) {
            return version_compare( $version, $m[1] . '.0.0', '>=' ) && version_compare( $version, ( (int) $m[1] + 1 ) . '.0.0', '<' );
        }
        if ( preg_match( '/^(\d+)\.(\d+)\.\*$/', $part, $m ) ) {
            return version_compare( $version, $m[1] . '.' . $m[2] . '.0', '>=' ) && version_compare( $version, $m[1] . '.' . ( (int) $m[2] + 1 ) . '.0', '<' );
        }
        return false;
    }

    private static function normalize( string $version ): string {
        if ( preg_match( '/^([^+-]+)(.*)$/', $version, $m ) ) {
            $parts = explode( '.', $m[1] );
            while ( count( $parts ) < 3 ) { $parts[] = '0'; }
            return implode( '.', array_slice( $parts, 0, 3 ) ) . $m[2];
        }
        return $version;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function numeric_parts( string $version ): array {
        $numeric = preg_split( '/[-+]/', $version, 2 )[0];
        $parts = array_map( 'intval', explode( '.', $numeric ) );
        return array( $parts[0] ?? 0, $parts[1] ?? 0, $parts[2] ?? 0 );
    }
}
