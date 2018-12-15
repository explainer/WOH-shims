<?php

/**
 * models a payment schedule based on a fixed amount of time from the last payment
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPL3
 * @version    0.4
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\payment_status\account;

class period_dynamic extends payment_schedule {
  
  /**
   * @var string name of the next due date cache group
   */
  const cachegroup = 'pdbmps-next_due_date';
  
  /**
   * @var int the renewal period interval
   */
  private $renewal_interval;
  
  /**
   * @var string the renewal period unit
   */
  private $renewal_unit;

  /**
   * sets up the dates
   * 
   * @param int $record_id
   */
  public function __construct( $record_id )
  {
    parent::__construct( $record_id );
    
    $this->setup_renewal_period_data();
    
    add_action('pdbmps-update_log_' . \pdbmps\payment_log\log_field::log_field_name(), array( $this, 'set_initial_payment_due_date' ), 50, 3 );
  }

  /**
   * provides the next due date after the last payment
   * 
   * @return int timestamp
   */
  public function next_due_date()
  {
    //error_log(__METHOD__.' last payment: '. date(get_option('date_format' ), $this->last_payment_date ). ' next due: '.date(get_option('date_format' ), $this->find_next_due_date() ) );
    if ( !$this->last_payment_date ) {
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
    $due_date = wp_cache_get( $this->record_id, self::cachegroup  );
    if ( false === $due_date ) {

      $base_date = $this->last_due_date;

      if ( $this->last_payment_date > $this->last_due_date ) { // if the payment came after the due date
        if (
                apply_filters( 'pdbmps-late_payment_interval', 0 ) > 0 
                && strtotime( '-' . apply_filters( 'pdbmps-late_payment_interval', 0 ) . ' day', $this->last_payment_date ) <= $this->last_due_date ) {
          /*
           * if the late payment interval is in use and it's later than the last payment interval
           */
          $base_date = $this->last_payment_date;
        } else {
          /*
           * adjust the base date to the late payment setting:  
           *    if "last due" the base date is the last due date
           *    if "last payment" the base date is the current payment date
           */
          $base_date = apply_filters( 'pdbmps-late_payment_setting', 'last_due' ) === 'last_due' ? $this->last_due_date : $this->last_payment_date;
        }
      }
      
//      error_log(__METHOD__.' last payment date: '.$this->last_payment_date.' las due date: '.$this->last_due_date .' base date: '.$base_date );

      $interval = new interval( $this->renewal_unit, $base_date );

      $count = $this->period_count; // this is the initial count: adds additional periods for a multi-period purchase
      $due_date = $base_date; // start with the base date
      $i = 0; // safety

      while ( $due_date < $base_date && $i < 50 ) {

        $count = $count + $this->renewal_interval;
        $i++;

        $due_date = $interval->next_date( $count );
      }

      // sets the offset from the last due date to find the next due date
      $due_date = $interval->next_date( $count );

//      error_log( __METHOD__ . ' count: ' . $count . ' base date: ' . date( get_option( 'date_format' ), $base_date ) . ' due date: ' . date( get_option( 'date_format' ), $due_date ) );

      wp_cache_set( $this->record_id, $due_date, self::cachegroup );
    }
    return $due_date;
  }
  
  /**
   * sets the initial payment due date
   * 
   * @param array $log_data
   * @param int $record_id
   * @param string $entry_id
   */
  public function set_initial_payment_due_date( $log_data, $record_id, $entry_id )
  {
    /* if the log due date is the same as the record date recorded this means 
     * the date recorded value was used to set the due date
     */
    if ( self::same_date( $log_data['due_date'], $this->record_date_recorded() ) && $this->is_valid_log_entry( $log_data ) ) { 
      \pdbmps\fields\log_table_db::write_entry_value($entry_id, 'due_date', date('F j, Y', strtotime( $log_data['payment_date'] ) ) ); // the initial due date is the current payment date
    }
  }
  
  /**
   * sets up the renewal period data
   * 
   * the renewal interval is an integer of the number of units in the renewal period
   * the renewal unit is going to be something like 'year', 'month' etc. 
   * 
   */
  private function setup_renewal_period_data()
  {
    preg_match( '#(\d*)\s*([a-z]+)#', strtolower( apply_filters( 'pdbmps-renewal_period', '1 year', $this->record_id ) ), $matches );
    $this->renewal_interval = empty( $matches[1] ) ? 1 : (int) $matches[1];
    $this->renewal_unit = $matches[2];
  }

  /**
   * provides the user paid status
   * 
   * compares the last payment date to the last due date to find the payment status
   * 
   * @return bool true if the user paid during the payable/due period last time
   */
  private function user_paid_status()
  {
    if ( $this->last_due_date ) {
      $last_due_date_payable_date = strtotime( '-' . $this->status_offset( 'payable' ) . ' days', $this->last_due_date );
      $last_due_date_past_due_date = strtotime( '+' . $this->status_offset( 'past_due' ) . ' days', $this->last_due_date );

      error_log( __METHOD__ . ' 
        
payable: ' . date( get_option( 'date_format' ), $last_due_date_payable_date ) . ' 
  
due date: ' . date( get_option( 'date_format' ), $this->last_due_date ) . ' 
  
past: ' . date( get_option( 'date_format' ), $last_due_date_past_due_date ) . ' 
  
last payment: ' . date( get_option( 'date_format' ), $this->last_payment_date( $this->record_date_recorded() ) )
      );

      return $last_due_date_payable_date < $this->last_payment_date( $this->record_date_recorded() ) && $this->last_payment_date( $this->record_date_recorded() ) < $last_due_date_past_due_date;
    }
    return false;
  }

}

/**
 * provides a date by multiples of period intervals
 */
class interval {

  /**
   * @var string interval unit as strtotime-readable string
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
   * supplies the next date with the given offset
   * 
   * @param int $offset number of units to add to the date
   * @return int timestamp
   */
  public function next_date( $offset )
  {
    return $this->calc_date($offset);
  }

  /**
   * supplies the previous date with the given offset
   * 
   * @param int $offset number of units to add to the date
   * @return int timestamp
   */
  public function previous_date( $offset )
  {
    return $this->calc_date( $offset, false );
  }

  /**
   * supplies the date with the given offset before or after the base date
   * 
   * @param int $offset number of units to offset
   * @param bool  $after if true, look for the interval date after the base date
   * @return int timestamp
   */
  private function calc_date( $offset, $after = true )
  {
    if ( $offset == 0 ) {
      return $this->basedate;
    }
    $date_mod = sprintf( ( $after ? '+' : '-' ) . '%d %s', $offset, $this->unit );
    error_log(__METHOD__.' date mod: '.$date_mod);
    return strtotime( $date_mod, $this->basedate );
  }

}
