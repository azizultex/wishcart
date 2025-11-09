<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Class WISHCART_URLDiscoverer
 * Handles discovering URLs from content
 */
class WISHCART_URLDiscoverer {
    private $content_fetcher;

    public function __construct($content_fetcher) {
        $this->content_fetcher = $content_fetcher;
    }

    /**
     * Discover URLs from a given URL
     *
     * @param string $url URL to discover from
     * @param array $options Discovery options
     * @return array Array of discovered URLs
     */
    public function discover($url, $options = []) {
        $content = $this->content_fetcher->fetch($url);
        if (!$content) {
            return [];
        }

        $urls = [];
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        @$doc->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
        
        $xpath = new DOMXPath($doc);
        $elements = $xpath->query('//a');
        
        foreach ($elements as $element) {
            /** @var DOMElement $element */
            $href = $element->getAttribute('href');
            if (empty($href)) {
                continue;
            }
            
            // Skip page anchors, javascript:, mailto:, tel:, etc.
            if (preg_match('/^(#|javascript:|mailto:|tel:)/i', $href)) {
                continue;
            }
            
            // Skip URLs with hash fragments
            if (strpos($href, '#') !== false) {
                $href = preg_replace('/#.*$/', '', $href);
                if (empty($href)) {
                    continue;
                }
            }
            
            // Convert relative URLs to absolute
            $domain = wp_parse_url($url, PHP_URL_HOST);
            $absolute_url = $this->normalize_url($href, $url, $domain);
            if ($absolute_url) {
                $urls[] = $absolute_url;
            }
        }
        
        return array_unique($urls);
    }

    /**
     * Normalize a URL
     */
    private function normalize_url($href, $base_url, $domain) {
        if (preg_match('/^(mailto:|tel:|javascript:|#)/i', $href)) {
            return false;
        }
        
        if (substr($href, 0, 2) === '//') {
            $base_protocol = wp_parse_url($base_url, PHP_URL_SCHEME);
            return $base_protocol . ':' . $href;
        }
        
        if (preg_match('/^https?:\/\//i', $href)) {
            $href_domain = wp_parse_url($href, PHP_URL_HOST);
            if ($href_domain !== $domain) {
                return false;
            }
            return $href;
        }
        
        if (substr($href, 0, 1) === '/') {
            $base_parts = wp_parse_url($base_url);
            $scheme = isset($base_parts['scheme']) ? $base_parts['scheme'] : 'https';
            return $scheme . '://' . $domain . $href;
        }
        
        $base_parts = wp_parse_url($base_url);
        $path = isset($base_parts['path']) ? $base_parts['path'] : '';
        $path = preg_replace('/\/[^\/]*$/', '/', $path);
        
        $scheme = isset($base_parts['scheme']) ? $base_parts['scheme'] : 'https';
        return $scheme . '://' . $domain . rtrim($path, '/') . '/' . ltrim($href, '/');
    }
} 