<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Class AISK_URLContentFetcher
 * Handles fetching content from URLs
 */
class AISK_URLContentFetcher {
    /**
     * Fetch content from a URL
     *
     * @param string $url URL to fetch
     * @param array $include_selectors Optional CSS selectors to include
     * @param array $exclude_selectors Optional CSS selectors to exclude
     * @return string|WP_Error Content or WP_Error on failure
     * @throws Exception When bot protection is detected or other errors occur
     */
    public function fetch($url, $include_selectors = [], $exclude_selectors = []) {
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'user-agent' => 'Mozilla/5.0 (compatible; AiskBot/1.0; +https://aisk.chat)',
            'sslverify' => false,
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
                'Referer' => wp_parse_url($url, PHP_URL_SCHEME) . '://' . wp_parse_url($url, PHP_URL_HOST)
            ]
        ]);

        if (is_wp_error($response)) {
            throw new Exception('Failed to fetch URL: ' . esc_html($response->get_error_message()));
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $headers = wp_remote_retrieve_headers($response);

        // Check for bot protection/captcha indicators
        if ($this->detect_bot_protection($body, $headers, $status_code)) {
            throw new Exception('Bot protection detected. Unable to crawl this URL.');
        }

        if ($status_code !== 200) {
            throw new Exception('HTTP error: ' . esc_html($status_code));
        }

        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if (!preg_match('/(text\/html|application\/xhtml\+xml)/i', $content_type)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return false;
        }

        return $body;
    }

    /**
     * Detect if the response indicates bot protection
     *
     * @param string $body Response body
     * @param array $headers Response headers
     * @param int $status_code HTTP status code
     * @return bool True if bot protection detected
     */
    private function detect_bot_protection($body, $headers, $status_code) {
        // Check for common bot protection indicators in headers
        $suspicious_headers = ['x-robots-tag', 'cf-ray', 'server', 'x-firewall-protection'];
        foreach ($suspicious_headers as $header) {
            if (isset($headers[$header]) && 
                preg_match('/(cloudflare|protection|security|firewall|guard)/i', $headers[$header])) {
                return true;
            }
        }

        // Check for common bot protection indicators in body
        $bot_protection_indicators = [
            'captcha',
            'cloudflare',
            'ddos-guard',
            'challenge-form',
            'access denied',
            'blocked',
            'security check',
            'please wait',
            'human verification',
            'bot protection',
            'javascript required',
            'please enable javascript',
            'checking your browser',
            'automated access',
            'temporarily limited',
            'too many requests'
        ];

        foreach ($bot_protection_indicators as $indicator) {
            if (stripos($body, $indicator) !== false) {
                return true;
            }
        }

        // Check for specific status codes that might indicate bot protection
        $suspicious_status_codes = [403, 429, 503];
        if (in_array($status_code, $suspicious_status_codes)) {
            return true;
        }

        // Check for unusually small response size (might indicate a challenge page)
        if (strlen($body) < 1000 && $status_code !== 200) {
            return true;
        }

        return false;
    }
}