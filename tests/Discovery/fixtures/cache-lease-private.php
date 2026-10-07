<?php
declare(strict_types=1);

require dirname( __DIR__, 2 ) . '/bootstrap.php';

// Mirror native Core's private cache and by-value magic getter/setter contract.
class WP_Object_Cache {
	private array $cache = array();
	public int $writebacks = 0;
	public function __get( string $name ): mixed { return $this->$name; }
	public function __set( string $name, mixed $value ): void { ++$this->writebacks; $this->$name = $value; }
	public function __isset( string $name ): bool { return isset( $this->$name ); }
}
set_error_handler( static function ( int $severity, string $message ): never { throw new RuntimeException( $message, $severity ); } );
$GLOBALS['cybermaps_mock_using_ext_object_cache'] = false;
$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
$GLOBALS['wp_object_cache']->cache = array( 'post_meta' => array( '3:old' => array( 'caller' => true ) ), 'unrelated' => array( 'preserved' => true ) );
$lease = new \Cybermaps\Discovery\PublicationCacheLease();
$values = $GLOBALS['wp_object_cache']->cache;
$values['post_meta']['3:worker'] = array( 'worker' => true );
$values['post_meta']['3:changed'] = array( 'worker' => true );
$values['category_relationships']['3:worker'] = array( 7 );
$values['terms']['3:term'] = (object) array( 'name' => 'Worker term' );
$GLOBALS['wp_object_cache']->cache = $values;
$lease->capture();
$values['post_meta']['3:changed'] = array( 'caller_changed' => true );
$values['terms']['3:term']->name = 'Caller term';
$GLOBALS['wp_object_cache']->cache = $values;
unset( $values );
$before = $GLOBALS['wp_object_cache']->writebacks;
$lease->release();
restore_error_handler();
echo json_encode( array( 'remaining' => $GLOBALS['wp_object_cache']->cache, 'release_writebacks' => $GLOBALS['wp_object_cache']->writebacks - $before ) );
