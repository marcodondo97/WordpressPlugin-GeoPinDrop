<?php
/**
 * Plugin Name: Geopindrop
 * Description: Plugin to manage and display geographic coordinates on OpenStreetMap with automatic geocoding.
 * Version: 1.0
 * Author: Marco Dondo
 * Text Domain: geopindrop
 * Plugin URI: https://github.com/marcodondo97/WordpressPlugin-Geopindrop
 * Author URI: https://github.com/marcodondo97
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin activation - create table
register_activation_hook(__FILE__, 'osm_plugin_activate');

function osm_plugin_activate() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'osm_coordinates';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        surname varchar(255) NOT NULL,
        info text,
        address varchar(255) NOT NULL,
        city varchar(255) NOT NULL,
        latitude varchar(255) NOT NULL,
        longitude varchar(255) NOT NULL,
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Add admin menu
add_action('admin_menu', 'osm_admin_menu');

function osm_admin_menu() {
    add_menu_page(
        'Geopindrop',
        'Geopindrop',
        'manage_options',
        'osm-coordinates',
        'osm_admin_page',
        'dashicons-location',
        30
    );
}

// Enqueue admin scripts
add_action('admin_enqueue_scripts', 'osm_admin_scripts');

function osm_admin_scripts($hook) {
    if ($hook != 'toplevel_page_osm-coordinates') {
        return;
    }
    
    wp_enqueue_script('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true);
    wp_enqueue_style('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4');
    wp_enqueue_script('jquery');
    wp_enqueue_script('jquery-ui-autocomplete');
    wp_enqueue_style('jquery-ui', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
}

// AJAX handler for address autocomplete
add_action('wp_ajax_osm_autocomplete', 'osm_autocomplete_handler');

function osm_autocomplete_handler() {
    $query = sanitize_text_field($_POST['query']);
    
    if (empty($query) || strlen($query) < 3) {
        wp_send_json_error('Query too short');
        return;
    }
    
    $url = 'https://nominatim.openstreetmap.org/search?q=' . urlencode($query) . '&format=json&limit=5&addressdetails=1';
    
    $response = wp_remote_get($url, array(
        'timeout' => 10,
        'user-agent' => 'WordPress OSM Plugin'
    ));
    
    if (is_wp_error($response)) {
        wp_send_json_error('Error fetching suggestions');
        return;
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body);
    
    if (empty($data)) {
        wp_send_json_error('No suggestions found');
        return;
    }
    
    $suggestions = array();
    foreach ($data as $item) {
        $display_name = isset($item->display_name) ? $item->display_name : '';
        $address = isset($item->address) ? $item->address : array();
        
        $street = isset($address->road) ? $address->road : '';
        $house_number = isset($address->house_number) ? $address->house_number : '';
        $city = isset($address->city) ? $address->city : (isset($address->town) ? $address->town : '');
        
        $suggestions[] = array(
            'label' => $display_name,
            'value' => $display_name,
            'street' => $street,
            'house_number' => $house_number,
            'city' => $city,
            'lat' => $item->lat,
            'lon' => $item->lon
        );
    }
    
    wp_send_json_success($suggestions);
}

// AJAX handler for geocoding
add_action('wp_ajax_osm_geocode', 'osm_geocode_handler');

function osm_geocode_handler() {
    try {
        $address = sanitize_text_field($_POST['address']);
        $city = sanitize_text_field($_POST['city']);
        
        if (empty($address) || empty($city)) {
            wp_send_json_error('Address and city are required');
            return;
        }
        
        $query = $address . ', ' . $city;
        $url = 'https://nominatim.openstreetmap.org/search?q=' . urlencode($query) . '&format=json&limit=1';
        
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'user-agent' => 'WordPress OSM Plugin'
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error('Error fetching coordinates');
            return;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);
        
        if (empty($data)) {
            wp_send_json_error('Address not found. Please check the address and city.');
            return;
        }
        
        wp_send_json_success(array(
            'latitude' => $data[0]->lat,
            'longitude' => $data[0]->lon
        ));
        
    } catch (Exception $e) {
        wp_send_json_error('Unexpected error occurred');
    }
}

// AJAX handler for deleting coordinates
add_action('wp_ajax_osm_delete_coordinate', 'osm_delete_coordinate_handler');

function osm_delete_coordinate_handler() {
    try {
        $id = intval($_POST['id']);
        
        if ($id <= 0) {
            wp_send_json_error('Invalid coordinate ID');
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'osm_coordinates';
        
        $result = $wpdb->delete($table_name, array('id' => $id), array('%d'));
        
        if ($result === false) {
            wp_send_json_error('Database error');
            return;
        }
        
        if ($result === 0) {
            wp_send_json_error('Coordinate not found');
            return;
        }
        
        wp_send_json_success('Coordinate deleted successfully');
        
    } catch (Exception $e) {
        wp_send_json_error('Unexpected error occurred');
    }
}

// AJAX handler for adding coordinates
add_action('wp_ajax_osm_add_coordinate', 'osm_add_coordinate_handler');

function osm_add_coordinate_handler() {
    try {
        $name = sanitize_text_field($_POST['name']);
        $surname = sanitize_text_field($_POST['surname']);
        $info = sanitize_text_field($_POST['info']);
        $address = sanitize_text_field($_POST['address']);
        $city = sanitize_text_field($_POST['city']);
        
        if (empty($name) || empty($surname) || empty($address) || empty($city)) {
            wp_send_json_error('All required fields must be filled');
            return;
        }
        
        $query = $address . ', ' . $city;
        $url = 'https://nominatim.openstreetmap.org/search?q=' . urlencode($query) . '&format=json&limit=1';
        
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'user-agent' => 'WordPress OSM Plugin'
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error('Error fetching coordinates');
            return;
        }
        
        $data = json_decode(wp_remote_retrieve_body($response));
        
        if (empty($data)) {
            wp_send_json_error('Address not found. Please check the address and city.');
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'osm_coordinates';
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'name' => $name,
                'surname' => $surname,
                'info' => $info,
                'address' => $address,
                'city' => $city,
                'latitude' => $data[0]->lat,
                'longitude' => $data[0]->lon
            )
        );
        
        if ($result === false) {
            wp_send_json_error('Database error');
            return;
        }
        
        $new_id = $wpdb->insert_id;
        
        wp_send_json_success(array(
            'message' => 'Coordinate added successfully!',
            'id' => $new_id,
            'latitude' => $data[0]->lat,
            'longitude' => $data[0]->lon
        ));
        
    } catch (Exception $e) {
        wp_send_json_error('Unexpected error occurred');
    }
}

// Admin page content
function osm_admin_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'osm_coordinates';
    
    // Get existing coordinates
    $coordinates = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");
    
    ?>
    <div class="wrap">
        <h1>Geopindrop Management</h1>
        
        <div style="display: flex; gap: 20px; margin-bottom: 20px;">
            <!-- Add new coordinate -->
            <div style="flex: 1;">
                <h2>Add New Coordinate</h2>
                <form method="post" id="osm-form">
                    <table class="form-table">
                        <tr>
                            <th><label for="name">Name *</label></th>
                            <td><input type="text" name="name" id="name" required></td>
                        </tr>
                        <tr>
                            <th><label for="surname">Surname *</label></th>
                            <td><input type="text" name="surname" id="surname" required></td>
                        </tr>
                        <tr>
                            <th><label for="info">Info</label></th>
                            <td><input type="text" name="info" id="info"></td>
                        </tr>
                        <tr>
                            <th><label for="address">Address *</label></th>
                            <td>
                                <input type="text" name="address" id="address" placeholder="Start typing address..." required>
                                <div id="address-suggestions" style="display:none; position:absolute; background:white; border:1px solid #ccc; max-height:200px; overflow-y:auto; z-index:1000;"></div>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="city">City *</label></th>
                            <td><input type="text" name="city" id="city" placeholder="e.g., Rome" required></td>
                        </tr>
                    </table>
                    <p><input type="submit" name="submit_coordinate" class="button button-primary" value="Add Coordinate"></p>
                </form>
            </div>
            
            <!-- Map preview -->
            <div style="flex: 1;">
                <h2>Map Preview</h2>
                <div id="osm-admin-map" style="width:100%;height:300px;"></div>
            </div>
        </div>
        
        <!-- Existing coordinates -->
        <div>
            <h2>Existing Coordinates</h2>
            <?php if (empty($coordinates)): ?>
                <p>No coordinates found.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Address</th>
                            <th>City</th>
                            <th>Coordinates</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($coordinates as $coord): ?>
                        <tr>
                            <td><?php echo esc_html($coord->name . ' ' . $coord->surname); ?></td>
                            <td><?php echo esc_html($coord->address); ?></td>
                            <td><?php echo esc_html($coord->city); ?></td>
                            <td><?php echo esc_html($coord->latitude . ', ' . $coord->longitude); ?></td>
                            <td><?php echo esc_html($coord->created_at); ?></td>
                            <td>
                                <button class="button button-small button-link-delete delete-coordinate" data-id="<?php echo esc_attr($coord->id); ?>">Delete</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <div style="margin-top: 20px;">
            <h3>Shortcode Usage</h3>
            <p>Use this shortcode to display the map on your pages: <code>[osm_map]</code></p>
        </div>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        // Get all coordinates for map bounds calculation
        var coordinates = [
            <?php foreach ($coordinates as $coord): ?>
            {
                lat: parseFloat(<?php echo esc_js($coord->latitude); ?>),
                lon: parseFloat(<?php echo esc_js($coord->longitude); ?>),
                name: '<?php echo esc_js($coord->name . ' ' . $coord->surname); ?>',
                address: '<?php echo esc_js($coord->address . ', ' . $coord->city); ?>',
                info: '<?php echo esc_js($coord->info); ?>'
            },
            <?php endforeach; ?>
        ];
        
        // Initialize admin map
        var adminMap = L.map('osm-admin-map');
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap contributors'
        }).addTo(adminMap);
        
        // Add markers and calculate bounds
        var bounds = L.latLngBounds();
        var hasMarkers = false;
        
        coordinates.forEach(function(coord) {
            if (!isNaN(coord.lat) && !isNaN(coord.lon)) {
                var marker = L.marker([coord.lat, coord.lon]).addTo(adminMap);
                var popupContent = '<b>' + coord.name + '</b><br>' + coord.address;
                if (coord.info) {
                    popupContent += '<br>' + coord.info;
                }
                marker.bindPopup(popupContent);
                
                bounds.extend([coord.lat, coord.lon]);
                hasMarkers = true;
            }
        });
        
        // Set map view based on coordinates
        if (hasMarkers && coordinates.length > 0) {
            if (coordinates.length === 1) {
                adminMap.setView([coordinates[0].lat, coordinates[0].lon], 10);
            } else {
                adminMap.fitBounds(bounds, { padding: [20, 20] });
            }
        } else {
            adminMap.setView([50.0, 10.0], 4);
        }
        
        // Address autocomplete
        var addressInput = $('#address');
        var suggestionsDiv = $('#address-suggestions');
        var autocompleteTimeout;
        
        if (addressInput.length && suggestionsDiv.length) {
            addressInput.on('input', function() {
                clearTimeout(autocompleteTimeout);
                var query = $(this).val();
                
                if (query.length < 3) {
                    suggestionsDiv.hide();
                    return;
                }
                
                autocompleteTimeout = setTimeout(function() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'osm_autocomplete',
                            query: query
                        },
                        success: function(response) {
                            if (response.success && response.data) {
                                displaySuggestions(response.data);
                            } else {
                                suggestionsDiv.hide();
                            }
                        },
                        error: function() {
                            suggestionsDiv.hide();
                        }
                    });
                }, 300);
            });
        }
        
        function displaySuggestions(suggestions) {
            if (!Array.isArray(suggestions)) {
                suggestionsDiv.hide();
                return;
            }
            
            suggestionsDiv.empty();
            
            suggestions.forEach(function(suggestion) {
                if (suggestion && suggestion.label) {
                    var item = $('<div class="suggestion-item" style="padding:8px; cursor:pointer; border-bottom:1px solid #eee;"></div>');
                    item.text(suggestion.label);
                    item.on('click', function() {
                        var street = suggestion.street || '';
                        var houseNumber = suggestion.house_number || '';
                        var city = suggestion.city || '';
                        
                        addressInput.val(street + (houseNumber ? ' ' + houseNumber : ''));
                        $('#city').val(city);
                        suggestionsDiv.hide();
                    });
                    suggestionsDiv.append(item);
                }
            });
            
            if (suggestions.length > 0) {
                suggestionsDiv.show();
            } else {
                suggestionsDiv.hide();
            }
        }
        
        // Hide suggestions when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('#address, #address-suggestions').length) {
                suggestionsDiv.hide();
            }
        });
        
        // Form submission
        var osmForm = $('#osm-form');
        if (osmForm.length) {
            osmForm.on('submit', function(e) {
                e.preventDefault();
                
                var address = $('#address').val().trim();
                var city = $('#city').val().trim();
                var name = $('#name').val().trim();
                var surname = $('#surname').val().trim();
                var info = $('#info').val().trim();
                
                if (!name || !surname || !address || !city) {
                    alert('Please fill in all required fields (Name, Surname, Address, City)');
                    return false;
                }
                
                var submitButton = $('input[name="submit_coordinate"]');
                var originalText = submitButton.val();
                submitButton.val('Processing...').prop('disabled', true);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'osm_add_coordinate',
                        name: name,
                        surname: surname,
                        info: info,
                        address: address,
                        city: city
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            var lat = parseFloat(response.data.latitude);
                            var lon = parseFloat(response.data.longitude);
                            
                            if (!isNaN(lat) && !isNaN(lon)) {
                                var newMarker = L.marker([lat, lon]).addTo(adminMap);
                                var popupContent = '<b>' + name + ' ' + surname + '</b><br>' + address + ', ' + city;
                                if (info) {
                                    popupContent += '<br>' + info;
                                }
                                newMarker.bindPopup(popupContent);
                                
                                bounds.extend([lat, lon]);
                                adminMap.fitBounds(bounds, { padding: [20, 20] });
                            }
                            
                            osmForm[0].reset();
                            location.reload();
                        } else {
                            alert('Error: ' + (response.data || 'Unknown error occurred'));
                        }
                        
                        submitButton.val(originalText).prop('disabled', false);
                    },
                    error: function() {
                        alert('Error saving coordinate. Please try again.');
                        submitButton.val(originalText).prop('disabled', false);
                    }
                });
            });
        }
        
        // Delete coordinate
        $(document).on('click', '.delete-coordinate', function() {
            if (!confirm('Are you sure you want to delete this coordinate?')) {
                return;
            }
            
            var button = $(this);
            var id = button.data('id');
            
            if (!id || isNaN(parseInt(id))) {
                alert('Invalid coordinate ID');
                return;
            }
            
            button.prop('disabled', true).text('Deleting...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'osm_delete_coordinate',
                    id: id
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + (response.data || 'Unknown error occurred'));
                        button.prop('disabled', false).text('Delete');
                    }
                },
                error: function() {
                    alert('Error deleting coordinate. Please try again.');
                    button.prop('disabled', false).text('Delete');
                }
            });
        });
    });
    </script>
    
    <style>
    .suggestion-item:hover {
        background-color: #f0f0f0;
    }
    #address-suggestions {
        max-width: 400px;
    }
    </style>
    <?php
}

// Add shortcode for map display
add_shortcode('osm_map', 'osm_map_shortcode');

function osm_map_shortcode($atts) {
    wp_enqueue_script('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true);
    wp_enqueue_style('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4');
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'osm_coordinates';
    $coordinates = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");
    
    ob_start();
    ?>
    <div id="osm-map" style="width:100%;height:500px;"></div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var coordinates = [
            <?php foreach ($coordinates as $coord): ?>
            {
                lat: parseFloat(<?php echo esc_js($coord->latitude); ?>),
                lon: parseFloat(<?php echo esc_js($coord->longitude); ?>),
                name: '<?php echo esc_js($coord->name . ' ' . $coord->surname); ?>',
                address: '<?php echo esc_js($coord->address . ', ' . $coord->city); ?>',
                info: '<?php echo esc_js($coord->info); ?>'
            },
            <?php endforeach; ?>
        ];
        
        var map = L.map('osm-map');
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);
        
        var bounds = L.latLngBounds();
        var hasMarkers = false;
        
        coordinates.forEach(function(coord) {
            if (!isNaN(coord.lat) && !isNaN(coord.lon)) {
                var marker = L.marker([coord.lat, coord.lon]).addTo(map);
                var popupContent = '<b>' + coord.name + '</b><br>' + coord.address;
                if (coord.info) {
                    popupContent += '<br>' + coord.info;
                }
                marker.bindPopup(popupContent);
                
                bounds.extend([coord.lat, coord.lon]);
                hasMarkers = true;
            }
        });
        
        if (hasMarkers && coordinates.length > 0) {
            if (coordinates.length === 1) {
                map.setView([coordinates[0].lat, coordinates[0].lon], 10);
            } else {
                map.fitBounds(bounds, { padding: [20, 20] });
            }
        } else {
            map.setView([50.0, 10.0], 4);
        }
    });
    </script>
    <?php
    
    return ob_get_clean();
}
