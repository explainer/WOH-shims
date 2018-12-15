<?php

/**
 * receives the IPM response
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPLv3
 * @version    0.3
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\transaction\paypal_ipn;

class listener {
  /**
   * @var object|bool the received data object or bool false
   */
  private $response = false;
  
  /**
   * provides the response object
   * 
   * @return \pdbmps\paypal_ipn\response|bool bool false if the response is not verified
   */
  public static function ipn_response()
  {
    $listener = new self();
    return $listener->response && $listener->response->is_verified() ? $listener->response : false;
  }


  /**
   * 
   */
  public function __construct()
  {
    $this->check_request();
  }

  /**
   * checks for the presence of the IPN query var
   * 
   * when the payment form is submitted, the "notify_url" value sent to PayPal is 
   * appended with a coded query var so that when the IPN is posted back to the 
   * site, it can be verified by checking for the query var, which will be unique 
   * to the site
   */
	private function check_request() 
  {
     //error_log(__METHOD__.' checking the request: '. 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . "$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]");
//    error_log(__METHOD__.' IPN post: '.print_r($_POST,1));
    /**
     * Check that the query var is set and is the correct value.
     */
		if ( get_query_var( $this->query_var_key(), false ) !== false ){
      $this->response = new response( get_query_var( $this->query_var_key() ) );
    }
	}
  
  /**
   * supplies the query var name
   * 
   * @return string
   */
  private function query_var_key()
  {
    return apply_filters( 'pdb-member_payments_query_var', 'pdb-member_payments' );
  }
}
