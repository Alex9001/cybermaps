<?php
declare(strict_types=1);

namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shared acknowledgement rule for reviewed configuration transactions. */
final class ConfigurationReviewGuard {
	/** @param array<string,mixed> $preview */
	public static function has_high_impact_changes( array $preview ): bool {
		if ( ! empty( $preview['high_impact_changes'] ) ) {
			return true;
		}
		if ( ! isset( $preview['changes'] ) || ! is_array( $preview['changes'] ) ) {
			return false;
		}
		foreach ( $preview['changes'] as $change ) {
			if (
				is_array( $change )
				&& ! empty( $change['high_impact'] )
				&& 'unchanged' !== ( $change['status'] ?? '' )
			) {
				return true;
			}
		}

		return false;
	}
}
