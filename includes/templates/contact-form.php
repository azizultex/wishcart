<?php
if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Template Name: Aisk Contact Form
 *
 * @category Template
 * @package  Aisk
 * @author   Aisk Team <support@aisk.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://aisk.chat
 *
 * @since    1.0.0
 * @requires PHP 7.4
 */

// Disable admin bar and unnecessary elements
add_filter('show_admin_bar', '__return_false');
remove_action('wp_head', '_admin_bar_bump_cb');

// Enqueue contact form styles and scripts
function aisk_contact_form_enqueue_assets() {
    // Register and enqueue styles
    wp_register_style(
        'aisk-contact-form-styles',
        false,
        array(),
        defined('AISK_VERSION') ? AISK_VERSION : '1.0.0'
    );

    $custom_css = '
        body {
            margin: 0;
            padding: 0;
            background: transparent;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: #333;
            overflow-x: hidden;
        }

        /* Form container */
        form {
            max-width: 100%;
            margin: 0;
            padding: 15px;
            background: transparent;
        }

        /* Form fields container */
        .form-fields {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
        }

        /* Form inputs */
        input[type="text"],
        input[type="email"],
        input[type="tel"],
        textarea {
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            background: white;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.2s ease;
            color: #333;
            margin: 0;
        }

        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="tel"]:focus,
        textarea:focus {
            outline: none;
            border-color: #3182ce;
            box-shadow: 0 0 0 2px rgba(49, 130, 206, 0.2);
        }

        /* Submit button */
        input[type="submit"],
        button[type="submit"] {
            background: #3182ce;
            color: white;
            padding: 10px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: background-color 0.2s ease;
            width: 100%;
            margin-top: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        input[type="submit"]:hover,
        button[type="submit"]:hover {
            background: #2c5282;
        }

        /* Labels */
        label {
            display: block;
            margin-bottom: 6px;
            color: #4a5568;
            font-size: 14px;
            font-weight: 500;
        }

        /* Remove any theme margins/padding */
        .entry-content,
        .post-content,
        .page-content,
        .form-container {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
            background: transparent !important;
        }

        /* Handle form validation messages */
        .wpcf7-response-output {
            margin: 10px 0 0 !important;
            padding: 10px !important;
            border-radius: 6px;
            font-size: 13px;
            border: 1px solid transparent !important;
        }
        
        .wpcf7-response-output.wpcf7-validation-errors {
            background-color: #fff3cd;
            border-color: #ffeeba !important;
            color: #856404;
        }
        
        .wpcf7-response-output.wpcf7-mail-sent-ok {
            background-color: #d4edda;
            border-color: #c3e6cb !important;
            color: #155724;
        }

        .wpcf7-not-valid-tip {
            color: #dc3545;
            font-size: 12px;
            margin-top: 4px;
            display: block;
        }

        /* Make textareas reasonable size */
        textarea {
            height: 120px !important;
            resize: vertical;
            min-height: 80px;
            max-height: 200px;
        }

        /* Form plugin specific fixes */
        .wpforms-container,
        .gform_wrapper,
        .wpcf7-form {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
            background: transparent !important;
        }
        
        /* Add field highlight effect */
        .form-field {
            position: relative;
            transition: transform 0.2s;
        }
        
        .form-field:focus-within {
            transform: translateY(-2px);
        }
        
        /* Add extra styling for specific form plugins */
        
        /* Contact Form 7 */
        .wpcf7-form p {
            margin: 0 0 12px;
        }
        
        .wpcf7-form-control-wrap {
            display: block;
            margin-top: 4px;
        }
        
        /* WPForms */
        .wpforms-field {
            padding: 0 0 12px !important;
        }
        
        .wpforms-field-label {
            margin-bottom: 6px !important;
        }
        
        /* Gravity Forms */
        .gform_fields {
            grid-row-gap: 12px !important;
        }
        
        .gfield_label {
            margin-bottom: 6px !important;
        }
        
        /* Success messages */
        .form-success {
            text-align: center;
            padding: 30px 0;
        }
        
        .form-success h3 {
            color: #38a169;
            margin-bottom: 10px;
        }
        
        .form-success p {
            color: #4a5568;
        }
    ';

    wp_add_inline_style('aisk-contact-form-styles', wp_strip_all_tags($custom_css));
    wp_enqueue_style('aisk-contact-form-styles');

    // Register and enqueue scripts
    wp_register_script(
        'aisk-contact-form-scripts',
        false,
        [],
        AISK_VERSION,
        true
    );

    $custom_js = "
        // Function to calculate and send height to parent
        function updateHeight() {
            const height = document.documentElement.scrollHeight;
            window.parent.postMessage({ frameHeight: height }, '*');
        }

        // Update height on load and after any content changes
        window.addEventListener('load', updateHeight);
        window.addEventListener('resize', updateHeight);

        // Use MutationObserver to watch for DOM changes
        const observer = new MutationObserver(updateHeight);
        observer.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            characterData: true
        });

        // Form specific scripts
        document.addEventListener('DOMContentLoaded', function() {
            // Add animation for form fields focus
            const formFields = document.querySelectorAll('input[type=\"text\"], input[type=\"email\"], input[type=\"tel\"], textarea');
            formFields.forEach(field => {
                const wrapper = document.createElement('div');
                wrapper.className = 'form-field';
                field.parentNode.insertBefore(wrapper, field);
                wrapper.appendChild(field);
            });
            
            // Contact Form 7 Events
            if (typeof wpcf7 !== 'undefined') {
                document.addEventListener('wpcf7submit', function(event) {
                    updateHeight();
                    setTimeout(updateHeight, 300);
                });
                document.addEventListener('wpcf7invalid', updateHeight);
                document.addEventListener('wpcf7mailsent', function(event) {
                    const form = event.detail.apiResponse ? event.target : event.srcElement;
                    const container = form.closest('.wpcf7');
                    
                    if (container) {
                        // Create success message
                        const successDiv = document.createElement('div');
                        successDiv.className = 'form-success';
                        successDiv.innerHTML = '<h3>Message Sent!</h3><p>Thank you for contacting us. We will get back to you shortly.</p>';
                        
                        // Replace form with success message
                        container.innerHTML = '';
                        container.appendChild(successDiv);
                        updateHeight();
                    }
                });
            }

            // WPForms
            if (typeof wpforms !== 'undefined') {
                document.addEventListener('wpformsAjaxSubmitSuccess', function(event) {
                    updateHeight();
                    setTimeout(updateHeight, 300);
                });
                document.addEventListener('wpformsAjaxSubmitFailed', updateHeight);
            }

            // Gravity Forms
            if (typeof gform !== 'undefined') {
                document.addEventListener('gform_post_render', updateHeight);
                gform.addAction('gform_confirmation_loaded', function() {
                    updateHeight();
                    setTimeout(updateHeight, 300);
                });
            }
        });
    ";

    wp_add_inline_script('aisk-contact-form-scripts', esc_js($custom_js));
    wp_enqueue_script('aisk-contact-form-scripts');
}
add_action('wp_enqueue_scripts', 'aisk_contact_form_enqueue_assets');

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php
// Get and render the shortcode
$settings = get_option('aisk_settings', []);
$shortcode = $settings['integrations']['contact_form']['shortcode'];
if ($shortcode) {
    echo do_shortcode($shortcode);
}
?>
<?php wp_footer(); ?>
</body>
</html>