<?php

/**
 * manages interactions with the log table form element database table
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPL3
 * @version    1.1
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\fields;

class log_table_db {

  /**
   * @var string name of the log table
   */
  const table_name = 'pdb_log_table_field';

  /**
   * @var string name of the db version option
   */
  const db_version_option = 'pdb_log_table_field_version';

  /**
   * @var string the current db version
   */
  const db_version = '1.1';
  
  /**
   * @var log_table_db
   */
  static $instance;

  /**
   * initializes the database connection
   * 
   * @param object $field an optional field objec
   */
  public function __construct()
  {
    $this->check_table();
    add_action( 'pdbmps-uninstall', array($this, 'uninstall') );
  }
  
  /**
   * provides the class instance
   * 
   * @return log_table_db
   */
  public static function get_instance()
  {
    if ( is_null( self::$instance ) ) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  /**
   * shortcode to get a log entry
   * 
   * @param string  $entry_id
   * @return  array associative array of log entry data
   */
  public static function get_log( $entry_id )
  {
    $log = self::get_instance();
    return $log->get_log_entry( $entry_id );
  }

  /**
   * provides a shortcut method to get log data for a list of entry ids
   * 
   * @param array|string $entry_list array of entry ids or single id
   * @return array of entries, indexed by the entry id
   */
  public static function get_logs( $entry_list )
  {
    $log = self::get_instance();
    $logs = array();
    foreach ( (array) $entry_list as $entry_id ) {
      $logs[$entry_id] = $log->get_log_entry( $entry_id );
    }
    return $logs;
  }
  
  
  /**
   * provides the first log entry given the record id
   * 
   * @param int $record_id
   * 
   * @return array all logs for the record
   */
  public static function get_all_entries( $record_id )
  {
    return self::record_logs($record_id);
  }
  
  
  /**
   * provides the last log entry given the record id
   * 
   * @param int $record_id
   * 
   * @return array  the last log entry data
   */
  public static function get_last_entry( $record_id )
  {
    $logs = self::record_logs($record_id);
    return reset( $logs );
  }
  
  
  /**
   * provides the first log entry given the record id
   * 
   * @param int $record_id
   * 
   * @return array  the first log entry data
   */
  public static function get_first_entry( $record_id )
  {
    $logs = self::record_logs($record_id);
    return end( $logs );
  }
  
  /**
   * provides all log entries for a given record ID
   * 
   * @param int $record_id
   * @return array of all log entries as entry_id => array( column => value )
   */
  private static function record_logs( $record_id )
  {
    $all_logs = wp_cache_get($record_id, self::table_name);
    if ( !$all_logs ) {
      $log = self::get_instance();
      global $wpdb;
      $sql = 'SELECT * FROM `' . $log->table_name() . '` WHERE `record_id` = %s ORDER BY `timestamp` DESC';
      $results = $wpdb->get_results( $wpdb->prepare( $sql, $record_id) );
      $all_logs = array();
      foreach( $results as $raw_entry ) {
        $all_logs[$raw_entry->entry_id][$raw_entry->entry_column] = $raw_entry->entry_value;
      }
      
      // sort by payment date
      uasort($all_logs,function( $a, $b ) {
          return ( strtotime( $a['payment_date'] ) < strtotime( $b['payment_date'] ) ) ? 1 : -1;
      });
      
      wp_cache_set($record_id, $all_logs, self::table_name);
    }
    return $all_logs;
  }
  

  /**
   * provides a shortcut for writing a log entry
   * 
   * @param array   $data the log entry data
   * @param int     $record_id  id of the associated record
   * @param string  $field  name of the field
   * @param string  $entry_id identifier for the entry
   * 
   * @return  string  the entry id
   */
  public static function save_entry( $data, $record_id, $field, $entry_id = '' )
  {
    $log = self::get_instance();
    if ( empty( $entry_id ) ) {
      $entry_id = self::unique_id();
    }
    $log->write_log_entry( $data, $entry_id, $field, $record_id );

    return $entry_id;
  }
  
  /**
   * updates or writes a log entry
   * 
   * @param array $data associative array of data values
   * @param string  $entry_id id of the entry to write or update
   * @param string  $field  name of the log field
   * @param string  $timestamp  the entry timestamp (optional)
   * @param int $record_id record id
   */
  public static function update_log_entry( $data, $entry_id, $field, $record_id, $timestamp = false )
  {
    $log = self::get_instance();
    
    $entry_meta = $log->get_entry_metadata( $entry_id );
    if ( $entry_meta ) {
      $log->_update_log_entry( $data, $entry_id, $field, $record_id, $timestamp );
    } else {
      $log->write_log_entry($data, $entry_id, $field, $record_id, $timestamp );
    }
  }

  /**
   * provides an easy way to delete a log entry
   * 
   * @param string  $entry_id
   * @return bool true if rows were deleted
   */
  public static function delete_entry( $entry_id )
  {
    $log = self::get_instance();
    $result = $log->delete_log_entry( $entry_id );
    return $result > 0;
  }
  
  /**
   * writes a single value to an entry
   * 
   * @param string  $entry_id
   * @param string  $name name of the column
   * @param string  $value to write
   */
  public static function write_entry_value( $entry_id, $name, $value )
  {
    $log = self::get_instance();
    $log->_write_entry_value($entry_id, $name, $value);
  }

  /**
   * supplies all the logs for a field
   * 
   * @global \wpdb $wpdb
   * @param string $field name of the field to get all the logs for
   * @return array of arrays, indexed by the entry ID
   */
  public function all_logs( $field )
  {
    global $wpdb;
    $result = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table_name() . ' WHERE `field` = "%s" ', $field ) );
    $return = array();
    foreach ( $result as $entry ) {
      $return[$entry->entry_id][$entry->entry_column] = $entry->entry_value;
    }
    return $return;
  }

  /**
   * gets a log entry
   * 
   * @global object $wpdb
   * @param string $entry_id
   * @return  array associative array of log entry data
   */
  public function get_log_entry( $entry_id )
  {
    global $wpdb;
    $result = $wpdb->get_results( 'SELECT * FROM ' . $this->table_name() . ' WHERE `entry_id` = "' . $entry_id . '" ' );

    $return = array();
    if ( $wpdb->num_rows > 0 ) {
      foreach ( $result as $entry ) {
        $return[$entry->entry_column] = $entry->entry_value;
      }
    }
    return $return;
  }

  /**
   * supplies the log entry values formatted for display
   * 
   * @param string  $entry_id
   * @return array associative array of display-ready values
   */
  public function get_entry_display( $entry_id )
  {
    $entry = $this->get_log_entry( $entry_id );
    
    $field_defs = \Participants_Db::$fields;
    foreach ( $entry as $name => $value ) {
      if ( array_key_exists( $name, $field_defs ) ) {
        $def = new \PDb_Field_Item( $field_defs[$name] );
        $def->set_value($value);
        $entry[$name] = \PDb_FormElement::get_field_value_display( $def );
      }
    }
    return $entry;
  }

  /**
   * writes a new log
   * 
   * @global \wpdb $wpdb
   * @param array $data associative array of data to log
   * @param string  $entry_id  log entry identifier
   * @param string  $field  name of the field
   * @param int $record_id id of the associated PDB record
   * @param string  $timestamp  if provided, uses this timestamp value
   * @return bool true if successful
   */
  public function write_log_entry( $data, $entry_id, $field, $record_id, $timestamp = false )
  {
    global $wpdb;
    $values = array();
    $columns = array( 'entry_id', 'record_id', 'field', 'entry_column', 'entry_value', 'timestamp' );
    $placeholder_pattern = $timestamp ? '( %s, %s, %s, %s, %s, %s )' : '( %s, %s, %s, %s, %s, NULL )';
    $placeholders = array();
    
    $query = 'INSERT INTO ' . $this->table_name() . ' (' . implode( ',', $columns ) . ') VALUES ';
    
    foreach ( $data as $column => $value ) {
      if ( $timestamp ) {
        array_push( $values, $entry_id, $record_id, $field, $column, $value, $timestamp );
      } else {
        array_push( $values, $entry_id, $record_id, $field, $column, $value );
      }
      $placeholders[] = $placeholder_pattern;
    }
    
    $wpdb->query( $wpdb->prepare( $query . implode( ',', $placeholders ), $values ) );
//    error_log(__METHOD__.' query: '.$wpdb->last_query);
    
    $this->clear_cache($record_id);
  }

  /**
   * updates a log entry
   * 
   * defaults to a "clean" update that deletes the matching entry before storing 
   * the updated entry; this is much more efficient if storing all the data of an 
   * entry. This should be set to false if only part of the entry is to be updated.
   * 
   * @global \wpdb $wpdb
   * @param array   $data     associative array of data to log
   * @param string  $entry_id to the log entry to update
   * @param string  $field    name of the log field
   * @param int     $record_id record id
   * @param string  $timestamp if provided, sets the timestamp of the entry
   * @param bool    $clean    if true, delete a matching entry
   * @return bool true if successful
   */
  private function _update_log_entry( $data, $entry_id, $field, $record_id, $timestamp = false, $clean = true )
  {
    global $wpdb;
    if ( $clean ) {
      $wpdb->delete( $this->table_name(), array( 'entry_id' => $entry_id ) );
      $this->write_log_entry($data, $entry_id, $field, $record_id, $timestamp );
    } else {
      foreach ( $data as $column => $value ) {
        $wpdb->update( $this->table_name(), array( 'entry_value' => $value ), array( 'entry_id' => $entry_id, 'entry_column' => $column ) );
      }
    }
  }
  
  /**
   * writes a single value to an entry
   * 
   * @global wpdb $wpdb
   * @param string  $entry_id
   * @param string  $column name of the column
   * @param string  $value to write
   */
  private function _write_entry_value( $entry_id, $column, $value )
  {
    global $wpdb;
    $wpdb->update( $this->table_name(), array( 'entry_value' => $value ), array( 'entry_id' => $entry_id, 'entry_column' => $column ) );
  }
  
  /**
   * provides the metadata for an entry
   * 
   * @param string  $entry_id the entry ID to get the metadata for
   * @global wpdb $wpdb
   * @return stdClass|bool false if no matching entry id found
   */
  public function get_entry_metadata( $entry_id )
  {
    global $wpdb;
    $result = $wpdb->get_row( $wpdb->prepare( 'SELECT `record_id`, `field`, `timestamp` FROM ' . $this->table_name() . ' WHERE `entry_id` = "%s"', $entry_id ) );
    return is_object( $result ) ? $result : false;
  }

  /**
   * deletes a log entry
   * 
   * @global \wpdb $wpdb
   * @param string  $entry_id
   * @return int|bool number of rows deleted or bool false if error
   */
  public function delete_log_entry( $entry_id )
  {
    global $wpdb;
    return $wpdb->delete( $this->table_name(), array('entry_id' => $entry_id) );
  }
  
  /**
   * deletes all entries attached to a particular record
   * 
   * @global \wpdb $wpdb
   * @param int $record_id
   * @return int|bool number of rows deleted or bool false if error
   */
  public function delete_all_record_entries( $record_id )
  {
    global $wpdb;
    $result = $wpdb->delete( $this->table_name(), array('record_id' => $record_id) );
    $this->clear_cache($record_id);
    return $result;
  }
  
  /**
   * clears the log cache
   * 
   * @param int $record_id
   */
  private function clear_cache( $record_id )
  {
    wp_cache_replace( $record_id, false, self::table_name );
  }

  /**
   * supplies a unique ID
   * 
   * @return string unique ID
   */
  public static function unique_id()
  {
    /**
     * @filter pdb-member_payments_new_log_id
     * @param string  the chosen unique ID
     * 
     * allows for an alternate unique ID method
     */
    return apply_filters( 'pdbmps-new_log_id', uniqid() );
  }

  /**
   * checks for a unique entry ID
   * 
   * @global \wpdb $wpdb
   * @param string $id candidate ID
   * @return bool true if the ID is unique
   */
  public function id_is_unique( $id )
  {
    global $wpdb;
    $sql = 'SELECT COUNT(*) FROM ' . $this->table_name() . ' WHERE `entry_id` = "%s"';
    $result = $wpdb->get_var( $wpdb->prepare( $sql, $id ) );
    return $result == '0';
  }


  /**
   * provides the first or last log entry given the record id
   * 
   * @param int $record_id
   * @param bool  $first  true to get first entry, false to get last
   * 
   * @return array  the last log entry data
   */
  private static function get_first_or_last_entry_by_query( $record_id, $first = true )
  {
    $order = $first ? 'ASC' : 'DESC';
    $log = self::get_instance();
    global $wpdb;
    $sql = 'SELECT * FROM `' . $log->table_name() . '` WHERE `record_id` = "%s" AND `entry_id` = (SELECT `entry_id` FROM `' . $log->table_name() . '` WHERE `record_id` = "%s" GROUP BY `entry_id` ORDER BY `id` ' . $order . ' LIMIT 1 )';
    $last_log = $wpdb->get_results( $wpdb->prepare( $sql, $record_id, $record_id) );
    $entry_data = array();
    foreach( $last_log as $raw_entry ) {
      $entry_data[$raw_entry->entry_column] = $raw_entry->entry_value;
    }
    return $entry_data;
  }

  /**
   * checks for the database table, creates or modifies it if needed
   * 
   * @global \wpdb $wpdb
   * @return null
   */
  private function check_table()
  {
    global $wpdb;

    $table_name = $this->table_name();

    if (
            $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) != $table_name ||
            version_compare( get_option( self::db_version_option, '0.0' ), self::db_version, '<' )
    ) {
      require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
      $charset_collate = $wpdb->get_charset_collate();
      $sql = "CREATE TABLE $table_name (
      id mediumint(9) NOT NULL AUTO_INCREMENT,
      `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `record_id` int(6),
      `field` tinytext,
      `entry_id` varchar(128),
      `entry_column` tinytext,
      `entry_value` text,
      INDEX entry_id (entry_id),
      PRIMARY KEY id (id)
    ) $charset_collate;";

      dbDelta( $sql );

      update_option( self::db_version_option, self::db_version );
    }
  }

  /**
   * uninstall
   * 
   * @global \wpdb $wpdb
   */
  public function uninstall()
  {
    global $wpdb;
    $wpdb->query( "DROP TABLE IF EXISTS " . $this->table_name() );
    delete_option( self::db_version_option );
  }

  /**
   * provides a prefixed table name
   * 
   * @global \wpdb $wpdb
   * @return string
   */
  private function table_name()
  {
    global $wpdb;
    return $wpdb->prefix . self::table_name;
  }

}
