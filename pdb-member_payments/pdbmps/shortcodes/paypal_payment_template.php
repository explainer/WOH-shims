<?php

/**
 * handles an paypal payment template functions
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

namespace pdbmps\shortcodes;

class paypal_payment_template {

  /**
   * prints the offline payments label
   * 
   */
  public function print_label()
  {
    echo apply_filters( 'pdbmps-paypal_type_label', '' );
  }

  /**
   * prints the offline payments label
   * 
   */
  public function print_button_label()
  {
    echo apply_filters( 'pdbmps-payment_button_label', '' );
  }

  /**
   * prints the paypal button for a sugnup form
   * 
   * @global object $PDb_Member_Payments
   */
  public function print_signup_payment_button()
  {
    global $PDb_Member_Payments;
    $PDb_Member_Payments->print_paypal_button();
  }

  /**
   * 
   * @global object $PDb_Member_Payments
   */
  public function print_record_payment_button()
  {
    global $PDb_Member_Payments;
    $PDb_Member_Payments->print_record_payment_button();
  }

  /**
   * 
   * @global object $PDb_Member_Payments
   */
  public function print_member_payments_payment_button()
  {
    global $PDb_Member_Payments;
    $PDb_Member_Payments->print_paypal_button( false );
  }

  /**
   * supplies the thanks message
   * 
   * @return string
   */
  public function thanks_message()
  {
    return apply_filters( 'pdb-member_payments_paypal_payment_thanks_message', '' );
  }

}
