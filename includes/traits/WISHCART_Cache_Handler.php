<?php
/**
 * Trait Cache_Handler
 * Provides common caching functionality for database queries
 */
trait WISHCART_Cache_Handler {
    /**
     * Get cached data or fetch from database
     *
     * @param string   $cache_key Cache key
     * @param callable $callback  Callback to fetch data if not cached
     * @param int      $expire    Cache expiration in seconds
     * @return mixed
     */
    protected function get_cached_data($cache_key, $callback, $expire = 300) {
        $data = wp_cache_get($cache_key, 'wishcart_cache');

        if (false === $data) {
            $data = $callback();
            if ($data) {
                wp_cache_set($cache_key, $data, 'wishcart_cache', $expire);
            }
        }

        return $data;
    }

    /**
     * Delete cache by key
     *
     * @param string $cache_key Cache key to delete
     */
    protected function delete_cache($cache_key) {
        wp_cache_delete($cache_key, 'wishcart_cache');
    }
}