<?php

/**
 * models a payment period based on fixed dates
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPL3
 * @version    0.1
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\payment_status\account;

class fixed extends payment_schedule {
  
  /**
   * @var array of fixed spayment schedule dates
   * 
   */
  private $date_map;
  
  /**
   * sets up the dates
   * 
   * @param int $record_id
   */
  public function __construct( $record_id )
  {
    parent::__construct($record_id);
    $this->date_map = $this->fixed_date_map();
  }

  /**
   * provides the next due date after the last payment
   * 
   * @return int timestamp
   */
  public function next_due_date()
  {
    return $this->next_fixed_payment_date();
  }
  
  /**
   * provides the fixed date closest to the last payment date
   * 
   * @return int timestamp
   */
  private function next_fixed_payment_date()
  {
    static $show = false;
    $previous_date = reset( $this->date_map );
    foreach ( $this->date_map as $next_date ) {
    
    if ($show) error_log(__METHOD__.' payable start: '.date( get_option('date_format'), strtotime( '-' . $this->status_offset('payable') . 'days', $next_date ) ) . ' payment date: ' . date( get_option('date_format'), $next_date ) );
    
      if ( strtotime( '-' . $this->status_offset('payable') . 'days', $next_date ) > $this->last_payment_date ) {
        break;
      }
      $previous_date = $next_date;
    }
    
    $show = false;
    
    return $next_date;
  }

  /**
   * builds a fixed date map
   * 
   * this takes the yearless date and gives it a year
   * 
   * @return array of fixed date timestamps
   */
  private function fixed_date_map()
  {
    $map = wp_cache_get( 'pdbmps_fixed_date_map' );
    if ( $map === false ) {
      $map = array();
      $fixed_date_setting = explode( "\n", apply_filters( 'pdbmps-renewal_date_list', "jan 1\njul 1" ) ); // defaults to biennial due dates
      $following_year = ', ' . date( 'Y', strtotime( '+ 2 year' ) );
      $next_year = ', ' . date( 'Y', strtotime( '+ 1 year' ) );
      $this_year = ', ' . date( 'Y', time() );
      $last_year = ', ' . date( 'Y', strtotime( '- 1 year' ) );
      
      foreach ( $fixed_date_setting as $date_string ) {
        foreach( array( $last_year, $this_year, $next_year, $following_year ) as $year ) {
          $map[] = \PDb_Date_Parse::timestamp( trim( $date_string ) . $year );
        }
      }
      sort( $map, SORT_NUMERIC );
      wp_cache_set( 'pdbmps_fixed_date_map', $map );
      //error_log(__METHOD__.' map: '.print_r($map,1));
    }
    return $map;
  }
  
}
