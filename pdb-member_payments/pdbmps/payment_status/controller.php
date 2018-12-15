<?php

/**
 * manages the user's payment status
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPL3
 * @version    0.3
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\payment_status;

class controller {

  /**
   * @var status_field object for the member payment status field
   */
  private $payment_status_field;

  /**
   * @var next_due_date field object
   */
  private $next_due_date_status_field;

  /**
   * 
   */
  public function __construct()
  {
    add_action( 'plugins_loaded', array($this, 'initialize') );
    add_filter( 'pdbmps-global_plugin_events', array($this, 'register_events') );
  }

  /**
   * 
   */
  public function initialize()
  {
    if ( apply_filters( 'pdbmps-payment_status_enable', false ) ) {
      $this->payment_status_field = new payment_status_field();
      $this->next_due_date_status_field = new next_due_date();
      new last_payment_date();
      add_action( 'pdb-after_submit_update', array($this, 'update_status_fields') );
      add_action( \pdbmps\Init::cron_hook, array($this, 'update_all_records') );
      add_action( 'pdbmps-update_status_fields', array($this, 'write_payment_status'), 50, 2 );
      add_action( 'pdbmps-write_payment_status', array($this, 'write_payment_status') );
    } else {
      \pdbmps\fields\status_field::deactivate_field( payment_status_field::status_field_name );
      \pdbmps\fields\status_field::deactivate_field( next_due_date::status_field_name );
      \pdbmps\fields\status_field::deactivate_field( last_payment_date::status_field_name );
    }
  }

  /**
   * updates the status fields when the record is updated
   * 
   * @param array $post the posted data
   */
  public function update_status_fields( $post )
  {
    if ( ! $this->is_prepayment_submission( $post ) ) {
      $this->write_payment_status( $post['id'] );
    }
  }
  
  /**
   * tells if the current submission is a pre-payment submission
   * 
   * this is a submission that is not a confirmed payment, but is made when the 
   * user is first taken to the payment portal
   * 
   * @param array $post the posted data
   * 
   * @return bool true if the submission is a pre payment submission
   */
  public function is_prepayment_submission( $post)
  {
    /**
     * @filter pdbmps-pre_payment_submission
     * 
     * @param bool true if the submission is a pre-payment submission
     * @param array the posted data
     * 
     * @return bool true if the submission represents a pre-payment submission
     */
    return apply_filters( 'pdbmps-pre_payment_submission', false, $post );
  }

  /**
   * writes the status string into the status field
   * 
   * @param int $record_id the user's PDB record id
   * @param string  $initial_status an initial status to use
   */
  public function write_payment_status( $record_id, $initial_status = '' )
  {
    $user_status = new user_status( $record_id );
    
    /*
     * If the current status is empty, the record has never recorded a payment. 
     * The initial status value will be used instead.
     */
    $current_status = $this->payment_status($record_id);
    $initial_status = empty( $initial_status ) ? apply_filters('pdbmps-initial_status', $initial_status ) : $initial_status;
    $current_status = empty( $current_status ) ? $initial_status : $current_status;
    
    if ( $user_status->check_for_status_change( $current_status ) ) {
      $this->payment_status_field->update( $user_status->status(), $record_id );
    }

    // update the cached due date value if it is stale
    $due_date = wp_cache_get( $this->next_due_date_status_field->status_field_name(), 'status_fields' );
    if ( $due_date === false ) {
      $status_info = $user_status->user_status_info();
      $this->next_due_date_status_field->update( $status_info['next_due_date'], $record_id );
      wp_cache_set( $this->next_due_date_status_field->status_field_name(), $status_info['next_due_date'], 'status_fields' );
    }
  }
  
  /**
   * supplies the stored payment status for a record
   * 
   * @global \wpdb $wpdb
   * @param int $record_id
   * @return string the status value
   */
  private function payment_status( $record_id )
  {
    global $wpdb;
    $current_status = $wpdb->get_var( $wpdb->prepare( 'SELECT `'. $this->payment_status_field->status_field_name() . '` FROM ' . \Participants_Db::$participants_table . ' WHERE `id` = %s', $record_id ) );
    return $this->payment_status_field->value_from_label( $current_status );
  }

  /**
   * updates the status of all Participants Database records
   * 
   * this is called on a daily WP cron
   */
  public function update_all_records()
  {
    $record_list = update_all::start();
    do_action( 'pdbmps-all_record_update', $record_list );
  }

  /**
   * registers the member status events
   * 
   * @param array $events
   * @return array as $tag => $title
   */
  public function register_events( $events )
  {
    global $PDb_Member_Payments;

    $status_change_title_stem = __( 'Member Payments: Status Change to: %s', 'pdb_member_payments' );

    foreach ( payment_status_field::status_list() as $status ) {
      $events['pdbmps-status_change_to_' . $status] = sprintf( $status_change_title_stem, $PDb_Member_Payments->status_label_string( $status ) );
    }
    return $events;
  }

}
