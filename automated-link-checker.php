<?php
/*
Plugin Name:  Automated Link Checker
Plugin URI:   https://github.com/westcoastdigital/Automated-Link-Checker
Description:  Automatically checks for broken internal and external links, images and PDFs.
Version:      1.0.1
Author:       Jon Mather
Author URI:   https://jonmather.au
License:      GPL v2 or later
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  translate
Domain Path:  /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define( 'ALC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ALC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Register the activation hook
register_activation_hook( __FILE__, 'alc_activate_plugin' );

// Plugin activation function
function alc_activate_plugin() {
    $interval_value = get_option('alc_cron_interval_value', 1);
    $interval_unit = get_option('alc_cron_interval_unit', 'daily');
    $run_hour = get_option('alc_run_hour', '00');
    $run_minute = get_option('alc_run_minute', '00');

    // Convert the user-selected interval into a WordPress-supported cron schedule
    $cron_schedule = alc_get_cron_schedule_name($interval_value, $interval_unit);

    // Remove the old scheduled event if it exists
    if (wp_next_scheduled('alc_check_broken_links')) {
        wp_clear_scheduled_hook('alc_check_broken_links');
    }

    // Set the first run time
    $first_run = time(); // Default to now
    
    // If using daily/weekly/monthly/yearly schedule, set specific time
    if (in_array($interval_unit, ['daily', 'weekly', 'monthly', 'yearly'])) {
        // Get current date
        $current_date = date('Y-m-d');
        $current_time = time();
        
        // Create timestamp for today at the specified time
        $target_time = strtotime("$current_date $run_hour:$run_minute:00");
        
        // If that time already passed today, schedule for tomorrow
        if ($target_time <= $current_time) {
            $target_time = strtotime("tomorrow $run_hour:$run_minute:00");
        }
        
        $first_run = $target_time;
    }

    // Schedule the new event with the custom interval
    wp_schedule_event($first_run, $cron_schedule, 'alc_check_broken_links');
}

// Map user input to a WordPress cron schedule
function alc_get_cron_schedule_name( $value, $unit ) {
    $schedule = '';

    switch ( $unit ) {
        case 'seconds':
            $schedule = 'every_second';
            break;
        case 'minutes':
            $schedule = 'every_minute';
            break;
        case 'hours':
            $schedule = '1hour';
            break;
        case 'daily':
            $schedule = 'daily';
            break;
        case 'weekly':
            $schedule = 'weekly';
            break;
        case 'monthly':
            $schedule = 'monthly';
            break;
        case 'yearly':
            $schedule = 'yearly';
            break;
        default:
            $schedule = 'daily';
    }

    return $schedule;
}

// Add custom cron intervals
add_filter( 'cron_schedules', 'alc_add_custom_cron_intervals' );

function alc_add_custom_cron_intervals( $schedules ) {
    // Add a custom interval for 1 hour
    $schedules['1hour'] = array(
        'interval' => 3600, // 3600 seconds = 1 hour
        'display'  => __( 'Once Every Hour' ),
    );

    // You can also add other intervals if necessary (for example, 5 minutes, etc.)
    $schedules['5minutes'] = array(
        'interval' => 300, // 300 seconds = 5 minutes
        'display'  => __( 'Once Every 5 Minutes' ),
    );

    $schedules['every_minute'] = [
        'interval' => 60,
        'display'  => __('Once Every Minute'),
    ];
    $schedules['every_second'] = [
        'interval' => 1,
        'display'  => __('Every Second'),
    ];

    return $schedules;
}

// Register the deactivation hook
register_deactivation_hook( __FILE__, 'alc_deactivate_plugin' );

// Plugin deactivation function
function alc_deactivate_plugin() {
    // Clear scheduled task (cron job)
    wp_clear_scheduled_hook( 'alc_check_broken_links' );
}

// Hook for link checking process
add_action( 'alc_check_broken_links', 'alc_check_broken_links_function' );

// Main function to check for broken links in both post content and ACF fields
function alc_check_broken_links_function() {
    global $wpdb;

    // Clear previous entries from the broken links table
    alc_clear_broken_links_table();

    // Query to get all post URLs from post content
    $posts = $wpdb->get_results( "SELECT ID, post_content, post_name FROM {$wpdb->posts} WHERE post_status = 'publish'" );

    // Counter for broken links
    $broken_links_count = 0;

    // Iterate over posts and check each link in post content and meta
    foreach ( $posts as $post ) {
        // Get the URL of the post
        $post_url = get_permalink( $post->ID );
        
        // Check links in post content
        $content = $post->post_content;
        $broken_links_count += alc_check_links_in_content( $content, $post->ID, $post_url );

        // Check links in post meta (ACF fields or other custom fields)
        $meta_fields = get_post_meta( $post->ID );  // Get all meta fields for the post
        foreach ( $meta_fields as $key => $value ) {
            // Check if the value is an array, sometimes ACF fields return arrays
            if ( is_array( $value ) ) {
                foreach ( $value as $v ) {
                    // Update counter and post link
                    $broken_links_count += alc_check_links_in_content( $v, $post->ID, $post_url );
                }
            } else {
                // Update counter and post link
                $broken_links_count += alc_check_links_in_content( $value, $post->ID, $post_url );
            }
        }
        // error_log("Total broken links found[152]: $broken_links_count");
    }

    // If broken links were found, send an email
    if ( $broken_links_count > 0 ) {
        alc_send_broken_link_email_notification( $broken_links_count );
    }

}

// Function to extract and check links in post content or meta fields
function alc_check_links_in_content( $content, $post_id, $post_url ) {
    // Extract all URLs (both internal and external)
    preg_match_all( '/https?\:\/\/[a-zA-Z0-9\-\._~\:\/\?#\[\]@!\$&\'\(\)\*\+,;=.]+/', $content, $matches );
    
    $urls = $matches[0];  // All links found in the content

    // Get the skip URLs from options
    $skip_urls_text = get_option( 'alc_skip_urls', '' );
    $skip_urls = array();

    // Convert textarea content to array, each URL on a new line
    if ( !empty( $skip_urls_text ) ) {
        $skip_urls = explode( "\n", $skip_urls_text );
        $skip_urls = array_map( 'trim', $skip_urls );
        $skip_urls = array_filter( $skip_urls );
    }

    // Counter for broken links
    $broken_links_count = 0;

    // Loop through all URLs and check if they are valid
    foreach ( $urls as $url ) {
        // Skip this URL if it's in the skip list
        if ( !empty( $skip_urls ) && in_array( $url, $skip_urls ) ) {
            continue; // Skip to the next URL
        }

        $is_broken = ! alc_is_valid_link( $url );
        
        // If the link is broken, log it
        if ( $is_broken ) {
            alc_log_broken_link( $post_id, $url, $post_url );
            // add to counter
            $broken_links_count++;
        }
    }

    // error_log("Total broken links found[183]: $broken_links_count");
    // return count
    return $broken_links_count;
}

// Function to validate a link
function alc_is_valid_link( $url ) {
    // Check if the link is a valid URL and not empty
    if ( empty( $url ) ) {
        return false;
    }

    // Check for missing images
    $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'pdf'];
    $file_extension = pathinfo($url, PATHINFO_EXTENSION);

    if ( in_array( strtolower( $file_extension ), $image_extensions ) ) {
        $response = wp_remote_get( $url );
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
            return false; // Image is broken
        }
    }

    // For all other types of URLs (non-images), perform the usual HTTP request check
    $response = wp_remote_get( $url );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
        return false;
    }

    return true;
}

// Function to log broken link info (could store it in a custom table)
function alc_log_broken_link( $post_id, $url, $post_url ) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'alc_broken_links';
    
    // Insert broken link details into a custom table
    $wpdb->insert(
        $table_name,
        array(
            'post_id'    => $post_id,
            'url'        => $url,
            'post_url'   => $post_url,
            'timestamp'  => current_time( 'mysql' ),
        )
    );
}

// Function to clear all entries from the broken links table
function alc_clear_broken_links_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'alc_broken_links';
    
    // Delete all records from the table before running the link checks
    $wpdb->query("DELETE FROM $table_name");
}

// Create the custom table for broken links upon plugin activation
function alc_create_broken_link_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'alc_broken_links';
    $charset_collate = $wpdb->get_charset_collate();

    // SQL to create the table
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) NOT NULL,
        url varchar(255) NOT NULL,
        post_url varchar(255) NOT NULL,  /* Store the source URL where the broken link was found */
        timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    // Run the SQL query to create the table
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    
    // Check for errors while creating the table
    $result = dbDelta( $sql );
    
    // Log any errors or messages
    if ( !empty( $result ) ) {
        error_log( 'Table creation result: ' . print_r( $result, true ) );
    }
}

// Hook to create the table on plugin activation
register_activation_hook( __FILE__, 'alc_create_broken_link_table' );

// Create admin menu item
add_action( 'admin_menu', 'alc_create_admin_menu' );

function alc_create_admin_menu() {
    add_menu_page( 
        'Broken Links', 
        'Broken Links', 
        'manage_options', 
        'alc_broken_links', 
        'alc_display_broken_links_page', 
        'dashicons-admin-links', 
        100 
    );
}

// Display broken links page in the admin area
function alc_display_broken_links_page() {
    global $wpdb;

    // Query for all broken links
    $table_name = $wpdb->prefix . 'alc_broken_links';
    $broken_links = $wpdb->get_results( "SELECT * FROM $table_name" );

    echo '<div class="wrap"><h2>Broken Links</h2><table class="widefat">';
    echo '<thead><tr><th>Post ID</th><th>URL</th><th>Post URL</th><th>Timestamp</th></tr></thead><tbody>';

    foreach ( $broken_links as $link ) {
        echo '<tr>';
        echo '<td>' . esc_html( $link->post_id ) . '</td>';
        echo '<td>' . esc_html( $link->url ) . '</td>';
        echo '<td><a href="' . esc_url( $link->post_url ) . '" target="_blank">' . esc_html( $link->post_url ) . '</a></td>';
        echo '<td>' . esc_html( $link->timestamp ) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

// Add Settings submenu under the plugin
add_action( 'admin_menu', 'alc_create_admin_settings_menu' );

function alc_create_admin_settings_menu() {
    add_submenu_page(
        'alc_broken_links',
        'Link Checker Settings',
        'Settings',
        'manage_options',
        'alc_link_checker_settings',
        'alc_display_settings_page'
    );
}

// Display settings page
function alc_display_settings_page() {
    ?>
    <div class="wrap">
        <h2>Automated Link Checker Settings</h2>
        <form method="post" action="options.php">
            <?php settings_fields( 'alc_settings_group' ); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Cron Time Interval</th>
                    <td>
                        <input type="number" name="alc_cron_interval_value" 
                               value="<?php echo esc_attr( get_option('alc_cron_interval_value', 1) ); ?>" 
                               min="1" />
                        <select name="alc_cron_interval_unit">
                            <option value="seconds" <?php selected( get_option('alc_cron_interval_unit'), 'seconds' ); ?>>Seconds</option>
                            <option value="minutes" <?php selected( get_option('alc_cron_interval_unit'), 'minutes' ); ?>>Minutes</option>
                            <option value="hours" <?php selected( get_option('alc_cron_interval_unit'), 'hours' ); ?>>Hours</option>
                            <option value="daily" <?php selected( get_option('alc_cron_interval_unit'), 'daily' ); ?>>Daily</option>
                            <option value="weekly" <?php selected( get_option('alc_cron_interval_unit'), 'weekly' ); ?>>Weekly</option>
                            <option value="monthly" <?php selected( get_option('alc_cron_interval_unit'), 'monthly' ); ?>>Monthly</option>
                            <option value="yearly" <?php selected( get_option('alc_cron_interval_unit'), 'yearly' ); ?>>Yearly</option>
                        </select>
                    </td>
                </tr>
                <!-- Run at specific time option -->
                <tr valign="top">
                    <th scope="row">Run at Specific Time</th>
                    <td>
                        <select name="alc_run_hour">
                            <?php
                            for ($i = 0; $i < 24; $i++) {
                                $hour = sprintf('%02d', $i);
                                echo '<option value="' . $hour . '" ' . selected(get_option('alc_run_hour', 00), $hour, false) . '>' . $hour . '</option>';
                            }
                            ?>
                        </select>
                        :
                        <select name="alc_run_minute">
                            <?php
                            for ($i = 0; $i < 60; $i += 5) {
                                $minute = sprintf('%02d', $i);
                                echo '<option value="' . $minute . '" ' . selected(get_option('alc_run_minute', 00), $minute, false) . '>' . $minute . '</option>';
                            }
                            ?>
                        </select>
                        <p class="description">Set the time when the link checker should run (server time). Only applies to daily, weekly, monthly, or yearly intervals.</p>
                        <p class="current-time">Current Server Time: <span id="server_time"><?= date('h:i:s a') ?></span></p>
                    </td>
                </tr>
                <!-- Custom Email Setting -->
                <tr valign="top">
                    <th scope="row">Notification Email Address</th>
                    <td>
                        <input type="email" name="alc_notification_email" 
                               value="<?php echo esc_attr( get_option('alc_notification_email', get_option('admin_email') ) ); ?>" />
                        <p class="description">Enter the email address to receive notifications about broken links. Leave empty to use the admin email.</p>
                    </td>
                </tr>
                <!-- Custom Ignore Setting -->
                <tr valign="top">
                    <th scope="row">Skip URLs</th>
                    <td>
                        <textarea type="email" name="alc_skip_urls"  rows="4" cols="50"><?php echo esc_attr( get_option('alc_skip_urls', '' ) ); ?></textarea>
                        <p class="description">Enter the one url per line to skip logging.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <script type="text/javascript">
    function updateServerTime() {
        var serverTime = document.getElementById('server_time');
        var time = new Date('<?php echo date('Y-m-d H:i:s'); ?>');
        
        setInterval(function() {
            time.setSeconds(time.getSeconds() + 1);
            var hours = time.getHours();
            var minutes = time.getMinutes();
            var seconds = time.getSeconds();
            var ampm = hours >= 12 ? 'pm' : 'am';
            
            hours = hours % 12;
            hours = hours ? hours : 12; // the hour '0' should be '12'
            minutes = minutes < 10 ? '0' + minutes : minutes;
            seconds = seconds < 10 ? '0' + seconds : seconds;
            
            serverTime.textContent = hours + ':' + minutes + ':' + seconds + ' ' + ampm;
        }, 1000);
    }

    // Run when the DOM is ready
    document.addEventListener('DOMContentLoaded', updateServerTime);
    </script>
    <?php
}

// Register plugin settings
add_action( 'admin_init', 'alc_register_settings' );

function alc_register_settings() {
    // Set default values if options don't exist
    if ( ! get_option( 'alc_cron_interval_value' ) ) {
        update_option( 'alc_cron_interval_value', 1 );
    }
    if ( ! get_option( 'alc_cron_interval_unit' ) ) {
        update_option( 'alc_cron_interval_unit', 'hours' );
    }
    // Default to admin email
    if ( ! get_option( 'alc_notification_email' ) ) {
        update_option( 'alc_notification_email', get_option( 'admin_email' ) );
    }
    // Update urls to skip
    if ( ! get_option( 'alc_skip_urls' ) ) {
        update_option( 'alc_skip_urls', get_option( 'alc_skip_urls' ) );
    }
    // set the hour
    if (!get_option('alc_run_hour')) {
        update_option('alc_run_hour', '00');
    }
    // set the minute
    if (!get_option('alc_run_minute')) {
        update_option('alc_run_minute', '00');
    }

    register_setting( 'alc_settings_group', 'alc_cron_interval_value' );
    register_setting( 'alc_settings_group', 'alc_cron_interval_unit' );
    register_setting( 'alc_settings_group', 'alc_notification_email' );
    register_setting( 'alc_settings_group', 'alc_skip_urls' );
    register_setting('alc_settings_group', 'alc_run_hour');
    register_setting('alc_settings_group', 'alc_run_minute');
}

add_action('update_option_alc_run_hour', 'alc_reschedule_cron', 10, 2);
add_action('update_option_alc_run_minute', 'alc_reschedule_cron', 10, 2);
add_action('update_option_alc_cron_interval_value', 'alc_reschedule_cron', 10, 2);
add_action('update_option_alc_cron_interval_unit', 'alc_reschedule_cron', 10, 2);

function alc_reschedule_cron($old_value, $new_value) {
    // Only reschedule if the value actually changed
    if ($old_value !== $new_value) {
        // Call the activation function which handles scheduling
        alc_activate_plugin();
    }
}

// Function to send the broken link email notification
function alc_send_broken_link_email_notification( $broken_links_count ) {
    // Get the email settings with admin as default
    $notification_email = get_option( 'alc_notification_email', get_option( 'admin_email' ) );
    // get the broken Links admin page URL
    $admin_url = admin_url( 'admin.php?page=alc_broken_links' );
    // get the site name
    $site_name = get_bloginfo('name');
    
    // Set up email subject and message
    $subject = $site_name . ": Broken Links Found";
    $message = sprintf(
        "There are %d broken links detected on your website.\n\nYou can review and manage them here:\n%s",
        $broken_links_count,
        $admin_url
    );

    // Send the email
    wp_mail( $notification_email, $subject, $message );
}