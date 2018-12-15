<?php

/**
 * models the dates for a payment schedule
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

abstract class payment_schedule {
  /**
   * @var int participant ID
   */
  protected $record_id;
  /**
   * @var int the date of the last payment unix timestamp
   */
  protected $last_payment_date;
  /**
   * @var int the due ate at the time of the payment unix timestamp
   */
  protected $last_due_date;
  /**
   * @var int holds the number of intervals added to the payment date
   * 
   * this advences the next due date this number of intervals to get the new due date
   */
  protected $period_count = 1;
  /**
   * sets up the dates
   * 
   * @param int $record_id
   */
  public function __construct( $record_id )
  {
    $this->record_id = $record_id;
    $this->setup_payment_dates();
    add_action( 'pdbmps-update_status_fields', array( get_class( $this ), 'clear_cache' ) );
  }
  
  /**
   * provides object property values
   * 
   * @param string $name name of the property
   * 
   * @return int|string timestamp for date, string otherwise
   */
  public function __get( $name )
  {
    switch (true) {
      case ( isset( $this->{$name} ) ) :
        return $this->{$name};
      default:
        return false;
    }
  }

  /**
   * tells if the user paid the last due and the payable date has passed
   * 
   * @return bool true if the user paid within the payment period of the last due date
   */
  public function account_is_payable()
  {
    return $this->next_payable_date() <= $this->current_date();
  }

  /**
   * tells if the user paid the last due and the payable date has passed
   * 
   * @return bool true if the user paid within the payment period of the last due date
   */
  public function account_is_due()
  {
    return $this->next_due_date() <= $this->current_date();
  }

  /**
   * tells if the user has not made a payment for the last due date
   * 
   * @return bool true if the user owes from the last due date
   */
  public function account_is_past_due()
  {
    if ( $this->no_payment_has_been_made() ) {
      // no payment has been made
      return true;
    }
    return $this->next_past_due_date() <= $this->current_date();
  }
  
  /**
   * tells if there is no last payment
   * 
   * @return bool true if no payment has been recorded
   */
  public function no_payment_has_been_made()
  {
    return $this->last_payment_date === false;
  }

  /**
   * determines the date of the last payment made by the member
   * 
   * @global object $wpdb
   * @return int|bool timestamp or bool false if not found
   */
  protected function setup_payment_dates()
  {
    $last_entry = $this->last_valid_payment();
    
    $this->last_payment_date = isset( $last_entry['payment_date'] ) ? \PDb_Date_Parse::timestamp( $last_entry['payment_date'], array('zero_time' => true), __METHOD__ ) : false;
    /*
     * the last due date is the due date that was active at the time of the last 
     * payment. If there was no last payment, the last due date will be the record 
     * date recorded date
     */
    $this->last_due_date = isset( $last_entry['due_date'] ) ? \PDb_Date_Parse::timestamp( $last_entry['due_date'], array('zero_time' => true), __METHOD__ ) : $this->last_payment_date;
  }
  
  /**
   * supplies that last valid log entry
   * 
   * @return array of log data from the last valid entry or bool false if no valid entry
   */
  protected function last_valid_payment()
  {
    foreach( \pdbmps\fields\log_table_db::get_all_entries($this->record_id) as $log_entry ) {
      if ( $this->is_valid_log_entry( $log_entry ) ) {
        return $log_entry;
      }
    }
    return false;
  }

  /**
   * checks a log entry for validity
   * 
   * "valid" in this case means a positive, non-negative amount
   * 
   * @param array $log_entry
   * @return bool true if iut is a valid payment entry
   */
  protected function is_valid_log_entry( $log_entry )
  {
    /**
     * @filter pdbmps-is_valid_log_entry
     * @param bool validity determination
     * @param array log entry data
     * @param int record id
     * @return bool true if a valid entry
     */
    return apply_filters( 'pdbmps-is_valid_log_entry', isset( $log_entry['mc_gross'] ) && floatval( $log_entry['mc_gross'] ) > 0, $log_entry, $this->record_id );
  }
  
  /**
   * clears the cache
   * 
   * @param int $record_id
   */
  public static function clear_cache( $record_id )
  {
    wp_cache_replace($record_id, false, period_dynamic::cachegroup );
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
  protected function record_date_recorded()
  {
    $record = \Participants_Db::get_participant($this->record_id);
    
    return apply_filters( 'pdbmps-no_payment_uses_date_recorded', true ) ? strtotime( $record['date_recorded'] ) : false;
  }
  
  /**
   * finds the last due date
   * 
   * @return  int timestamp
   */
  protected function find_last_due_date()
  {
    if ( $this->last_payment_date ) {
      return strtotime( '+' . apply_filters( 'pdbmps-renewal_period', 'year' ), $this->last_payment_date  );
    } else {
      return $this->record_date_recorded();
    }
  }
  
  /**
   * provides the date of the last payment
   * 
   * @param int|bool $default value to return of there is no last payment
   * @return int timestamp
   */
  public function last_payment_date( $default = false )
  {
    return $this->last_payment_date ? : $default;
  }
  
  /**
   * provides the date of the last payment
   * 
   * @return int timestamp
   */
  public function current_due_date()
  {
    return $this->last_due_date;
  }
  
  /**
   * provides the next due date after the last payment
   * 
   * @return int timestamp
   */
  abstract public function next_due_date();
  
  /**
   * provides the current date
   * 
   * @return int unix timestamp
   */
  public function current_date()
  {
    return apply_filters('pdb-member_payments_test_date', time() );
  }

  /**
   * provides the date of the beginning of the next payable period
   * 
   * @return int unix timestamp
   */
  public function next_payable_date()
  {
    return strtotime( '-' . $this->payable_start_offset() . ' days', $this->next_due_date() );
  }

  /**
   * provides the start date of the next past due period
   * 
   * @return intunix timestamp
   */
  public function next_past_due_date()
  {
    return strtotime( '+' . $this->past_due_start_offset() . ' days', $this->next_due_date() );
  }
  
  /**
   * sets the number of periods in the purchase
   * 
   * @param int $count the number of periods getting purchased
   */
  public function set_period_count( $count ) {
   $this->period_count = (int) $count;
  }


  /**
   * provides the offset for the payable period start
   * 
   * @return int number of days for the offset
   */
  protected function payable_start_offset()
  {
    /*
     * this ensures the payable offset plus the past_due offset does not exceed the time between due dates
     */
    return min( array( $this->status_offset('payable'), $this->days_between_due_dates() - $this->past_due_start_offset() ) );
  }
  
  /**
   * provides the past_due period start offset
   * 
   * this is limited to the time available between due dates
   * 
   * @return int offset in days
   */
  protected function past_due_start_offset()
  {
    return min( array( $this->status_offset('past_due'), $this->days_between_due_dates() ) );
  }
  
  /**
   * provides the number of days between due dates
   * 
   * @return int number of days
   */
  protected function days_between_due_dates()
  {
    // minus 1 so that there won't be any overlap
    return intval( $this->next_due_date() - $this->last_payment_date( $this->record_date_recorded() ) / DAY_IN_SECONDS ) - 1;
  }

  /**
   * provides the day offset for the start of the given status
   * 
   * @param string  $status
   * @return  int  status offset
   */
  protected function status_offset( $status )
  {
    $params = apply_filters( 'pdbmps-' . $status . '_label', $status );
    return is_array( $params ) ? $params['offset'] : $params;
  }
  
  
  
  /**
   * compare dates, ignoring time differences
   * 
   * @param string $date_1 the first date
   * @param string $date_2 the second date
   * 
   * @return bool true if the two date match
   */
  public static function same_date( $date_1, $date_2 )
  {
    $config = array('zero_time' => true );
    $first_date = \PDb_Date_Parse::timestamp( $date_1, $config );
    $second_date = \PDb_Date_Parse::timestamp( $date_2, $config );
    
    return date( get_option('date_format'), $first_date ) === date( get_option('date_format'), $second_date );
  }
}
