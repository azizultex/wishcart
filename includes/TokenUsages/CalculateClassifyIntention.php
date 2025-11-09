<?php
/**
 * Calculate classify intention usage for a given auth_key and site_url.
 */
class CalculateClassifyIntention {
    private $wpdb;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * Get daily usage for a given auth_key and site_url.
     *
     * @param string $auth_key
     * @param string $site_url
     * @return array|false
     */
    public function get_daily_usage($auth_key, $site_url) {
        $today = gmdate('Y-m-d');
        return $this->get_usage($auth_key, $site_url, $today, $today);
    }

    /**
     * Get monthly usage for a given auth_key and site_url.
     *
     * @param string $auth_key
     * @param string $site_url
     * @return array|false
     */
    public function get_monthly_usage($auth_key, $site_url) {
        $first_day = gmdate('Y-m-01');
        $last_day = gmdate('Y-m-t');
        return $this->get_usage($auth_key, $site_url, $first_day, $last_day);
    }

    /**
     * Internal method to get usage between two dates (inclusive).
     *
     * @param string $auth_key
     * @param string $site_url
     * @param string $from_date (Y-m-d)
     * @param string $to_date (Y-m-d)
     * @return array|false
     */
    private function get_usage($auth_key, $site_url, $from_date, $to_date) {
        $prefix = $this->wpdb->prefix;
        $usage_table = esc_sql( $prefix . 'sb_usage_logs' );
        $auth_table = esc_sql( $prefix . 'sb_auth_keys' );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names cannot be parameterized
        $sql = $this->wpdb->prepare(
            "SELECT 
                SUM(ul.classify_requests) as classify_requests,
                SUM(ul.classify_prompt_tokens) as classify_prompt_tokens,
                SUM(ul.classify_completion_tokens) as classify_completion_tokens,
                SUM(ul.classify_total_tokens) as classify_total_tokens
            FROM {$usage_table} ul
            INNER JOIN {$auth_table} ak ON ul.auth_key_id = ak.id
            WHERE ak.auth_key = %s
              AND ak.site_url LIKE %s
              AND ul.date_log BETWEEN %s AND %s",
            $auth_key, '%' . $this->wpdb->esc_like($site_url) . '%', $from_date, $to_date
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Using a pre-prepared query variable
        $result = $this->wpdb->get_row( $sql, ARRAY_A );
        if (!$result) {
            return [
                'classify_requests' => 0,
                'classify_prompt_tokens' => 0,
                'classify_completion_tokens' => 0,
                'classify_total_tokens' => 0,
            ];
        }
        // Ensure all values are integers
        return [
            'classify_requests' => (int) ($result['classify_requests'] ?? 0),
            'classify_prompt_tokens' => (int) ($result['classify_prompt_tokens'] ?? 0),
            'classify_completion_tokens' => (int) ($result['classify_completion_tokens'] ?? 0),
            'classify_total_tokens' => (int) ($result['classify_total_tokens'] ?? 0),
        ];
    }
}
