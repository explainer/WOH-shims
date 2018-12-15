<?php

/**
 * handles updating all the records' payment status on a cron
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2017  xnau webdesign
 * @license    GPL3
 * @version    0.1
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\payment_status;

class update_all {
  
  /**
   * @var string name of the id list option
   */
  const saved_list = 'pdbmps-update_id_list';
  
  /**
   * @var int records the start time in microseconds for the class instantiation
   */
  private $start_time;
  
  /**
   * @var int number of seconds the script can run
   */
  private $time_limit;
  
  /**
   * @var array list of all the ids that need processing
   */
  private $id_list;
  
  /**
   * 
   */
  private function __construct()
  {
    $this->start_time = microtime(true);
    $this->set_time_limit();
    $this->id_list = $this->get_id_list();
  }
  
  /**
   * starts the update process
   * 
   * @return array list of processed ids
   */
  public static function start()
  {
    $update = new self();
    return $update->update_records();
  }
  
  /**
   * updates records until the timeout
   * 
   * 
   * @return array list of processed ids
   */
  private function update_records()
  {
    $processed = array();
    $ids = $this->id_list;
    reset( $ids );
    while ( ! empty( $this->id_list ) && ! $this->time_to_quit() ) {
      $processed[] = $this->update_record( current( $ids ) );
      next( $ids );
    }
    $this->save_id_list();
    if ( PDB_DEBUG ) {
      error_log(__METHOD__.' '.count($processed).' records updated in ' . (microtime(true) - $this->start_time) . ' seconds. Date: '.date( get_option( 'date_format') . ' ' . get_option( 'time_format' ) ) );
    }
    return $processed;
  }
  
  /**
   * updates a record
   * 
   * @param int $id the record id
   * @return int $id that was processed
   */
  private function update_record( $id )
  {
    do_action('pdbmps-write_payment_status', $id );
    $this->update_list( $id );
    return $id;
  }
  
  /**
   * @var array the list of IDs to process
   */
  private function get_id_list()
  {
    return get_option( self::saved_list, $this->get_db_list() );
  }
  
  /**
   * updates the id list
   * 
   */
  private function save_id_list()
  {
    if ( empty( $this->id_list ) ) {
      delete_option( self::saved_list );
    } else {
      update_option( self::saved_list, $this->id_list, false );
    }
  }
  
  /**
   * removes a processed id from the list
   * 
   * @param string $value the value to remove
   */
  private function update_list( $value )
  {
    unset( $this->id_list[array_search($value, $this->id_list)] );
  }
  
  /**
   * provides the list of ids from the DB
   * 
   * @return array
   */
  private function get_db_list()
  {
    global $wpdb;
    return $wpdb->get_col( apply_filters( 'pdbmps-update_all_id_list_query', 'SELECT `id` FROM ' . \Participants_Db::$participants_table ) );
  }
  
  /**
   * checks to see if it is quitting time
   * 
   * @return bool true if it is time to stop processing
   */
  private function time_to_quit()
  {
    return ( $this->start_time + $this->time_limit ) < microtime(true);
  }
  
  /**
   * sets the time limit value
   * 
   */
  private function set_time_limit()
  {
    $max_setting = ini_get( 'max_execution_time' );
    $this->time_limit = apply_filters('pdbmps-max_full_update_time', $max_setting + 2 ); // the 2 is to give us time for slop and to finish
  }
}
