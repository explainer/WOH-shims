<?php

/**
 * handles the PDT response from PayPal
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPLv3
 * @version    0.2
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\paypal_pdt;

class response {

  /**
   * @var string  holds the raw response string
   */
  private $response_data;

  /**
   * @param array $response the raw response from PayPal
   */
  public function __construct( $response )
  {
    /**
     * @filter pdbmps_pdt_response_data
     * 
     * provides access to the response data
     */
    add_filter( 'pdbmps-pdt_response_data', array( $this, 'data' ) );
    
    $this->parse_response( $response );
    if (WP_DEBUG) error_log(__METHOD__.' PDT response from PayPal: '.print_r($this->response_data,1));
    /**
     * @action pdb-member_payments_pdt_response
     * @param array the complete PDT response set
     * 
     * triggered when the PDT response comes back from PayPal
     */
    do_action('pdb-member_payments_pdt_response', $this->response_data);
  }
  
  /**
   * provides a complete set of response data
   * 
   * @return array associative array of response data
   */
  public function data()
  {
    return $this->response_data;
  }

  /**
   * provides a specific data field from the response
   * 
   * @param string $name of the data field
   * 
   * @return string|bool value of the field, bool false if it not found
   */
  public function __get( $name )
  {
    return isset( $this->response_data[$name] ) ? $this->response_data[$name] : false;
  }

  /**
   * parses out the response string into an array
   * 
   * @param string $response
   */
  private function parse_response( $response )
  {
    $lines = explode( "\n", $response );
    $keyarray = array('status' => stripos( $lines[0], 'success' ) !== false ? 'success' : 'failure' );
    
    if ( $keyarray['status'] === 'success' ) {
    
      for ( $i = 1; $i < count( $lines ); $i++ ) {
        if ( ! empty($lines[$i]) ) {
          list( $key, $val ) = explode( "=", $lines[$i] );
          $keyarray[urldecode( $key )] = urldecode( $val );
        }
      }
      
    } else {
      $keyarray = false;
    }
    $this->response_data = $keyarray;
  }

}
