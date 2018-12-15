<?php

/**
 * manages logging of PayPal return data
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPLv3
 * @version    0.9
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps;

class PayPal_Log {

  /**
   * @var string base name of the log field
   */
  const basename = 'pdbmps_payment_log';

  /**
   * @var string name of the transaction id column
   */
  const id_column = 'txn_id';
  
  /**
   * @var string  the PP date format
   */
  const date_format = 'H:i:s M j, Y T';

  /**
   * @var int the record ID
   */
  private $id;

  /**
   * this class is meant to be instantiated in a context where the record id is 
   * known and you want to interact with the payment log data in that record
   * 
   * @param int $id of the current record
   */
  public function __construct( $id )
  {
    $this->id = $id;
  }

  /**
   * supplies the name of the logging field
   * 
   * @return string
   */
  public static function log_field_name()
  {
    return get_option( self::log_field_name_option(), '' );
  }

  /**
   * provides a shortcut method for adding a log entry
   * 
   * overwrites an existing entry with the same transaction id
   * 
   * @param int $in the record id
   * @param asrray $data associative array of data values
   * @return null
   */
  public static function update_entry( $id, $data )
  {
    $log = new self( $id );
    $log->add_log_entry( $data, true );
  }

  /**
   * provides a shortcut method for adding a log entry
   * 
   * this will only add the entry if the transaction ID is not found
   * 
   * @param int $in the record id
   * @param asrray $data associative array of data values
   * @return null
   */
  public static function add_new_entry( $id, $data )
  {
    $log = new self( $id );
    $log->add_log_entry( $data, false );
  }

  /**
   * adds a new log entry
   * 
   * @param array $data log data as $name => $value
   * @param bool  $overwrite  if true, overwrite a log entry with a matching TXN ID, 
   *                          if false, a matching entry will not be logged to prevent 
   *                          duplicates
   */
  public function add_log_entry( $data, $overwrite = false )
  {
    /**
     * @filter pdb-member_payments_log_entry
     * 
     * provides access to the log entry data before it is saved
     * 
     * @param array the raw data from PayPal
     * @param int the current record ID
     * @return array
     */
    $data = apply_filters( 'pdbmps-log_entry', $data, $this->id );
    $data[fields\last_payment_type::status_field_name] = 'paypal';
    
    $log_row = array();
    foreach ( array_keys( $this->log_column_names() ) as $column ) {
      $log_row[$column] = isset( $data[$column] ) ? filter_var( $data[$column], FILTER_SANITIZE_STRING ) : '';
    }
    // use the test date for the payment if configured
    $payment_date = apply_filters('pdb-member_payments_test_date', $log_row['payment_date'] );
    $log_row['payment_date'] = \PDb_Date_Display::is_valid_timestamp( $payment_date ) ? date( self::date_format, $payment_date ) : $payment_date;
    
    $data[ self::log_field_name() ] = $log_row;
    
    $log_entry_id = $this->transaction_log_id( $log_row[self::id_column] );

    $posted = false;
    if ( $overwrite === true && $log_entry_id ) {
      // overwriting: delete matching entry fron the log list and write new entry
      fields\log_table_db::delete_entry($log_entry_id);
      $posted = apply_filters( 'pdbmps-update_field_log', self::log_field_name(), $data, $this->id );
    } elseif ( $log_entry_id === false ) {
      $posted = apply_filters( 'pdbmps-update_field_log', self::log_field_name(), $data, $this->id );
    } else {
      return;
    }
    if ( $posted ) {
      $this->update_record_log_list( $posted[ self::log_field_name() ] );
      do_action( 'pdbmps-update_status_fields', $this->id, 'due' );
    }
    /**
     * @action pdbmps-paypal_log_stored
     * @param int the record id
     * @param array the PayPal response data
     */
    do_action( 'pdbmps-paypal_log_stored', $this->id, $data );
    do_action( 'pdbmps-set_last_payment_type', $this->id, 'paypal' );
  }
  
  /**
   * updates the record with the current log id list
   * 
   * @param array $log_id_list current list of log ids
   */
  private function update_record_log_list( $log_id_list )
  {
    global $wpdb;
    $wpdb->update( \Participants_Db::$participants_table, array( self::log_field_name() => serialize( $log_id_list ) ), array( 'id' => $this->id ) );
  }

  /**
   * removes log entries from a time range
   * 
   * @param int $range_max  the latest date to remove (unix ts)
   * @param int $range_min  the earliest date to remove, if omitted all logs up to 
   *                        the max date will be removed
   */
  public function remove_logs( $range_max, $range_min = 0 )
  {
    array_filter( $this->all_logs(), function ($k) use ( $range_max, $range_min ) {
      ( $k > $range_min && $k < $range_max ) === false;
    }, ARRAY_FILTER_USE_KEY );
  }

  /**
   * supplies the payment log column names
   * 
   * the names correspond to field names in the PayPal PDT reponse
   * @link https://developer.paypal.com/docs/classic/ipn/integration-guide/IPNandPDTVariables/#id091EB04C0HS
   * 
   * @retrun array of $name => $title
   */
  public function log_column_names()
  {
    global $PDb_Member_Payments;
    $columns = $PDb_Member_Payments->initial_log_columns();
    if ( self::log_field_exists() ) {
      $log_field = \Participants_Db::$fields[self::log_field_name()];

      $options = isset( $log_field->values ) ? maybe_unserialize( $log_field->values ) : array();
      if ( is_array( $options ) ) {
        $columns = $options;
      }
      /**
       * make sure the transaction id value is present
       */
      $columns += array('txn_id' => __( 'Transaction ID', 'pdb_member_payments' ));
    }
    return $columns;
  }

  /**
   * provides a log ID value
   * 
   * finds the log entry ID, given the transaction ID
   * 
   * @param string  $id the transaction ID to locate
   * 
   * @return string|bool the log intry ID for the transaction, bool false if not found
   */
  public function transaction_log_id( $txn_id )
  {
//    global $wpdb;
//    $result = $wpdb->get_var( $wpdb->prepare( 'SELECT `entry_id` FROM ' . fields\log_table_db::table_name . ' WHERE `entry_value` = "%s"', $txn_id ) );
//    return is_null($result) ? false : $result;
    return apply_filters( 'pdb-log_table_get_single_entry_id', $txn_id );
  }

  /**
   * provides a log index value
   * 
   * this will be the matched transaction or a new timestamp if no mtached transaction is found
   * 
   * @param string $txn_id the transaction id
   * 
   * @return int timestamp
   */
  public function log_index( $txn_id )
  {
    return ( $index = $this->find_log_index( $txn_id ) ) ? $index : time();
  }

  /**
   * finds the timestamp of a log entry, given a transaction ID
   * 
   * @param string  $txn_id the transaction ID to find
   * 
   * @return  int  the timestamp of the found log entry
   */
  public function find_log_index( $txn_id )
  {
    $matched_entry = array_filter( $this->all_logs(), function ( $v ) use ( $txn_id ) {
      return array_search( $txn_id, $v ) !== false;
    } );
    return is_array( $matched_entry ) ? key( $matched_entry ) : false;
  }

  /**
   * activates the PayPal log
   * 
   */
  public static function activate()
  {
    /**
     * create the payment record field
     */
    if ( !self::log_field_exists() ) {
      global $PDb_Member_Payments;
      $name = get_option( self::log_field_name_option(), $name = Init::unique_name( self::basename ) );
      \Participants_Db::add_blank_field( array_merge( array(
          'name' => $name,
          'title' => self::log_field_title(),
          'form_element' => 'log-table',
          'group' => Init::find_admin_group(),
          'order' => 100, // this doesn't matter much
          'validation' => 'no',
          'values' => serialize( $PDb_Member_Payments->initial_log_columns() ),
          'readonly' => 1,
          'CSV' => 1,
                      ),
          fields\status_field::get_field_def( $name )
              )
      );
      update_option( self::log_field_name_option(), $name );
    }
  }

  /**
   * deactivates the PP log
   */
  public static function deactivate()
  {
    fields\status_field::store_field_def( self::log_field_name() );
    Init::delete_field( self::log_field_name() );
  }

  /**
   * gets the log array
   * 
   * @return array of arrays of log entry ids
   */
  private function all_logs()
  {
    $logs = wp_cache_get( $this->id, self::basename );
    if ( $logs === false ) {
      $logs = fields\log_table_db::get_logs( $this->all_log_indexes() );
      wp_cache_add( $this->id, $logs, self::basename );
    }
    return $logs;
  }

  /**
   * gets the log array
   * 
   * @return array of arrays of log entry ids
   */
  private function all_log_indexes()
  {
    global $wpdb;
    $sql = 'SELECT `' . self::log_field_name() . '` FROM ' . \Participants_Db::$participants_table . ' WHERE `id` = "' . $this->id . '"';
    return (array) maybe_unserialize( $wpdb->get_var( $sql ) );
  }

  /*
   * static metods used in setting up the logging field
   */

  /**
   * supplies the PayPal log field title
   * 
   * @return  string
   */
  public static function log_field_title()
  {
    return _x( 'Payment Log', 'title for the payment log', 'pdb_member_payments' );
  }

  /**
   * supplies the log field name option name
   * 
   * @return string
   */
  public static function log_field_name_option()
  {
    return 'pdb-' . self::basename . '_field_name';
  }

  /**
   * checks for the presence of the payment log field
   * 
   * @return bool true if the field exists
   */
  public static function log_field_exists()
  {
    return array_key_exists( self::log_field_name(), \Participants_Db::$fields );
  }

}
