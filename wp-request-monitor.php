<?php

/**
 * Plugin Name: WP Request Monitor
 * * Description: Advanced request monitoring for WordPress. Track guest user activity, analyze traffic patterns, and monitor site usage with professional reporting tools.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class UserRequestLogger
{

    private $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'user_request_logs';

        // Hook into WordPress
        add_action('plugins_loaded', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'create_table'));
        register_deactivation_hook(__FILE__, array($this, 'cleanup'));
    }

    public function init()
    {
        // Only log frontend requests
        add_action('wp', array($this, 'log_frontend_requests'));

        // Admin interface
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // AJAX handlers for admin
        add_action('wp_ajax_get_request_logs', array($this, 'ajax_get_logs'));
        add_action('wp_ajax_clear_request_logs', array($this, 'ajax_clear_logs'));
        add_action('wp_ajax_get_log_details', array($this, 'ajax_get_log_details'));
    }

    public function create_table()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
            id int(11) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            method varchar(10) NOT NULL,
            url text NOT NULL,
            ip_address varchar(45) NOT NULL,
            browser varchar(255) DEFAULT NULL,
            device_type varchar(50) DEFAULT NULL,
            referer text DEFAULT NULL,
            status_code int(3) DEFAULT 200,
            user_agent text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY timestamp_idx (timestamp),
            KEY ip_idx (ip_address)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function cleanup()
    {
        // Optional: Remove table on deactivation
        // global $wpdb;
        // $wpdb->query("DROP TABLE IF EXISTS {$this->table_name}");
    }

    public function log_frontend_requests()
    {
        // Skip logging for admin, login, AJAX requests, and logged-in users
        if (is_admin() || wp_doing_ajax() || $this->is_login_page() || is_user_logged_in()) {
            return;
        }

        global $wpdb;

        $data = array(
            'method' => $_SERVER['REQUEST_METHOD'],
            'url' => $this->get_current_url(),
            'ip_address' => $this->get_user_ip(),
            'browser' => $this->get_browser_name($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'device_type' => $this->get_device_type($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'referer' => $_SERVER['HTTP_REFERER'] ?? 'Direct',
            'status_code' => http_response_code() ?: 200,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        );

        $wpdb->insert($this->table_name, $data);
    }

    private function get_current_url()
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $uri = $_SERVER['REQUEST_URI'];
        return $protocol . '://' . $host . $uri;
    }

    private function get_user_ip()
    {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );

        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    }

    private function get_browser_name($user_agent)
    {
        $browsers = array(
            'Chrome' => '/Chrome\/([0-9.]+)/',
            'Firefox' => '/Firefox\/([0-9.]+)/',
            'Safari' => '/Safari\/([0-9.]+)/',
            'Edge' => '/Edg\/([0-9.]+)/',
            'Opera' => '/Opera\/([0-9.]+)/',
            'Internet Explorer' => '/MSIE ([0-9.]+)/'
        );

        foreach ($browsers as $browser => $pattern) {
            if (preg_match($pattern, $user_agent, $matches)) {
                return $browser . ' ' . $matches[1];
            }
        }

        return 'Unknown';
    }

    private function get_device_type($user_agent)
    {
        if (preg_match('/Mobile|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i', $user_agent)) {
            if (preg_match('/iPad/i', $user_agent)) {
                return 'Tablet';
            }
            return 'Mobile';
        }
        return 'Desktop';
    }

    private function is_login_page()
    {
        return in_array($GLOBALS['pagenow'], array('wp-login.php', 'wp-register.php'));
    }

    public function add_admin_menu()
    {
        add_management_page(
            'User Request Logs',
            'User Logs',
            'manage_options',
            'user-request-logs',
            array($this, 'admin_page')
        );
    }

    public function enqueue_admin_scripts($hook)
    {
        if ($hook !== 'tools_page_user-request-logs') {
            return;
        }

        wp_enqueue_script('jquery');
    }

    public function admin_page()
    {
?>
        <div class="wrap">
            <h1>User Request Logs</h1>

            <div class="tablenav top">
                <div class="alignleft actions">
                    <input type="text" id="search-logs" placeholder="Search all data..." style="width: 300px;">
                    <select id="filter-device">
                        <option value="">All Devices</option>
                        <option value="Desktop">Desktop</option>
                        <option value="Mobile">Mobile</option>
                        <option value="Tablet">Tablet</option>
                    </select>
                    <button type="button" id="apply-filters" class="button">Apply Filters</button>
                    <button type="button" id="clear-filters" class="button">Clear</button>
                </div>
                <div class="alignright actions">
                    <button type="button" id="clear-all-logs" class="button button-secondary">Clear All Logs</button>
                    <button type="button" id="refresh-logs" class="button button-primary">Refresh</button>
                </div>
            </div>

            <div id="logs-loading" style="display: none;">Loading...</div>

            <table class="wp-list-table widefat fixed striped" id="logs-table">
                <thead>
                    <tr>
                        <th scope="col" class="sortable" data-sort="timestamp" style="width: 160px;">Timestamp</th>
                        <th scope="col" class="sortable" data-sort="ip_address" style="width: 120px;">IP Address</th>
                        <th scope="col" class="sortable" data-sort="url">URL</th>
                        <th scope="col" class="sortable" data-sort="status_code" style="width: 80px;">Status</th>
                        <th scope="col" class="sortable" data-sort="user_agent">User Agent</th>
                        <th scope="col" style="width: 80px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="logs-tbody">
                    <!-- Data will be loaded via AJAX -->
                </tbody>
            </table>

            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num" id="total-records">0 items</span>
                    <span class="pagination-links">
                        <button type="button" id="first-page" class="button">&laquo;</button>
                        <button type="button" id="prev-page" class="button">&lsaquo;</button>
                        <span class="paging-input">
                            Page <input type="text" id="current-page" value="1" size="2"> of <span id="total-pages">1</span>
                        </span>
                        <button type="button" id="next-page" class="button">&rsaquo;</button>
                        <button type="button" id="last-page" class="button">&raquo;</button>
                    </span>
                </div>
            </div>
        </div>

        <!-- Modal Dialog -->
        <dialog id="log-details-modal" style="width: 90%; max-width: 800px; border: 1px solid #ccc; border-radius: 5px; padding: 20px;">
            <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0;">Request Details</h2>
                <button type="button" id="close-modal" style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
            </div>
            <div id="modal-content">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer" style="margin-top: 20px; text-align: right;">
                <button type="button" class="button button-primary" onclick="document.getElementById('log-details-modal').close()">Close</button>
            </div>
        </dialog>

        <style>
            .wp-list-table th.sortable {
                cursor: pointer;
            }

            .wp-list-table th.sortable:hover {
                background-color: #f0f0f1;
            }

            .wp-list-table th.sorted-asc::after {
                content: ' ↑';
            }

            .wp-list-table th.sorted-desc::after {
                content: ' ↓';
            }

            #logs-table td {
                word-break: break-word;
                vertical-align: top;
            }

            .tablenav {
                margin: 10px 0;
            }

            .tablenav .alignleft select,
            .tablenav .alignleft input {
                margin-right: 5px;
            }

            .view-details-btn {
                padding: 2px 8px;
                font-size: 11px;
            }

            .modal-details-table {
                width: 100%;
                border-collapse: collapse;
            }

            .modal-details-table th,
            .modal-details-table td {
                padding: 8px 12px;
                text-align: left;
                border-bottom: 1px solid #ddd;
            }

            #logs-table th {
                padding: 8px 10px;
            }

            .modal-details-table th {
                background-color: #f9f9f9;
                font-weight: bold;
                width: 150px;
            }

            .modal-details-table td {
                word-break: break-word;
            }
        </style>

        <script>
            jQuery(document).ready(function($) {
                let currentPage = 1;
                let sortBy = 'timestamp';
                let sortOrder = 'DESC';

                function loadLogs() {
                    $('#logs-loading').show();

                    const filters = {
                        search: $('#search-logs').val(),
                        device: $('#filter-device').val(),
                        page: currentPage,
                        sort_by: sortBy,
                        sort_order: sortOrder
                    };

                    $.post(ajaxurl, {
                        action: 'get_request_logs',
                        filters: filters,
                        _wpnonce: '<?php echo wp_create_nonce('user_logs_nonce'); ?>'
                    }, function(response) {
                        $('#logs-loading').hide();

                        if (response.success) {
                            const data = response.data;
                            let html = '';

                            data.logs.forEach(function(log) {
                                html += '<tr>';
                                html += '<td>' + log.timestamp + '</td>';
                                html += '<td>' + log.ip_address + '</td>';
                                html += '<td style="word-break: break-all;">' + log.url + '</td>';
                                html += '<td style="word-break: break-all;">' + log.status_code + '</td>';
                                html += '<td style="word-break: break-all;">' + log.user_agent + '</td>';
                                html += '<td><button type="button" class="button button-small view-details-btn" data-id="' + log.id + '">View</button></td>';
                                html += '</tr>';
                            });

                            $('#logs-tbody').html(html);
                            $('#total-records').text(data.total + ' items');
                            $('#total-pages').text(data.total_pages);
                            $('#current-page').val(currentPage);

                            // Update pagination buttons
                            $('#first-page, #prev-page').prop('disabled', currentPage === 1);
                            $('#next-page, #last-page').prop('disabled', currentPage === data.total_pages);
                        }
                    });
                }

                // View details modal
                $(document).on('click', '.view-details-btn', function() {
                    const logId = $(this).data('id');

                    $.post(ajaxurl, {
                        action: 'get_log_details',
                        log_id: logId,
                        _wpnonce: '<?php echo wp_create_nonce('user_logs_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            const log = response.data;
                            let html = '<table class="modal-details-table">';
                            html += '<tr><th>ID</th><td>' + log.id + '</td></tr>';
                            html += '<tr><th>Timestamp</th><td>' + log.timestamp + '</td></tr>';
                            html += '<tr><th>Method</th><td>' + log.method + '</td></tr>';
                            html += '<tr><th>URL</th><td style="word-break: break-all;">' + log.url + '</td></tr>';
                            html += '<tr><th>IP Address</th><td>' + log.ip_address + '</td></tr>';
                            html += '<tr><th>Browser</th><td>' + log.browser + '</td></tr>';
                            html += '<tr><th>Device Type</th><td>' + log.device_type + '</td></tr>';
                            html += '<tr><th>Referer</th><td style="word-break: break-all;">' + (log.referer || 'Direct') + '</td></tr>';
                            html += '<tr><th>Status Code</th><td>' + log.status_code + '</td></tr>';
                            html += '<tr><th>User Agent</th><td style="word-break: break-all;">' + log.user_agent + '</td></tr>';
                            html += '</table>';

                            $('#modal-content').html(html);
                            document.getElementById('log-details-modal').showModal();
                        }
                    });
                });

                // Close modal
                $('#close-modal').click(function() {
                    document.getElementById('log-details-modal').close();
                });

                // Close modal when clicking outside
                $('#log-details-modal').click(function(e) {
                    if (e.target === this) {
                        this.close();
                    }
                });

                // Initial load
                loadLogs();

                // Filters
                $('#apply-filters').click(loadLogs);
                $('#clear-filters').click(function() {
                    $('#search-logs, #filter-device').val('');
                    currentPage = 1;
                    loadLogs();
                });

                // Pagination
                $('#first-page').click(function() {
                    currentPage = 1;
                    loadLogs();
                });
                $('#prev-page').click(function() {
                    if (currentPage > 1) {
                        currentPage--;
                        loadLogs();
                    }
                });
                $('#next-page').click(function() {
                    currentPage++;
                    loadLogs();
                });
                $('#last-page').click(function() {
                    currentPage = parseInt($('#total-pages').text());
                    loadLogs();
                });

                $('#current-page').keypress(function(e) {
                    if (e.which === 13) {
                        currentPage = parseInt($(this).val()) || 1;
                        loadLogs();
                    }
                });

                // Sorting
                $('.sortable').click(function() {
                    const newSortBy = $(this).data('sort');
                    if (sortBy === newSortBy) {
                        sortOrder = sortOrder === 'ASC' ? 'DESC' : 'ASC';
                    } else {
                        sortBy = newSortBy;
                        sortOrder = 'DESC';
                    }

                    $('.sortable').removeClass('sorted-asc sorted-desc');
                    $(this).addClass(sortOrder === 'ASC' ? 'sorted-asc' : 'sorted-desc');

                    currentPage = 1;
                    loadLogs();
                });

                // Clear all logs
                $('#clear-all-logs').click(function() {
                    if (confirm('Are you sure you want to clear all logs? This cannot be undone.')) {
                        $.post(ajaxurl, {
                            action: 'clear_request_logs',
                            _wpnonce: '<?php echo wp_create_nonce('user_logs_nonce'); ?>'
                        }, function(response) {
                            if (response.success) {
                                alert('Logs cleared successfully!');
                                loadLogs();
                            }
                        });
                    }
                });

                // Refresh
                $('#refresh-logs').click(loadLogs);

                // Auto-refresh every 30 seconds
                setInterval(function() {
                    if (!$('#search-logs').is(':focus')) {
                        loadLogs();
                    }
                }, 30000);
            });
        </script>
<?php
    }

    public function ajax_get_logs()
    {
        check_ajax_referer('user_logs_nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        $filters = $_POST['filters'] ?? array();
        $page = max(1, intval($filters['page'] ?? 1));
        $per_page = 20;
        $offset = ($page - 1) * $per_page;

        $where_conditions = array('1=1');
        $where_values = array();

        // Search filter (search in all columns)
        if (!empty($filters['search'])) {
            $search = '%' . $wpdb->esc_like($filters['search']) . '%';
            $where_conditions[] = '(url LIKE %s OR ip_address LIKE %s OR user_agent LIKE %s OR referer LIKE %s OR browser LIKE %s OR method LIKE %s)';
            $where_values = array_merge($where_values, array($search, $search, $search, $search, $search, $search));
        }

        // Device filter
        if (!empty($filters['device'])) {
            $where_conditions[] = 'device_type = %s';
            $where_values[] = $filters['device'];
        }

        $where_clause = implode(' AND ', $where_conditions);

        // Sorting
        $sort_by = sanitize_sql_orderby($filters['sort_by'] ?? 'timestamp');
        $sort_order = ($filters['sort_order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_clause}";
        if (!empty($where_values)) {
            $count_query = $wpdb->prepare($count_query, $where_values);
        }
        $total = $wpdb->get_var($count_query);

        // Get logs
        $logs_query = "SELECT id, timestamp, ip_address, url, status_code, user_agent FROM {$this->table_name} WHERE {$where_clause} ORDER BY {$sort_by} {$sort_order} LIMIT %d OFFSET %d";
        $query_values = array_merge($where_values, array($per_page, $offset));
        $logs_query = $wpdb->prepare($logs_query, $query_values);
        $logs = $wpdb->get_results($logs_query, ARRAY_A);

        wp_send_json_success(array(
            'logs' => $logs,
            'total' => intval($total),
            'total_pages' => ceil($total / $per_page),
            'current_page' => $page
        ));
    }

    public function ajax_get_log_details()
    {
        check_ajax_referer('user_logs_nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        $log_id = intval($_POST['log_id']);
        $log = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $log_id), ARRAY_A);

        if ($log) {
            wp_send_json_success($log);
        } else {
            wp_send_json_error('Log not found');
        }
    }

    public function ajax_clear_logs()
    {
        check_ajax_referer('user_logs_nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->table_name}");

        wp_send_json_success();
    }
}

// Initialize the plugin
new UserRequestLogger();
