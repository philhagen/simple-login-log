<?php
/*
  Plugin Name: Simple Login Log
  Plugin URI: http://simplerealtytheme.com
  Description: This plugin keeps a log of WordPress user logins. Offers user filtering and export features.
  Author: Max Chirkov
  Version: 0.1
  Author URI: http://SimpleRealtyTheme.com
 */


//TODO: add cleanup method on uninstall

if( !class_exists( 'SimpleLoginLog' ) )
{
 class SimpleLoginLog {
    private $table = 'simple_login_log';
    private $log_duration = null; //days
    private $opt_name = 'simple_login_log';
    private $opt = false;
    
    function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . $this->table;
        $this->opt = get_option($this->opt_name);

        if(isset($_GET['download-login-log']))
        {
            $this->export_to_CSV();
        } 


        add_action( 'admin_menu', array(&$this, 'sll_admin_menu') );
        add_action('admin_init', array(&$this, 'settings_api_init') );
        
        //Action on successfull loging
        add_action( 'wp_login', array(&$this, 'login_success') );   
        
        //Style the log table
        add_action( 'admin_head', array(&$this, 'admin_header') );

        //Initialize scheduled events
        add_action( 'wp', array(&$this, 'init_scheduled_events') );        
    }

    function init_scheduled_events()
    {
        if ( $this->opt['log_duration'] && !wp_next_scheduled( 'truncate_log' ) ) 
        {
            wp_schedule_event(time(), 'daily', 'truncate_log');
        }elseif( !$this->opt['log_duration'] || 0 == $this->opt['log_duration'])
        {
            $timestamp = wp_next_scheduled( 'truncate_log' );
            (!$timestamp) ? false : wp_unschedule_event($timestamp, 'truncate_log');
            
        }
    }

    function truncate_log()
    {
        global $wpdb;

        if( 0 < (int) $this->opt['log_duration'] ){
            $sql = $wpdb->prepare( "DELETE FROM {$this->table} WHERE time < DATE_SUB(CURDATE(),INTERVAL %d DAY)", array($this->opt['log_duration']));
            $wpdb->query($sql);
        }
    }

    function install()
    {       
        global $wpdb;
        
        $sql = "CREATE TABLE  " . $this->table . " 
                (
                    id INT( 11 ) NOT NULL AUTO_INCREMENT ,
                    uid INT( 11 ) NOT NULL ,
                    user_login VARCHAR( 60 ) NOT NULL ,
                    time DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL ,                  
                    ip VARCHAR( 100 ) NOT NULL ,
                    data LONGTEXT NOT NULL ,
                    PRIMARY KEY ( id ) ,
                    INDEX ( uid, ip )
                );";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);          
    }

    //Initializing Settings
    function settings_api_init()
    {
        add_settings_section('simple_login_log', 'Simple Login Log', array(&$this, 'sll_settings'), 'general');             
        add_settings_field('field_log_duration', 'Truncate Log Entries', array(&$this, 'field_log_duration'), 'general', 'simple_login_log');
        register_setting( 'general', 'simple_login_log' );      
    }

    function sll_admin_menu()
    {
        add_submenu_page( 'users.php', __('Simple Login Log', 'sll'), __('Login Log', 'sll'), 'edit_users', 'login_log', array(&$this, 'log_manager') );
    }

    function sll_settings()
    {                                                   
        //content that goes before the fields output
    }

    function field_log_duration()
    {
        $duration = (null !== $this->opt['log_duration']) ? $this->opt['log_duration'] : $this->log_duration;
        $output = '<input type="text" value="' . $duration . '" name="simple_login_log[log_duration]" size="10" class="code" /> days and older.';
        echo $output;
        echo "<p>Leave empty or enter 0 if you don't want the log to be truncated.</p>";              
    }

    function admin_header()
    {
        if($_GET['page'] != 'login_log')
            return; 

        echo '<style type="text/css">';
        echo '.wp-list-table .column-id { width: 5%; }';
        echo '.wp-list-table .column-uid { width: 10%; }';
        echo '.wp-list-table .column-user_login { width: 10%; }';
        echo '.wp-list-table .column-name { width: 15%; }';
        echo '.wp-list-table .column-time { width: 15%; }';
        echo '.wp-list-table .column-ip { width: 10%; }';
        echo '</style>';        
    }

    //Catch messages on successful login
    function login_success($user_login){
        
        $userdata = get_user_by('login', $user_login);

        $uid = ($userdata->ID) ? $userdata->ID : 0;

        //Stop if login form wasn't submitted
        if( !$_REQUEST['wp-submit'] )
            return;
                
        if ( isset( $_REQUEST['redirect_to'] ) ) { $data['Login Redirect'] = $_REQUEST['redirect_to']; }        
        $data['User Agent'] = $_SERVER['HTTP_USER_AGENT'];      
        
        $serialized_data = serialize($data);
        
        $values = array(
            'uid'           => $uid,
            'user_login'    => $user_login,
            'time'          => current_time('mysql'),       
            'ip'            => $_SERVER['REMOTE_ADDR'],
            'data'          => $serialized_data,
            );
        
        $format = array('%d', '%s', '%s', '%s', '%s');

        $this->save_data($values, $format);
    }

    function save_data($values, $format){
        global $wpdb;

        $wpdb->insert( $this->table, $values, $format );
    }

    function log_manager()
    {
        global $wpdb, $ssl_list_table;
        
        $log_table = new SLL_List_Table;
        
        $limit = 20;
        $offset = ( isset($_REQUEST['page']) ) ? $limit * $_REQUEST['page'] : 0; 
        
        if($_GET['filter'])
        {
            $where = "WHERE user_login = '{$_GET['filter']}'";
        }
        if($_GET['datefilter'])
        {
            $year = substr($_GET['datefilter'], 0, 4);
            $month = substr($_GET['datefilter'], -2);
            $where = "WHERE YEAR(time) = {$year} AND MONTH(time) = {$month}";
        }

        $sql = "SELECT * FROM $this->table $where ORDER BY time DESC LIMIT $limit OFFSET $offset";        
        $data = $wpdb->get_results($sql, 'ARRAY_A');
                
        $log_table->items = $data;
        $log_table->prepare_items();

        echo '<div class="wrap srp">';
        echo '<h2>Login Log</h2>';
        echo '<div class="tablenav top">';
            echo '<div class="alignleft actions">';
                echo $this->date_filter();
            echo '</div>';
            echo '<form method="get">';
            echo '<p class="search-box">';
            echo '<input type="hidden" name="page" value="login_log" />';
            echo '<label>Username: </label><input type="text" name="filter" class="filter-username" /> <input class="button" type="submit" value="Filter User" />';
            echo '</p>';
            echo '</form>';
        echo '</div>';
        $log_table->display();
        echo '</div>';
        echo '<form method="get" id="export-login-log">';
        echo '<input type="hidden" name="page" value="login_log" />';
        echo '<input type="hidden" name="download-login-log" value="true" />';
        submit_button( __('Export Log to CSV'), 'secondary' ); 
        echo '</form>';  
    }

    private function date_filter()
    {
        global $wpdb;
        $sql = "SELECT DISTINCT YEAR(time) as year, MONTH(time)as month FROM {$this->table}";
        $results = $wpdb->get_results($sql);

        if(!$results)
            return;

        

        foreach($results as $row)
        {
            //represent month in double digits            
            $timestamp = mktime(0, 0, 0, $row->month, 1, $row->year);
            $month = (strlen($row->month) == 1) ? '0' . $row->month : $row->month;

            $option .= '<option value="' . $row->year . $month . '" ' . selected($row->year . $month, $_GET['datefilter'], false) . '>' . date('F', $timestamp) . ' ' . $row->year . '</option>';
        }

        $output = '<form method="get">';
        $output .= '<input type="hidden" name="page" value="login_log" />';
        $output .= '<select name="datefilter"><option value="">View All</option>' . $option . '</select>';
        $output .= '<input class="button" type="submit" value="Filter" />';
        $output .= '</form>';
        return $output;
    }

    function export_to_CSV(){
        global $wpdb;

        $sql = "SELECT * FROM $this->table";        
        $data = $wpdb->get_results($sql, 'ARRAY_A');

        if(!$data)
            return;

        // send response headers to the browser
        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment;filename=login_log.csv');
        $fp = fopen('php://output', 'w');
                
        $i = 0;
        foreach($data as $row){
            $tmp = unserialize($row['data']);
            //output header row
            if(0 == $i)
            {
                fputcsv( $fp, array_keys($row) );
            }
            $row_data = (!empty($tmp)) ? array_map(create_function('$key, $value', 'return $key.": ".$value." | ";'), array_keys($tmp), array_values($tmp)) : array();
            $row['data'] = implode($row_data);
            fputcsv($fp, $row);
            $i++;
        }
        
        fclose($fp);
        die();
    }

 }
}

if( class_exists( 'SimpleLoginLog' ) )
{
    $sll = new SimpleLoginLog;       
    //Register for activation
    register_activation_hook( __FILE__, array(&$sll, 'install') );  
    
}

if(!class_exists('WP_List_Table')){
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}
class SLL_List_Table extends WP_List_Table
{       
    function __construct()
    {
        global $status, $page;
                
        //Set parent defaults
        parent::__construct( array(
            'singular'  => 'user',     //singular name of the listed records
            'plural'    => 'users',    //plural name of the listed records
            'ajax'      => false        //does this table support ajax?
        ) );
        
    }

    function column_default($item, $column_name)
    {
        switch($column_name){
            case 'id':
            case 'uid':            
            case 'time':
            case 'ip':
                return $item[$column_name];  
            case 'user_login':
                return "<a href='" . get_admin_url() . "users.php?page=login_log&filter={$item[$column_name]}' title='Filter log by this name'>{$item[$column_name]}</a>";                              
            case 'name';
                $user_info = get_userdata($item['uid']);
                return $user_info->first_name .  " " . $user_info->last_name;                       
            case 'data':
                $data = unserialize($item[$column_name]);
                if(is_array($data))
                {
                    foreach($data as $k => $v)
                    {
                        $output .= $k .': '. $v .'<br />';
                    }
                    return $output;
                }               
                break;  
            default:
                print_r($item);                                         
        }
    }

    function get_columns()
    {
        $columns = array(
            'id'            => '#',
            'uid'           => 'User ID',
            'user_login'    => 'Username',
            'name'          => 'Name',
            'time'          => 'Time',
            'ip'            => 'IP Address',
            'data'          => 'Data',
        );
        return $columns;
    }

    function get_sortable_columns()
    {
        $sortable_columns = array(
            //'id'    => array('id',true),     //doesn't sort correctly
            'uid'   => array('uid',false),
            'time'  => array('time',true),
            'ip'    => array('ip', false),
        );
        return $sortable_columns;
    }

    function prepare_items()
    {
        
        /**
         * First, lets decide how many records per page to show
         */
        $per_page = 20;
        
        
        /**
         * REQUIRED. Now we need to define our column headers. This includes a complete
         * array of columns to be displayed (slugs & titles), a list of columns
         * to keep hidden, and a list of columns that are sortable. Each of these
         * can be defined in another method (as we've done here) before being
         * used to build the value for our _column_headers property.
         */
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        
        
        /**
         * REQUIRED. Finally, we build an array to be used by the class for column 
         * headers. The $this->_column_headers property takes an array which contains
         * 3 other arrays. One for all columns, one for hidden columns, and one
         * for sortable columns.
         */
        $this->_column_headers = array($columns, $hidden, $sortable);
        
        
        /**
         * Optional. You can handle your bulk actions however you see fit. In this
         * case, we'll handle them within our package just to keep things clean.
         */
        //$this->process_bulk_action();
        
        
        /**
         * Instead of querying a database, we're going to fetch the example data
         * property we created for use in this plugin. This makes this example 
         * package slightly different than one you might build on your own. In 
         * this example, we'll be using array manipulation to sort and paginate 
         * our data. In a real-world implementation, you will probably want to 
         * use sort and pagination data to build a custom query instead, as you'll
         * be able to use your precisely-queried data immediately.
         */
        $data = $this->items;
                
        
        /**
         * This checks for sorting input and sorts the data in our array accordingly.
         * 
         * In a real-world situation involving a database, you would probably want 
         * to handle sorting by passing the 'orderby' and 'order' values directly 
         * to a custom query. The returned data will be pre-sorted, and this array
         * sorting technique would be unnecessary.
         */
        function usort_reorder($a,$b){
            $orderby = (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : 'time'; //If no sort, default to title
            $order = (!empty($_REQUEST['order'])) ? $_REQUEST['order'] : 'desc'; //If no order, default to asc
            $result = strcmp($a[$orderby], $b[$orderby]); //Determine sort order
            return ($order==='asc') ? $result : -$result; //Send final sort direction to usort
        }
        usort($data, 'usort_reorder');
        
        
        /***********************************************************************
         * ---------------------------------------------------------------------
         * vvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvv
         * 
         * In a real-world situation, this is where you would place your query.
         * 
         * ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
         * ---------------------------------------------------------------------
         **********************************************************************/
        
                
        /**
         * REQUIRED for pagination. Let's figure out what page the user is currently 
         * looking at. We'll need this later, so you should always include it in 
         * your own package classes.
         */
        $current_page = $this->get_pagenum();
        
        /**
         * REQUIRED for pagination. Let's check how many items are in our data array. 
         * In real-world use, this would be the total number of items in your database, 
         * without filtering. We'll need this later, so you should always include it 
         * in your own package classes.
         */
        $total_items = count($data);
        
        
        /**
         * The WP_List_Table class does not handle pagination for us, so we need
         * to ensure that the data is trimmed to only the current page. We can use
         * array_slice() to 
         */
        $data = array_slice($data,(($current_page-1)*$per_page),$per_page);
        
        
        
        /**
         * REQUIRED. Now we can add our *sorted* data to the items property, where 
         * it can be used by the rest of the class.
         */
        $this->items = $data;
        
        
        /**
         * REQUIRED. We also have to register our pagination options & calculations.
         */
        $this->set_pagination_args( array(
            'total_items' => $total_items,                  //WE have to calculate the total number of items
            'per_page'    => $per_page,                     //WE have to determine how many items to show on a page
            'total_pages' => ceil($total_items/$per_page)   //WE have to calculate the total number of pages
        ) );
    }
    
}