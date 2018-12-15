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

class period_static extends payment_schedule {
  /**
   * sets up the dates
   * 
   * @param int $record_id
   */
  public function __construct( $record_id )
  {
    parent::__construct($record_id);
  }

  /**
   * provides the next due date after the last payment
   * 
   * @return int timestamp
   */
  public function next_due_date()
  {
    
    //error_log(__METHOD__.' last payment date: '.date( get_option('date_format'), $this->last_payment_date ).' due date: '. date( get_option('date_format'), strtotime( '+' . apply_filters( 'pdbmps-renewal_period', '1 year', $this->record_id ), $this->last_payment_date ) ) );
    
    if ( ! $this->last_payment_date ) {
      return $this->record_date_recorded();
    }
    return strtotime( '+' . apply_filters( 'pdbmps-renewal_period', '1 year', $this->record_id ), $this->last_payment_date );
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

}
