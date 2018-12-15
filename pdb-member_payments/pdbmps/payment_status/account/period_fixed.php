<?php

/**
 * models a payment schedule based on a fixed amount of time from the last payment
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPL3
 * @version    0.2
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\payment_status\account;

class period_fixed extends payment_schedule {
  
  /**
   * @var string name of the saved due dates option
   */
  const due_dates = 'pdbmps-user_due_dates';
  /**
   * sets up the dates
   * 
   * @param int $record_id
   */
  public function __construct( $record_id )
  {
    parent::__construct($record_id);
    $this->update_due_date();
  }

  /**
   * provides the next due date after the last payment
   * 
   * @return int timestamp
   */
  public function next_due_date()
  {
    if ( ! $this->last_payment_date ) {
      return $this->record_date_recorded();
    }
    return $this->find_next_due_date();
  }
  
  /**
   * finds the next due date
   * 
   * this works by incrementing payment periods until the due date is after the 
   * last payment date: that is the next date a payment is due
   * 
   * @return int timestamp
   */
  private function find_next_due_date()
  {
    $due_date = wp_cache_get('pdbmps-last_due_date');
    if ( false === $due_date ) {
      
      $member = new \pdbmps\Member( $this->record_id );
      $offset = in_array( $member->status, array( 'payable','paid' ) ) ? 1 : 0; // this determines the final offset to find the next due date
      
      preg_match( '#(\d*)\s*([a-z]+)#', strtolower( apply_filters( 'pdbmps-renewal_period', '1 year', $this->record_id ) ), $matches );
      $increment = empty( $matches[1] ) ? 1 : $matches[1];
      $interval = new interval( $matches[2], $this->stored_due_date() );
      
      $count = 0;
      $due_date = $this->stored_due_date();
      $i = 0; // safety
      
      while ( $due_date < $this->last_payment_date && $i < 50 ) {
        
        $count = $count + $increment;
        $i++;
      
        $due_date = $interval->next_date( $count );
      
      }
      
      // sets the offset from the last due date to find the next due date
      $due_date = $interval->next_date($count + $offset);
      wp_cache_add('pdbmps-last_due_date', $due_date);
      
    }
    return $due_date;
  }
  
  /**
   * provides the stored due date
   * 
   * @return bool|int bool false if no payment has been recorded, int timestamp 
   *                  if the first payment has been logged
   */
  private function stored_due_date()
  {
    $due_date_list = get_option( self::due_dates, array() );
    if ( isset( $due_date_list[$this->record_id] ) ) {
      return $due_date_list[$this->record_id];
    }
    return $this->record_date_recorded();
  }
  
  /**
   * provides the record's date_recorded date
   * 
   * @filter pdbmps-no_payment_uses_date_recorded
   * @param bool  default
   * @return bool true to use the record's date_recorded timestamp as the first payment date
   * 
   * @return int timestamp
   */
  private function record_date_recorded()
  {
    $record = \Participants_Db::get_participant($this->record_id);
    return apply_filters( 'pdbmps-no_payment_uses_date_recorded', true ) ? strtotime( $record['date_recorded'] ) : false;
  }
  
  /**
   * updates the payment date option
   * 
   */
  private function update_due_date()
  {
    $due_date_list = get_option( self::due_dates, array() );
    if ( $this->last_payment_date && ( ! isset( $due_date_list[$this->record_id] ) || ! $due_date_list[$this->record_id] ) ) {
      $due_date_list[$this->record_id] = $this->last_payment_date; // this would be false if there is no recorded payment
      update_option( self::due_dates, $due_date_list, false );
    }
  }

}

class interval {
  /**
   * @var string interval unit
   */
  private $unit;
  
  /**
   * @var int the base date
   */
  private $basedate;
  
  /**
   * 
   * @param string  $unit
   * @param int $basedate
   */
  public function __construct( $unit, $basedate )
  {
    $this->unit = $unit;
    $this->basedate = $basedate;
  }
  
  /**
   * supplies the date with the given offset
   * 
   * @param int $offset number of units to add to the date
   * @return int timestamp
   */
  public function next_date( $offset )
  {
    if ( $offset == 0 ) {
      return $this->basedate;
    }
    $date_mod = sprintf( '+%d %s', $offset, $this->unit);
    error_log(__METHOD__.' date mod: '.$date_mod);
    return strtotime( $date_mod, $this->basedate );
  }
}
