<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Class WISHCART_ContentProcessor
 * Handles processing and cleaning content
 */
class WISHCART_ContentProcessor {
    /**
     * Process HTML content
     *
     * @param string $html HTML content
     * @param array $include_selectors Optional CSS selectors to include
     * @param array $exclude_selectors Optional CSS selectors to exclude
     * @return string Processed content
     */
    public function process($html, $include_selectors = [], $exclude_selectors = []) {
        // error_log('ContentProcessor: Raw HTML (first 1000 chars): ' . substr($html, 0, 1000));
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        
        // Remove script, style, and other non-content elements
        $this->remove_elements_by_tag($doc, 'script');
        $this->remove_elements_by_tag($doc, 'style');
        $this->remove_elements_by_tag($doc, 'svg');
        $this->remove_elements_by_tag($doc, 'noscript');
        $this->remove_elements_by_tag($doc, 'iframe');
        
        $xpath = new DOMXPath($doc);
        
        // Handle exclude selectors
        if (!empty($exclude_selectors)) {
            foreach ($exclude_selectors as $selector) {
                $this->remove_elements_by_selector($doc, $xpath, $selector);
            }
        }
        
        // Handle include selectors
        if (!empty($include_selectors)) {
            $content = '';
            foreach ($include_selectors as $selector) {
                $elements = $this->get_elements_by_selector($xpath, $selector);
                foreach ($elements as $element) {
                    $content .= trim($element->textContent) . "\n\n";
                }
            }
            $cleaned = $this->clean_content($content);
            return $cleaned;
        }
        
        // If no include selectors, get all text content
        $body = $doc->getElementsByTagName('body');
        if ($body->length > 0) {
            $cleaned = $this->clean_content($body->item(0)->textContent);
            // error_log('ContentProcessor: Processed content (first 1000 chars): ' . substr($cleaned, 0, 1000));
            return $cleaned;
        }
        // error_log('ContentProcessor: No body tag found or no content extracted.');
        return '';
    }

    /**
     * Remove elements by tag name
     */
    private function remove_elements_by_tag($doc, $tag_name) {
        $elements = $doc->getElementsByTagName($tag_name);
        $total = $elements->length;
        
        for ($i = $total - 1; $i >= 0; $i--) {
            $element = $elements->item($i);
            if ($element && $element->parentNode) {
                $element->parentNode->removeChild($element);
            }
        }
    }

    /**
     * Remove elements by CSS selector
     */
    private function remove_elements_by_selector($doc, $xpath, $selector) {
        $selector = trim($selector);
        $elements = $this->get_elements_by_selector($xpath, $selector);
        
        foreach ($elements as $element) {
            if ($element && $element->parentNode) {
                $element->parentNode->removeChild($element);
            }
        }
    }

    /**
     * Get elements by CSS selector
     */
    private function get_elements_by_selector($xpath, $selector) {
        $selector = trim($selector);
        $elements = [];
        
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $selector)) {
            $nodeList = $xpath->query("//{$selector}");
            if ($nodeList) {
                foreach ($nodeList as $node) {
                    $elements[] = $node;
                }
            }
            return $elements;
        }
        
        if (substr($selector, 0, 1) === '.') {
            $class = substr($selector, 1);
            $nodeList = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]");
            if ($nodeList) {
                foreach ($nodeList as $node) {
                    $elements[] = $node;
                }
            }
            return $elements;
        }
        
        if (substr($selector, 0, 1) === '#') {
            $id = substr($selector, 1);
            $nodeList = $xpath->query("//*[@id='{$id}']");
            if ($nodeList) {
                foreach ($nodeList as $node) {
                    $elements[] = $node;
                }
            }
            return $elements;
        }
        
        return $elements;
    }

    /**
     * Clean content
     */
    private function clean_content($text) {
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        // Remove common web page elements
        $phrases_to_remove = [
            'Skip to content', 'Skip to main content', 'Skip to navigation',
            'Search', 'Search for:', 'Menu', 'Main Menu', 'Navigation', 'Primary Menu',
            'Footer', 'Header', 'Sidebar', 'Widget', 'Banner',
            'Home', 'About', 'Contact', 'Services', 'Products', 'Portfolio',
            'Copyright', 'All rights reserved', '©', 'Terms', 'Privacy',
            'Share', 'Share this', 'Share on', 'Like', 'Tweet', 'Pin',
            'Read more', 'Learn more', 'Click here', 'Details', 'More',
            'Subscribe', 'Newsletter', 'Sign up', 'Log in', 'Register', 'Login',
            'Username', 'Password', 'Forgot password',
            'Comments', 'Leave a comment', 'Reply', 'Submit', 'Post',
            'Related', 'Categories', 'Tags', 'Archives', 'Recent',
            'Previous', 'Next', 'Back', 'Forward', 'Continue',
            'Add to cart', 'Buy now', 'Checkout', 'Shopping cart'
        ];
        
        foreach ($phrases_to_remove as $phrase) {
            $text = preg_replace('/\b' . preg_quote($phrase, '/') . '\b/i', '', $text);
        }
        
        return trim($text);
    }
} 