<?php

/**
 * holds all the properties and methods for an indvidual log table field for a specific record
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPL3
 * @version    0.7
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\fields;

class log_table_field {

  /**
   * @var string  name of the current field
   */
  private $name;

  /**
   * @var array of field attributes
   */
  private $attributes = array();

  /**
   * @var array of field options
   */
  private $options = array();

  /**
   * @var int id of the current record
   */
  private $record_id;

  /**
   * @var array of data from the current record
   */
  private $record;

  /**
   * @var array ordered list of log entry ids
   */
  private $log_id_list = array();

  /**
   * @var array of log table column names
   */
  private $columns;
  
  /**
   * @var string|bool the ID of a newly-added log entry or bool false if no new 
   *                  entry has been added
   */
  private $new_log_id = false;

  /**
   * instantiates the object
   * 
   * @param stdClass|string  $field object or field name
   * @param int $record_id the id of the record
   */
  public function __construct( $field, $record_id )
  { 
    $this->record_id = $record_id;
    $this->record = $this->participant_values( $record_id ); // this will be an array of log entry ids
    //assert( is_array( $this->record ), 'get record values' );
    if ( is_string( $field ) && \Participants_Db::is_column( $field ) ) {
      $field = \Participants_Db::$fields[$field];
      /* @var $field \PDb_Form_Field_Def */
    }
    $this->setup_field_props( $field );
    $this->setup_columns();
    $this->setup_entry_id_list();
  }

  /**
   * tells if the field is readonly
   * 
   * @return bool true if the field is readonly
   */
  public function is_readonly()
  {
    return $this->readonly == '1';
  }

  /**
   * provides the id of the last log entry
   * 
   * @return string
   */
  public function last_entry_id()
  {
    return end( $this->log_id_list );
  }

  /**
   * supplies the entry id list
   * 
   * @return array
   */
  public function entry_id_list()
  {
    return $this->log_id_list;
  }
  
  /**
   * supplies the id of a new log entry (if any)
   * 
   * @return string
   */
  public function new_log_id()
  {
    return $this->new_log_id;
  }
  
  /**
   * tells if the post has log data to write
   * 
   * @param array $post the post data
   * @return bool true if there is log data to write
   */
  public function has_log_data( $post )
  {
    $log_entry = $this->log_entry_data($post);
    return log_table::array_has_values( $log_entry );
  }

  /**
   * updates the log
   * 
   * @param array   $post       the incoming data
   * 
   * @return array the posted data with the log and last value fields updated
   */
  public function update_field_log( $post )
  {
    // get the log entry data
    $log_entry = $this->log_entry_data($post);
    
    if ( log_table::array_has_values( $log_entry ) ) {
    
      // add the due_date value
      $user_status = new \pdbmps\payment_status\user_status($this->record_id); 
      $user_status_info = $user_status->user_status_info_print();
      $log_entry['due_date'] = $user_status_info['next_due_date'];
      
      $this->_update_log( $log_entry );
      /**
       * @action pdbmps-update_log_{$log_field_name}
       * @param array log entry data
       * @param int the current record id
       * @param string the log entry ID
       */
      do_action( 'pdbmps-update_log_' . $this->name, $this->prepare_log_entry_data( $log_entry ), $this->record_id, $this->new_log_id );

      $post[$this->name] = $this->log_id_list;
      
    }

    // add the last value field values to the post array
    // this is called even if there is no new log entry
    return last_value_fields::update( $this->columns(), $post, log_table_db::get_log( $this->last_entry_id() ) );
  }

  /**
   * removes a log entry
   * 
   * @param string  $entry_id
   * 
   * @return bool true if the entry was deleted
   */
  public function remove_entry( $entry_id )
  {
    if ( empty( $this->log_id_list ) || ! in_array( $entry_id, $this->log_id_list ) ) {
      return false;
    }
    $this->remove_list_entry( $entry_id );
    log_table_db::delete_entry( $entry_id );
    last_value_fields::store( $this->columns(), $this->record_id );
    add_filter( 'pdbmps-payment_status_list', function ($list) {
      $list[] = '';
      return $list;
    } );
    do_action( 'pdbmps-update_status_fields', $this->record_id );
    return $this->update_record_log_id_list();
  }
  
  /**
   * extracts the log data from the post data
   * 
   * @param array $post data
   * @return array the log data
   */
  private function log_entry_data( $post )
  {
    return $this->record_id && array_key_exists( $this->name, $post ) ? filter_var_array( $post[$this->name], FILTER_SANITIZE_STRING ) : array();
  }

  /**
   * updates the record with the log id list
   */
  private function update_record_log_id_list()
  {
    global $wpdb;
    $result = $wpdb->update( \Participants_Db::$participants_table, array($this->name => serialize( $this->log_id_list )), array('id' => $this->record_id) );
    return $result > 0;
  }

  /**
   * sets up the columns
   * 
   */
  private function setup_columns()
  {
    foreach ( $this->options as $name => $title ) {
      /**
       * @filter pdb-member_payments_non_column_options 
       * @param array
       * 
       * provides a way to add field definition options that are not table columns
       */
      if ( !in_array( $name, apply_filters( 'pdb-member_payments_non_column_options', array() ) ) ) {
        $this->columns[$name] = $title;
      }
    }
  }

  /**
   * provides the log column definitions
   * 
   * @return array as $name => $title
   */
  public function columns()
  {
    return $this->columns;
  }

  /**
   * updates the log entry for a single field
   * 
   * @param array   $entry_data the log field array from the form submission
   * 
   * @return array  the updated log id list
   */
  private function _update_log( $entry_data )
  {
    assert( !empty( $entry_data ), ' update called with no new data' );
    /**
     * called when manually saving a new log entry
     * 
     * @filter pdbmps-field_log_id
     * @param string  the generated id
     * @param array the new log entry data
     * @return string the ID to use for the new entry
     */
    $entry_id = apply_filters( 'pdbmps-field_log_id', log_table_db::unique_id(), $entry_data );

    // add the new entry to the field value
    log_table_db::save_entry( $this->prepare_log_entry_data( $entry_data ), $this->record_id, $this->name, $entry_id );
    $this->add_new_entry_id( $entry_id );
  }

  /**
   * prepares the data from a manual log entry for storing
   * 
   * @param array $entry_data
   * 
   * @return array the data to store
   */
  private function prepare_log_entry_data( $entry_data )
  {
    foreach ( $entry_data as $name => &$value ) {
      switch ( $name ) {
        case 'payment_date':
          $timestamp = \PDb_Date_Parse::timestamp( $value );
          $value = date( \pdbmps\payment_log\PayPal_Log::date_format, $timestamp ? : time()  );
          break;
      }
    }
    return apply_filters( 'pdbmps-log_entry', $entry_data, $this->record_id );
  }

  /**
   * adds a new entry id to the list of ids
   * 
   * @param string $entry_id the new id
   */
  private function add_new_entry_id( $entry_id )
  {
    $this->new_log_id = $entry_id;
    $this->log_id_list[] = $entry_id;
    $this->sort_id_list();
  }

  /**
   * removes an entry from the list
   * 
   * @param string  $entry_id
   */
  private function remove_list_entry( $entry_id )
  {
    unset( $this->log_id_list[array_search( $entry_id, $this->log_id_list )] );
  }

  /**
   * provides the list of IDs from the log_table database table
   * 
   * this is to rebuild the PDB record value based on what's actually in the log_table DB
   * 
   * @return array of log entry ids
   */
  private function setup_entry_id_list()
  {
    if ( $this->record_id ) {
      global $wpdb;
      $id_list = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT `entry_id` FROM ' . $wpdb->prefix . log_table_db::table_name . ' WHERE `field` = "%s" AND `record_id` = "%s"', $this->name, $this->record_id ) );

      $this->sort_id_list( $id_list );
    }
  }

  /**
   * orders an ID list based on the payment date field
   * 
   * @param array $id_list list of IDs to order
   */
  private function sort_id_list( $id_list = false )
  {
    if ( !$id_list ) {
      $id_list = $this->log_id_list;
    }
    global $wpdb;
    $sort_field = apply_filters( 'pdbmps-entry_sort_field', 'payment_date' );
    $sql = 'SELECT `entry_value`, `entry_id` FROM ' . $wpdb->prefix . log_table_db::table_name . ' WHERE `entry_id` IN ("' . implode( '","', $id_list ) . '") AND `entry_column` = "' . $sort_field . '"';
    $result = $wpdb->get_results( $sql, ARRAY_A );

    // convert the stored string dates to timestamps
    foreach ( $result as &$entry ) {
      $entry['entry_value'] = \PDb_Date_Parse::timestamp( $entry['entry_value'] );
    }

    // sort the array by the timestamps
    usort( $result, function($a, $b) {
      return $a['entry_value'] - $b['entry_value'];
    } );

    // rebuild the array as flat array of entry ids
    $list = array();
    for ( $i = 0; $i < count( $result ); $i++ ) {
      $list[] = $result[$i]['entry_id'];
    }
    $this->log_id_list = $list;
  }

  /**
   * supplies a property or attribute value
   * 
   * @param string $name of the attribute
   * 
   * @return  string  the attribute value, empty string if not found 
   */
  public function __get( $name )
  {
    if ( isset( $this->{$name} ) ) {
      return $this->{$name};
    }
    if ( isset( $this->attributes[$name] ) ) {
      return $this->attributes[$name];
    }
  }

  /**
   * provides the field value from the database
   * 
   * @param int $id the record id
   * @return array the stored value
   */
  private function participant_values( $id )
  {
    return \Participants_Db::get_participant( filter_var( $id, FILTER_SANITIZE_NUMBER_INT ) );
  }

  /**
   * adds all the properties
   * 
   * @param stdClass|\PDb_Form_Field_Def $field 
   */
  private function setup_field_props( $field )
  {
    if ( is_a( $field, '\PDb_Form_Field_Def' ) ) {
      $this->name = $field->name();
      $this->options = $field->options();
      $this->attributes = $field->attributes;
    } else {
      $this->name = $field->name;
      $this->options = $field->options;
      $this->attributes = $field->attributes;
    }
  }

}
