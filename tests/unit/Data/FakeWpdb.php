<?php
/**
 * In-memory $wpdb double for Data-layer tests.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Tests\Unit\Data;

use stdClass;

/**
 * Minimal $wpdb replacement for unit tests.
 *
 * Provides the subset of the $wpdb surface used by MetaRepository
 * (and later Collector): a prefix property, prepare() that records
 * argument lists, and get_results() that returns queued pages.
 *
 * Named class rather than anonymous: PHPUnit 9.6 accumulates
 * anonymous class definitions across test methods, which pushed
 * the unit suite into OOM territory (3.1 GB RSS, kernel kill).
 */
final class FakeWpdb {

	/**
	 * Table prefix, matching $wpdb->prefix.
	 *
	 * @var string
	 */
	public string $prefix = 'wp_';

	/**
	 * Queued result pages, consumed one per get_results() call.
	 *
	 * @var array<int, array<int, stdClass>>
	 */
	public array $pages = array();

	/**
	 * SQL strings passed to get_results(), in call order.
	 *
	 * @var array<int, string>
	 */
	public array $queries = array();

	/**
	 * Argument lists passed to prepare(), in call order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $prepare_args = array();

	/**
	 * Deletion calls captured by the double.
	 *
	 * @var array<int, array{table: string, where: array<string, mixed>, format: array<int, string>}>
	 */
	public array $deletes = array();

	/**
	 * Calls captured by query(), in call order.
	 *
	 * @var array<int, string>
	 */
	public array $query_calls = array();

	/**
	 * Record placeholder arguments and return the SQL unchanged.
	 *
	 * The real wpdb::prepare() substitutes values into the SQL.
	 * Tests only need to observe how many placeholders were
	 * requested, so substitution is skipped.
	 *
	 * @param string $sql     SQL with %d placeholders.
	 * @param mixed  ...$args Placeholder values.
	 * @return string The unmodified SQL.
	 */
	public function prepare( string $sql, ...$args ): string {
		$this->prepare_args[] = $args;
		return $sql;
	}

	/**
	 * Return the next queued page, or an empty array when drained.
	 *
	 * @param string $sql Prepared SQL (unused).
	 * @return array<int, stdClass>
	 */
	public function get_results( string $sql ): array {
		$this->queries[] = $sql;
		return array_shift( $this->pages ) ?? array();
	}

	/**
	 * Record a delete() call and return 1.
	 *
	 * @param string               $table  Table name.
	 * @param array<string, mixed> $where  WHERE column => value pairs.
	 * @param array<int, string>   $format Placeholder formats.
	 * @return int Affected rows.
	 */
	public function delete( string $table, array $where, array $format ): int {
		$this->deletes[] = array(
			'table'  => $table,
			'where'  => $where,
			'format' => $format,
		);
		return 1;
	}

	/**
	 * Record a query() call and return 1.
	 *
	 * The real wpdb::query() executes the statement. Unit tests
	 * only need to observe the SQL and a non-false return, so the
	 * double always reports one affected row.
	 *
	 * @param string $sql Prepared SQL.
	 * @return int Affected rows.
	 */
	public function query( string $sql ): int {
		$this->query_calls[] = $sql;
		return 1;
	}
}
