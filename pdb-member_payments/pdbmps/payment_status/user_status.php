<?php

/**
 * tells a user's current status
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPL3
 * @version    0.5
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\payment_status;

class user_status {
  
  /**
   * @var object payment dates object
   */
  private $payment_schedule;

  /**
   * @var string the current user's payment status, one of the 4 payment statuses
   */
  private $status;
  
  /**
   * @var string|bool holds the pending status label or bool false
   */
  private $pending_status;

  /**
   * @param int|bool  $record_id  the user's record ID or bool false if the 
   *                              current record does not have an ID yet
   * @param int       $period_count number of payment periods to add
   */
  public function __construct( $record_id, $period_count = 1 )
  {
    if (PDB_DEBUG) assert( is_numeric($record_id), 'user status constructor ' );
    
    if ( $record_id ) {
      switch( apply_filters( 'pdbmps-payment_due_mode', 'period' ) ) {
        case 'fixed':
          $this->payment_schedule = new account\fixed( $record_id );
          break;
        case 'period':
        default:
          $this->payment_schedule = new account\period_dynamic( $record_id );
          break;
      }
      $this->payment_schedule->set_period_count($period_count);
      
      /* @var $pending pdbmps\fields\pending_payment_field */
      $pending = apply_filters('pdbmps-pending_payment_status', false );
      $this->pending_status = $pending->pending_status( $record_id );
    }
    $this->set_user_status();
  }
  
  /**
   * supplies the status value
   * 
   * @return string the status
   */
  public function status()
  {
    return $this->pending_status ? : $this->status;
  }
  
  /**
   * supplies the status value
   * 
   * @return string the status
   */
  public function status_title()
  {
    $setting = apply_filters('pdbmps-' . $this->status() . '_label', $this->status() );
    if ( is_array( $setting ) ) {
      $setting = $setting['title'];
    }
    return $setting;
  }
  
  /**
   * checks for a status change
   * 
   * @paran string  $old_status the saved status value
   * @return bool true if the user status has changed
   */
  public function check_for_status_change( $old_status )
  {
    return $this->trigger_status_change_event( $old_status );
  }

  /**
   * provides the current user status string
   * 
   * @param int $record_id the user's record ID
   * @return string the status string
   */
  public static function current_status( $record_id )
  {
    $status = new self( $record_id );
    return $status->status();
  }
  
  /**
   * shortcut to getting the user's status info array
   * 
   * this converts timestamps to human-readable format
   * 
   * @param int $record_id of the current record
   * @return array
   */
  public static function info( $record_id )
  {
    $status = new self( $record_id );
    return $status->user_status_info_print();
  }
  
  /**
   * shortcut to getting the user's status info array
   * 
   * this converts timestamps to human-readable format
   * 
   * @return array
   */
  public function user_status_info_print()
  {
    $status_array = $this->user_status_info();
    array_walk( $status_array, array( $this, 'date_timestamps' ) );
    return $status_array;
  }
  
  
  /**
   * supplies a user status info array
   * 
   * @return array
   */
  public function user_status_info()
  {
    $status_info = array(
          'last_payment_date' => '',
          'current_due_date'  => '',
          'next_due_date'     => '',
          'current_date'      => '',
          'payable_date'      => '',
          'past_due_date'     => '',
          'payment_status'    => $this->status(),
      );
    if ( is_object( $this->payment_schedule ) ) {
      $status_info = array(
          'last_payment_date' => $this->payment_schedule->last_payment_date(),
          'current_due_date'  => $this->payment_schedule->current_due_date(),
          'next_due_date'     => $this->payment_schedule->next_due_date(),
          'current_date'      => $this->payment_schedule->current_date(),
          'payable_date'      => $this->payment_schedule->next_payable_date(),
          'past_due_date'     => $this->payment_schedule->next_past_due_date(),
          'payment_status'    => $this->status(),
      );
    }
    return $status_info;
  }
  
  /**
   * supplies a date string if the input is a timestamp
   * 
   * @param string $value
   * @param string  int $key
   * @return string
   */
  public function date_timestamps( &$value, $key )
  {
    if ( is_numeric( $value ) && stripos( $key, 'date' ) !== false ) {
      $value = $this->_date($value);
    }
  }
  
  /**
   * shows a human-readible date for a timestamp
   * 
   * @param int $timestamp
   * @return string date
   */
  protected function _date( $timestamp )
  {
    return date( get_option( 'date_format' ), $timestamp );
  }

  /**
   * sets the current user's status
   * 
   */
  private function set_user_status()
  {
    if ( ! is_object( $this->payment_schedule ) || ! $this->payment_schedule->last_payment_date() ) {
      $this->status = apply_filters( 'pdbmps-initial_status', '' );
    } elseif ( $this->payment_schedule->account_is_past_due() ) {
      $this->status = 'past_due';
    } elseif ( $this->payment_schedule->account_is_payable() ) {
      $this->status = $this->payment_schedule->account_is_due() ? 'due' : 'payable';
    } else {
      // the user paid last due date, but the next payable period has not begun
      $this->status = 'paid';
    }
  }

  /**
   * triggers the event for a status change
   * 
   * @paran string  $old_status the saved status value
   *
   * @global \pdbmps\Plugin $PDb_Member_Payments
   * @return bool true if there is a new status
   */
  private function trigger_status_change_event( $old_status )
  { 
    if ( PDB_DEBUG >= 3 ) error_log(__METHOD__.' prev status: '. $old_status . ' new status: '. $this->status);
    
    $change = false;

    /*
     * the change is processed if there is a new status value and the old status 
     * is a valid status value
     * 
     */
    if ( in_array( $old_status, payment_status_field::status_list() ) && $old_status !== $this->status ) {
      // we have a new status
      $change = true;
      global $PDb_Member_Payments;
      $data = array_merge( \Participants_Db::get_participant( $this->payment_schedule->record_id ), $PDb_Member_Payments->transaction_data(), $this->user_status_info() );
      
      if ( PDB_DEBUG ) { error_log(__METHOD__.' 
          
doing action: '. 'pdbmps-status_change_to_' . $this->status . ' from previous status of: '. $old_status );
      }
      
      /**
       * @action pdbmps-status_change_to_{$status}
       * @param array $record the current user's record data
       * @param account\payment_schedule payment schedule
       * 
       * statuses will be:
       *  paid
       *  payable
       *  due
       *  past_due
       */
      do_action( 'pdbmps-status_change_to_' . $this->status, apply_filters( 'pdbmps-template_data', $data ), $this->payment_schedule );
    }
    return $change;
  }

}
