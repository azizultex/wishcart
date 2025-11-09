<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * API Usage Tracker Class for AISK plugin
 *
 * @category WordPress
 * @package  AISK
 * @author   Aisk Team <support@aisk.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://aisk.chat
 */

/**
 * AISK_API_Usage_Tracker Class
 *
 * @category WordPress
 * @package  AISK
 * @author   Aisk Team <support@aisk.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://aisk.chat
 */
class AISK_API_Usage_Tracker {

    private static $instance = null;
    private $database;

    /**
     * Get singleton instance of the class
     *
     * @since 1.0.0
     *
     * @return AISK_API_Usage_Tracker Instance of the class
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->database = new AISK_Database();
    }

    /**
     * Log API usage
     *
     * @param array $data API usage data
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function log_usage( $data ) {
        // Ensure required fields are present
        $required_fields = ['request_id', 'channel', 'feature', 'provider', 'status'];
        foreach ($required_fields as $field) {
            if (!isset($data[$field])) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging is properly guarded
                    error_log("AISK API Usage Tracker: Missing required field '{$field}'");
                }
                return;
            }
        }

        // Add default values
        $data = array_merge([
            'conversation_id' => null,
            'user_id' => get_current_user_id() ?: null,
            'user_role' => $this->get_user_role(),
            'endpoint' => null,
            'model' => null,
            'error_code' => null,
            'latency_ms' => null,
            'tokens_in' => 0,
            'tokens_out' => 0,
            'cost_usd' => 0.0000,
            'ip_hash' => $this->get_ip_hash(),
            'country' => $this->get_country(),
            'url' => $this->get_current_url(),
            'context_id' => null,
            'metadata' => null,
        ], $data);

        // Calculate cost if not provided
        if ($data['cost_usd'] == 0 && ($data['tokens_in'] > 0 || $data['tokens_out'] > 0)) {
            $data['cost_usd'] = $this->calculate_cost($data['provider'], $data['model'], $data['tokens_in'], $data['tokens_out']);
        }

        // Store in database (non-blocking)
        $this->store_usage_async($data);
    }

    /**
     * Store usage data asynchronously
     *
     * @param array $data Usage data
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function store_usage_async( $data ) {
        // Use WordPress shutdown hook to store data without blocking the request
        add_action('shutdown', function() use ($data) {
            try {
                $this->database->store_api_usage($data);
            } catch (Exception $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging is properly guarded
                    error_log("AISK API Usage Tracker: Failed to store usage data - " . $e->getMessage());
                }
            }
        });
    }

    /**
     * Get user role
     *
     * @since 1.0.0
     *
     * @return string|null User role or null
     */
    private function get_user_role() {
        $user = wp_get_current_user();
        return $user && !empty($user->roles) ? $user->roles[0] : null;
    }

    /**
     * Get hashed IP address
     *
     * @since 1.0.0
     *
     * @return string|null Hashed IP address
     */
    private function get_ip_hash() {
        $ip = $this->get_client_ip();
        return $ip ? hash('sha256', $ip) : null;
    }

    /**
     * Get client IP address
     *
     * @since 1.0.0
     *
     * @return string|null Client IP address
     */
    private function get_client_ip() {
        $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_SERVER data is sanitized with wp_unslash and filter_var
                foreach (explode(',', wp_unslash($_SERVER[$key])) as $ip) {
                    $ip = trim($ip);
                    // Filter out private/reserved IP ranges for public client tracking
                    // Note: This excludes local/internal testing and customers behind private networks
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        // Fallback: validate REMOTE_ADDR with filter_var for security
        if (isset($_SERVER['REMOTE_ADDR'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_SERVER data is sanitized with wp_unslash and filter_var
            $remote = wp_unslash($_SERVER['REMOTE_ADDR']);
            // Validate before returning
            if (filter_var($remote, FILTER_VALIDATE_IP) !== false) {
                return $remote;
            }
        }
        
        return null;
    }

    /**
     * Get country from IP
     *
     * @since 1.0.0
     *
     * @return string|null Country name
     */
    private function get_country() {
        // Simple implementation - you might want to use a GeoIP service
        $ip = $this->get_client_ip();
        if (!$ip) {
            return null;
        }

        // For now, return null - you can integrate with a GeoIP service later
        return null;
    }

    /**
     * Get current URL
     *
     * @since 1.0.0
     *
     * @return string|null Current URL
     */
    private function get_current_url() {
        if (isset($_SERVER['HTTP_HOST']) && isset($_SERVER['REQUEST_URI'])) {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']));
            $uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
            // Use esc_url_raw when persisting or returning for external use
            return esc_url_raw($protocol . '://' . $host . $uri);
        }
        return null;
    }

    /**
     * Calculate cost based on provider and tokens
     *
     * @param string $provider Provider name
     * @param string $model Model name
     * @param int $tokens_in Input tokens
     * @param int $tokens_out Output tokens
     *
     * @since 1.0.0
     *
     * @return float Calculated cost in USD
     */
    private function calculate_cost( $provider, $model, $tokens_in, $tokens_out ) {
        // Pricing per 1K tokens (as of 2024)
        $pricing = [
            'openai' => [
                'gpt-4o' => ['input' => 0.005, 'output' => 0.015],
                'gpt-4o-mini' => ['input' => 0.0003, 'output' => 0.0006],
                'gpt-4' => ['input' => 0.03, 'output' => 0.06],
                'gpt-4-turbo' => ['input' => 0.01, 'output' => 0.03],
                'gpt-3.5-turbo' => ['input' => 0.0015, 'output' => 0.002],
                'text-embedding-ada-002' => ['input' => 0.0001, 'output' => 0],
            ],
            'anthropic' => [
                'claude-3-opus' => ['input' => 0.015, 'output' => 0.075],
                'claude-3-sonnet' => ['input' => 0.003, 'output' => 0.015],
                'claude-3-haiku' => ['input' => 0.00025, 'output' => 0.00125],
            ],
            'google' => [
                'gemini-pro' => ['input' => 0.0005, 'output' => 0.0015],
                'gemini-pro-vision' => ['input' => 0.0005, 'output' => 0.0015],
            ]
        ];

        $provider_lower = strtolower($provider);
        $model_lower = strtolower($model);

        if (!isset($pricing[$provider_lower]) || !isset($pricing[$provider_lower][$model_lower])) {
            return 0.0000; // Unknown pricing
        }

        $rates = $pricing[$provider_lower][$model_lower];
        $input_cost = ($tokens_in / 1000) * $rates['input'];
        $output_cost = ($tokens_out / 1000) * $rates['output'];

        return round($input_cost + $output_cost, 4);
    }

    /**
     * Log chat API usage
     *
     * @param string $request_id Request ID
     * @param string $conversation_id Conversation ID
     * @param string $provider Provider name
     * @param string $model Model name
     * @param string $status Request status
     * @param int $latency_ms Latency in milliseconds
     * @param int $tokens_in Input tokens
     * @param int $tokens_out Output tokens
     * @param string $error_code Error code if failed
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function log_chat_usage( $request_id, $conversation_id, $provider, $model, $status, $latency_ms, $tokens_in, $tokens_out, $error_code = null ) {
        $this->log_usage([
            'request_id' => $request_id,
            'conversation_id' => $conversation_id,
            'channel' => 'widget',
            'feature' => 'chat',
            'provider' => $provider,
            'endpoint' => 'chat/completions',
            'model' => $model,
            'status' => $status,
            'error_code' => $error_code,
            'latency_ms' => $latency_ms,
            'tokens_in' => $tokens_in,
            'tokens_out' => $tokens_out,
        ]);
    }

    /**
     * Log classify intent usage
     */
    public function log_classify_usage( $request_id, $conversation_id, $provider, $model, $status, $latency_ms, $tokens_in, $tokens_out = 0, $error_code = null ) {
        $this->log_usage([
            'request_id' => $request_id,
            'conversation_id' => $conversation_id,
            'channel' => 'widget',
            'feature' => 'classify',
            'provider' => $provider,
            'endpoint' => 'chat/completions',
            'model' => $model,
            'status' => $status,
            'error_code' => $error_code,
            'latency_ms' => $latency_ms,
            'tokens_in' => $tokens_in,
            'tokens_out' => $tokens_out,
        ]);
    }

    /**
     * Log embeddings API usage
     *
     * @param string $request_id Request ID
     * @param string $provider Provider name
     * @param string $model Model name
     * @param string $status Request status
     * @param int $latency_ms Latency in milliseconds
     * @param int $tokens_in Input tokens
     * @param string $error_code Error code if failed
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function log_embeddings_usage( $request_id, $provider, $model, $status, $latency_ms, $tokens_in, $error_code = null ) {
        $this->log_usage([
            'request_id' => $request_id,
            'channel' => 'admin',
            'feature' => 'embeddings',
            'provider' => $provider,
            'endpoint' => 'embeddings',
            'model' => $model,
            'status' => $status,
            'error_code' => $error_code,
            'latency_ms' => $latency_ms,
            'tokens_in' => $tokens_in,
            'tokens_out' => 0,
        ]);
    }

    /**
     * Log crawler usage
     *
     * @param string $request_id Request ID
     * @param string $url URL being crawled
     * @param string $status Request status
     * @param int $latency_ms Latency in milliseconds
     * @param string $error_code Error code if failed
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function log_crawler_usage( $request_id, $url, $status, $latency_ms, $error_code = null ) {
        $this->log_usage([
            'request_id' => $request_id,
            'channel' => 'admin',
            'feature' => 'crawler',
            'provider' => 'internal',
            'endpoint' => 'crawler',
            'status' => $status,
            'error_code' => $error_code,
            'latency_ms' => $latency_ms,
            'tokens_in' => 0,
            'tokens_out' => 0,
            'url' => $url,
            'context_id' => $url,
        ]);
    }

    /**
     * Log messenger usage
     *
     * @param string $request_id Request ID
     * @param string $platform Platform (whatsapp, telegram)
     * @param string $feature Feature (send, receive)
     * @param string $status Request status
     * @param int $latency_ms Latency in milliseconds
     * @param string $error_code Error code if failed
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function log_messenger_usage( $request_id, $platform, $feature, $status, $latency_ms, $error_code = null ) {
        $this->log_usage([
            'request_id' => $request_id,
            'channel' => $platform,
            'feature' => 'messenger_' . $feature,
            'provider' => $platform,
            'endpoint' => $platform . '/' . $feature,
            'status' => $status,
            'error_code' => $error_code,
            'latency_ms' => $latency_ms,
            'tokens_in' => 0,
            'tokens_out' => 0,
        ]);
    }
}
