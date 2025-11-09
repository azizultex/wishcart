<?php
/**
 * Response Formatter Class for AISK
 *
 * This file contains the response formatting functionality for the AISK plugin,
 * handling message formatting, link conversion, and list formatting.
 *
 * @category WordPress
 * @package  AISK
 * @author   AISK Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */

if ( ! defined('ABSPATH') ) {
	exit;
}

/**
 * AISK Response Formatter Class
 *
 * Handles formatting of chatbot responses for better readability and user experience.
 *
 * @category Class
 * @package  AISK
 * @author   AISK Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */
class WISHCART_Response_Formatter {

    /**
     * Format the AI response for better display
     *
     * @param string $response The raw AI response
     * @return string The formatted response
     */
    public static function format_response($response) {
        if (empty($response)) {
            return $response;
        }

        // Convert response to HTML
        $formatted = self::convert_to_html($response);
        
        // Process links (enhanced)
        $formatted = self::process_links($formatted);
        
        // Process email addresses
        $formatted = self::process_emails($formatted);
        
        // Process lists (enhanced)
        $formatted = self::process_lists($formatted);
        
        // Process text formatting (enhanced)
        $formatted = self::process_text_formatting($formatted);
        
        // Process code blocks (enhanced)
        $formatted = self::process_code_blocks($formatted);
        
        // Process quotes (enhanced)
        $formatted = self::process_quotes($formatted);
        
        // Process headings
        $formatted = self::process_headings($formatted);
        
        // Process tables
        $formatted = self::process_tables($formatted);
        
        // Process callouts and alerts
        $formatted = self::process_callouts($formatted);
        
        // Clean up and sanitize
        $formatted = self::cleanup_html($formatted);
        
        return $formatted;
    }

    /**
     * Convert markdown-style text to HTML
     *
     * @param string $text The text to convert
     * @return string The converted HTML
     */
    private static function convert_to_html($text) {
        $text = str_replace(["\r\n", "\n"], '</p><p>', $text);
        $text = '<p>' . $text . '</p>';
        
        // Remove empty paragraphs
        $text = preg_replace('/<p class="mb-5">\s*<\/p>/', '', $text);
        return $text;
    }

    /**
     * Process and convert URLs to clickable links (Enhanced)
     *
     * @param string $text The text containing URLs
     * @return string The text with clickable links
     */
    private static function process_links($text) {
        // Enhanced URL pattern for matching various URL formats
        $url_pattern = '/(https?:\/\/[^\s<>"{}|\\^`\[\]]+)/i';
        
        // Replace URLs with clickable links
        $text = preg_replace_callback($url_pattern, function($matches) {
            $url = $matches[1];
            $display_url = $url;
            
            // Extract domain for better display
            $parsed_url = wp_parse_url($url);
            if ($parsed_url && isset($parsed_url['host'])) {
                $domain = $parsed_url['host'];
                $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
                
                // Create a more readable display URL
                if (strlen($url) > 50) {
                    $display_url = $domain . $path;
                    if (strlen($display_url) > 40) {
                        $display_url = $domain . (strlen($path) > 20 ? '...' . substr($path, -15) : $path);
                    }
                }
            }
            
            // Add external link icon for external URLs
            // Determine site host safely for comparison
            $site_parsed = wp_parse_url( home_url() );
            $site_host = isset( $site_parsed['host'] ) ? $site_parsed['host'] : '';
            $is_external = ! isset( $parsed_url['host'] ) || ( $site_host && strtolower( $parsed_url['host'] ) !== strtolower( $site_host ) );
            $external_icon = $is_external ? '<span class="external-link-icon">↗</span>' : '';
            
            return sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer" class="chat-link %s">%s%s</a>',
                esc_url($url),
                $is_external ? 'external-link' : 'internal-link',
                esc_html($display_url),
                $external_icon
            );
        }, $text);
        
        return $text;
    }

    /**
     * Process and convert email addresses to clickable mailto links
     *
     * @param string $text The text containing email addresses
     * @return string The text with clickable email links
     */
    private static function process_emails($text) {
        // Email pattern to match email addresses
        $email_pattern = '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/';
        
        // Replace email addresses with clickable mailto links
        $text = preg_replace_callback($email_pattern, function($matches) {
            $email = $matches[0];
            
            return sprintf(
                '<a href="mailto:%s" class="chat-email-link">%s</a>',
                esc_html($email),
                esc_html($email)
            );
        }, $text);
        
        return $text;
    }

    /**
     * Process and format lists (Enhanced)
     *
     * @param string $text The text containing lists
     * @return string The text with formatted lists
     */
    private static function process_lists($text) {
        // Split text into lines
        $lines = explode('<br />', $text);
        $formatted_lines = [];
        $in_list = false;
        $list_type = '';
        $list_items = [];
        $list_level = 0;
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Check for bullet points (enhanced patterns)
            if (preg_match('/^[\s]*([•\-\*\+])[\s]+(.+)$/', $line, $matches)) {
                $bullet = $matches[1];
                $content = trim($matches[2]);
                
                if (!$in_list || $list_type !== 'ul') {
                    if ($in_list) {
                        $formatted_lines[] = self::close_list($list_items, $list_type, $list_level);
                        $list_items = [];
                    }
                    $in_list = true;
                    $list_type = 'ul';
                    $list_level = 0;
                }
                $list_items[] = $content;
            }
            // Check for numbered lists (enhanced patterns)
            elseif (preg_match('/^[\s]*(\d+)[\.\)][\s]+(.+)$/', $line, $matches)) {
                $number = $matches[1];
                $content = trim($matches[2]);
                
                if (!$in_list || $list_type !== 'ol') {
                    if ($in_list) {
                        $formatted_lines[] = self::close_list($list_items, $list_type, $list_level);
                        $list_items = [];
                    }
                    $in_list = true;
                    $list_type = 'ol';
                    $list_level = 0;
                }
                $list_items[] = $content;
            }
            // Check for nested lists (indented content)
            elseif ($in_list && preg_match('/^[\s]{2,}(.+)$/', $line, $matches)) {
                $content = trim($matches[1]);
                
                // Check if this is a new nested list item
                if (preg_match('/^([•\-\*\+])[\s]+(.+)$/', $content, $nested_matches)) {
                    // Close current list and start nested list
                    $formatted_lines[] = self::close_list($list_items, $list_type, $list_level);
                    $list_items = [trim($nested_matches[2])];
                    $list_type = 'ul';
                    $list_level++;
                } elseif (preg_match('/^(\d+)[\.\)][\s]+(.+)$/', $content, $nested_matches)) {
                    // Close current list and start nested numbered list
                    $formatted_lines[] = self::close_list($list_items, $list_type, $list_level);
                    $list_items = [trim($nested_matches[2])];
                    $list_type = 'ol';
                    $list_level++;
                } else {
                    // Continue current list item
                    if (!empty($list_items)) {
                        $list_items[count($list_items) - 1] .= ' ' . $content;
                    }
                }
            }
            // End of list
            else {
                if ($in_list) {
                    $formatted_lines[] = self::close_list($list_items, $list_type, $list_level);
                    $list_items = [];
                    $in_list = false;
                    $list_type = '';
                    $list_level = 0;
                }
                if (!empty($line)) {
                    $formatted_lines[] = $line;
                }
            }
        }
        
        // Close any remaining list
        if ($in_list) {
            $formatted_lines[] = self::close_list($list_items, $list_type, $list_level);
        }
        
        return implode('<br />', $formatted_lines);
    }

    /**
     * Close a list and return formatted HTML (Enhanced)
     *
     * @param array $items The list items
     * @param string $type The list type (ul or ol)
     * @param int $level The nesting level
     * @return string The formatted list HTML
     */
    private static function close_list($items, $type, $level = 0) {
        if (empty($items)) {
            return '';
        }
        
        $indent_class = $level > 0 ? " chat-list-nested chat-list-level-{$level}" : '';
        $html = "<{$type} class=\"chat-list{$indent_class}\">";
        foreach ($items as $item) {
            $html .= "<li>" . esc_html($item) . "</li>";
        }
        $html .= "</{$type}>";
        
        return $html;
    }

    /**
     * Process text formatting (Enhanced)
     *
     * @param string $text The text to format
     * @return string The formatted text
     */
    private static function process_text_formatting($text) {
        // Bold text: **text** or __text__
        $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.*?)__/', '<strong>$1</strong>', $text);
        
        // Italic text: *text* or _text_
        $text = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text);
        $text = preg_replace('/_([^_]+)_/', '<em>$1</em>', $text);
        
        // Strikethrough text: ~~text~~
        $text = preg_replace('/~~(.*?)~~/', '<del>$1</del>', $text);
        
        // Inline code: `code`
        $text = preg_replace('/`([^`]+)`/', '<code class="chat-inline-code">$1</code>', $text);
        
        // Highlighted text: ==text==
        $text = preg_replace('/==(.*?)==/', '<mark>$1</mark>', $text);
        
        return $text;
    }

    /**
     * Process code blocks (Enhanced)
     *
     * @param string $text The text containing code blocks
     * @return string The text with formatted code blocks
     */
    private static function process_code_blocks($text) {
        // Code blocks with language specification: ```language code```
        $text = preg_replace_callback('/```(\w+)?\s*\n?([^`]+)```/', function($matches) {
            $language = !empty($matches[1]) ? $matches[1] : '';
            $code = trim($matches[2]);
            $language_class = $language ? " language-{$language}" : '';
            
            return '<pre class="chat-code-block"><code class="chat-code' . $language_class . '">' . esc_html($code) . '</code></pre>';
        }, $text);
        
        return $text;
    }

    /**
     * Process quote blocks (Enhanced)
     *
     * @param string $text The text containing quotes
     * @return string The text with formatted quotes
     */
    private static function process_quotes($text) {
        // Quote blocks: lines starting with >
        $lines = explode('<br />', $text);
        $formatted_lines = [];
        $in_quote = false;
        $quote_content = [];
        
        foreach ($lines as $line) {
            if (preg_match('/^[\s]*>[\s]*(.+)$/', $line, $matches)) {
                if (!$in_quote) {
                    $in_quote = true;
                }
                $quote_content[] = trim($matches[1]);
            } else {
                if ($in_quote) {
                    $formatted_lines[] = self::close_quote($quote_content);
                    $quote_content = [];
                    $in_quote = false;
                }
                $formatted_lines[] = $line;
            }
        }
        
        if ($in_quote) {
            $formatted_lines[] = self::close_quote($quote_content);
        }
        
        return implode('<br />', $formatted_lines);
    }

    /**
     * Close a quote block and return formatted HTML (Enhanced)
     *
     * @param array $content The quote content
     * @return string The formatted quote HTML
     */
    private static function close_quote($content) {
        if (empty($content)) {
            return '';
        }
        
        $html = '<blockquote class="chat-quote">';
        $html .= '<p>' . esc_html(implode(' ', $content)) . '</p>';
        $html .= '</blockquote>';
        
        return $html;
    }

    /**
     * Process headings
     *
     * @param string $text The text containing headings
     * @return string The text with formatted headings
     */
    private static function process_headings($text) {
        // Headings: # H1, ## H2, ### H3, etc.
        $text = preg_replace('/^#\s+(.+)$/m', '<h1 class="chat-heading chat-h1">$1</h1>', $text);
        $text = preg_replace('/^##\s+(.+)$/m', '<h2 class="chat-heading chat-h2">$1</h2>', $text);
        $text = preg_replace('/^###\s+(.+)$/m', '<h3 class="chat-heading chat-h3">$1</h3>', $text);
        $text = preg_replace('/^####\s+(.+)$/m', '<h4 class="chat-heading chat-h4">$1</h4>', $text);
        $text = preg_replace('/^#####\s+(.+)$/m', '<h5 class="chat-heading chat-h5">$1</h5>', $text);
        $text = preg_replace('/^######\s+(.+)$/m', '<h6 class="chat-heading chat-h6">$1</h6>', $text);
        
        return $text;
    }

    /**
     * Process tables
     *
     * @param string $text The text containing tables
     * @return string The text with formatted tables
     */
    private static function process_tables($text) {
        // Simple table detection and formatting
        $lines = explode('<br />', $text);
        $formatted_lines = [];
        $in_table = false;
        $table_rows = [];
        
        foreach ($lines as $line) {
            if (preg_match('/^\|(.+)\|$/', $line)) {
                if (!$in_table) {
                    $in_table = true;
                }
                $table_rows[] = $line;
            } else {
                if ($in_table) {
                    $formatted_lines[] = self::close_table($table_rows);
                    $table_rows = [];
                    $in_table = false;
                }
                $formatted_lines[] = $line;
            }
        }
        
        if ($in_table) {
            $formatted_lines[] = self::close_table($table_rows);
        }
        
        return implode('<br />', $formatted_lines);
    }

    /**
     * Close a table and return formatted HTML
     *
     * @param array $rows The table rows
     * @return string The formatted table HTML
     */
    private static function close_table($rows) {
        if (empty($rows)) {
            return '';
        }
        
        $html = '<table class="chat-table">';
        $is_header = true;
        
        foreach ($rows as $row) {
            $cells = array_map('trim', explode('|', trim($row, '|')));
            $tag = $is_header ? 'th' : 'td';
            
            $html .= '<tr>';
            foreach ($cells as $cell) {
                $html .= "<{$tag}>" . esc_html($cell) . "</{$tag}>";
            }
            $html .= '</tr>';
            
            $is_header = false;
        }
        
        $html .= '</table>';
        return $html;
    }

    /**
     * Process callouts and alerts
     *
     * @param string $text The text containing callouts
     * @return string The text with formatted callouts
     */
    private static function process_callouts($text) {
        // Info callout: !info text
        $text = preg_replace('/!info\s+(.+)/', '<div class="chat-callout chat-info"><span class="callout-icon">ℹ</span><span class="callout-text">$1</span></div>', $text);
        
        // Warning callout: !warning text
        $text = preg_replace('/!warning\s+(.+)/', '<div class="chat-callout chat-warning"><span class="callout-icon">⚠</span><span class="callout-text">$1</span></div>', $text);
        
        // Error callout: !error text
        $text = preg_replace('/!error\s+(.+)/', '<div class="chat-callout chat-error"><span class="callout-icon">❌</span><span class="callout-text">$1</span></div>', $text);
        
        // Success callout: !success text
        $text = preg_replace('/!success\s+(.+)/', '<div class="chat-callout chat-success"><span class="callout-icon">✅</span><span class="callout-text">$1</span></div>', $text);
        
        return $text;
    }

    /**
     * Clean up HTML and ensure proper formatting (Enhanced)
     *
     * @param string $html The HTML to clean up
     * @return string The cleaned HTML
     */
    private static function cleanup_html($html) {
        // Remove empty paragraphs
        $html = preg_replace('/<p>\s*<\/p>/', '', $html);
        
        // Ensure proper spacing around lists, code blocks, and other elements
        $html = preg_replace('/<br \/>\s*(<[uo]l|<pre|<table|<blockquote|<h[1-6]|<div class="chat-callout)/', '$1', $html);
        $html = preg_replace('/(<\/[uo]l>|<\/pre>|<\/table>|<\/blockquote>|<\/h[1-6]>|<\/div>)\s*<br \/>/', '$1', $html);
        
        // Clean up multiple line breaks
        $html = preg_replace('/<br \/>\s*<br \/>\s*<br \/>+/', '<br /><br />', $html);
        
        // Add proper spacing between elements
        $html = preg_replace('/(<\/[uo]l>|<\/pre>|<\/table>|<\/blockquote>|<\/h[1-6]>|<\/div>)\s*(<[uo]l|<pre|<table|<blockquote|<h[1-6]|<div class="chat-callout)/', '$1<br />$2', $html);
        
        return $html;
    }

    /**
     * Get CSS styles for formatted content (Enhanced)
     *
     * @return string The CSS styles
     */
    public static function get_styles() {
        return '
        /* Enhanced Link Styles */
        .chat-link {
            color: #4F46E5;
            text-decoration: underline;
            word-break: break-all;
            transition: color 0.2s ease;
            position: relative;
        }
        .chat-link:hover {
            color: #3730A3;
            text-decoration: none;
        }
        .chat-link.external-link {
            color: #059669;
        }
        .chat-link.external-link:hover {
            color: #047857;
        }
        .external-link-icon {
            font-size: 0.8em;
            margin-left: 2px;
            opacity: 0.7;
        }

        /* Enhanced List Styles */
        .chat-list {
            margin: 12px 0;
            padding-left: 24px;
            line-height: 1.6;
        }
        .chat-list li {
            margin: 6px 0;
            line-height: 1.5;
            position: relative;
        }
        .chat-list-nested {
            margin: 4px 0;
            padding-left: 20px;
        }
        .chat-list-level-1 { padding-left: 16px; }
        .chat-list-level-2 { padding-left: 12px; }
        .chat-list-level-3 { padding-left: 8px; }

        /* Enhanced Text Formatting */
        .chat-inline-code {
            background-color: #F3F4F6;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: "SF Mono", Monaco, "Cascadia Code", "Roboto Mono", Consolas, "Courier New", monospace;
            font-size: 0.9em;
            color: #374151;
            border: 1px solid #E5E7EB;
        }
        .chat-inline-code:hover {
            background-color: #E5E7EB;
        }

        /* Enhanced Code Blocks */
        .chat-code-block {
            background-color: #1F2937;
            border: 1px solid #374151;
            border-radius: 8px;
            padding: 16px;
            margin: 16px 0;
            overflow-x: auto;
            font-family: "SF Mono", Monaco, "Cascadia Code", "Roboto Mono", Consolas, "Courier New", monospace;
            font-size: 0.9em;
            line-height: 1.5;
            position: relative;
        }
        .chat-code-block::before {
            content: "Code";
            position: absolute;
            top: 8px;
            right: 12px;
            font-size: 0.75em;
            color: #9CA3AF;
            font-weight: 500;
        }
        .chat-code {
            color: #F9FAFB;
            background: none;
            padding: 0;
            border: none;
            font-size: inherit;
        }

        /* Enhanced Quote Styles */
        .chat-quote {
            border-left: 4px solid #4F46E5;
            margin: 16px 0;
            padding: 12px 16px;
            background-color: #F8FAFC;
            border-radius: 0 8px 8px 0;
            position: relative;
        }
        .chat-quote::before {
            content: """;
            position: absolute;
            top: 8px;
            left: 8px;
            font-size: 2em;
            color: #4F46E5;
            opacity: 0.3;
        }
        .chat-quote p {
            margin: 0;
            padding-left: 20px;
            font-style: italic;
            color: #374151;
        }

        /* Heading Styles */
        .chat-heading {
            margin: 20px 0 12px 0;
            color: #111827;
            font-weight: 600;
            line-height: 1.3;
        }
        .chat-h1 { font-size: 1.5em; }
        .chat-h2 { font-size: 1.3em; }
        .chat-h3 { font-size: 1.1em; }
        .chat-h4 { font-size: 1em; }
        .chat-h5 { font-size: 0.9em; }
        .chat-h6 { font-size: 0.8em; }

        /* Table Styles */
        .chat-table {
            width: 100%;
            border-collapse: collapse;
            margin: 16px 0;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        .chat-table th {
            background-color: #F3F4F6;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #E5E7EB;
        }
        .chat-table td {
            padding: 12px;
            border-bottom: 1px solid #E5E7EB;
            color: #374151;
        }
        .chat-table tr:hover {
            background-color: #F9FAFB;
        }

        /* Callout Styles */
        .chat-callout {
            margin: 16px 0;
            padding: 12px 16px;
            border-radius: 8px;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            border-left: 4px solid;
        }
        .chat-callout .callout-icon {
            font-size: 1.2em;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .chat-callout .callout-text {
            flex: 1;
            line-height: 1.5;
        }
        .chat-info {
            background-color: #EFF6FF;
            border-left-color: #3B82F6;
            color: #1E40AF;
        }
        .chat-warning {
            background-color: #FFFBEB;
            border-left-color: #F59E0B;
            color: #92400E;
        }
        .chat-error {
            background-color: #FEF2F2;
            border-left-color: #EF4444;
            color: #991B1B;
        }
        .chat-success {
            background-color: #ECFDF5;
            border-left-color: #10B981;
            color: #065F46;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .chat-list {
                padding-left: 20px;
            }
            .chat-code-block {
                padding: 12px;
                font-size: 0.85em;
            }
            .chat-table {
                font-size: 0.9em;
            }
            .chat-table th,
            .chat-table td {
                padding: 8px;
            }
            .chat-heading {
                margin: 16px 0 8px 0;
            }
        }

        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            .chat-inline-code {
                background-color: #374151;
                color: #F9FAFB;
                border-color: #4B5563;
            }
            .chat-code-block {
                background-color: #111827;
                border-color: #374151;
            }
            .chat-quote {
                background-color: #1F2937;
                color: #F9FAFB;
            }
            .chat-table {
                background-color: #1F2937;
            }
            .chat-table th {
                background-color: #374151;
                color: #F9FAFB;
                border-bottom-color: #4B5563;
            }
            .chat-table td {
                color: #F9FAFB;
                border-bottom-color: #4B5563;
            }
            .chat-table tr:hover {
                background-color: #374151;
            }
        }
        ';
    }

    /**
     * Process label-description patterns (e.g., "Return Policy: Items can be returned...")
     *
     * @param string $text The text to process
     * @return string The processed text
     */
    private static function process_label_description($text) {
        // Pattern to match label: description format
        // Matches text that has a colon followed by content, where the label part should be bold
        $text = preg_replace_callback(
            '/^([^:]+):\s*(.+)$/m',
            function($matches) {
                $label = trim($matches[1]);
                $description = trim($matches[2]);
                
                // Skip if it's already formatted or if it's a simple colon usage
                if (strpos($label, '<') !== false || strlen($label) < 2) {
                    return $matches[0];
                }
                
                // Skip common patterns that shouldn't be formatted
                $skip_patterns = [
                    '/^https?:\/\//i',  // URLs
                    '/^\d+$/',          // Just numbers
                    '/^[A-Z]{2,}$/',    // Just uppercase letters (like abbreviations)
                    '/^[a-z]{1,3}$/i'   // Very short words
                ];
                
                foreach ($skip_patterns as $pattern) {
                    if (preg_match($pattern, $label)) {
                        return $matches[0];
                    }
                }
                
                return sprintf(
                    '<span class="chat-label">%s</span>: %s',
                    esc_html($label),
                    $description
                );
            },
            $text
        );
        
        return $text;
    }
}
