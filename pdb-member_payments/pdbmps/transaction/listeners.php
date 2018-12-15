<?php

/**
 * defines our payment portal listeners
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2017  xnau webdesign
 * @license    GPL3
 * @version    0.2
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\transaction;

class listeners {
  /**
   * sets up the listeners
   */
  public function __construct()
  {
    add_action( 'template_redirect', array($this, 'listen') );
  }
  
  /**
   * initiates the listener cycle
   */
  public function listen()
  {
    /*
     * we will add new portal listeners here
     */
    $this->pp_listeners();
  }

  /**
   * checks for a PayPal PDT or IPN transaction and logs it if available
   * 
   * @global \pdbmps\Plugin $PDb_Member_Payments
   */
  private function pp_listeners()
  {
    global $PDb_Member_Payments;
    // check for PDT
    if ( array_key_exists( 'tx', $_GET ) && $PDb_Member_Payments->pdt_is_configured() ) {
      $PDb_Member_Payments->set_transaction_data( paypal_pdt\process::pdt( $PDb_Member_Payments->pdt_token() ) );
      
      if ( $PDb_Member_Payments->transaction_data_is_set() && $id = \pdbmps\Plugin::get_record_id_from_return_code( $PDb_Member_Payments->last_tx_value('custom') ) ) {
        \pdbmps\payment_log\PayPal_Log::add_new_entry( $id, $PDb_Member_Payments->transaction_data() );
      }
    }
    // check for IPN
    if ( $ipn = paypal_ipn\listener::ipn_response() ) {
      $id = \pdbmps\Plugin::get_record_id_from_return_code( $ipn->custom );
      if ( $id ) {
        $PDb_Member_Payments->set_transaction_data( $ipn->data() );
        \pdbmps\payment_log\PayPal_Log::add_new_entry( $id, $PDb_Member_Payments->transaction_data() );
      }
    }
  }
}