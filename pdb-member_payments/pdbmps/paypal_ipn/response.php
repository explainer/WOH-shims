<?php

/**
 * models the IPN response data
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPLv3
 * @version    0.4
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\paypal_ipn;

class response {

  /**
   * @var string the passed-in value
   */
  private $query_value;

  /**
   * @var array the sanitized post values
   */
  private $post;
  
  /**
   * @var bool  true if the response is verified
   */
  private $verified;

  /**
   * 
   * @param string $query_value the passed-in get var value from the paypal response
   */
  public function __construct( $query_value )
  {
    $this->query_value = $query_value;
    $this->sanitize_post();

    $this->verified = $this->_validateMessage();
    
    if ( PDB_DEBUG )
      error_log(__METHOD__.' IPN received: '.print_r(  $this->post,1 ) );
    
    if ( $this->is_verified() ) {
      do_action( 'pdbmps-ipn_response_data', $this->data() );
    }
  }
  
  /**
   * tells if the response is valid
   * 
   * @return bool true if verified
   */
  public function is_verified()
  {
    return $this->verified === true;
  }
  
  /**
   * provides the query var value
   * 
   * @return string
   */
  public function query_var_value()
  {
    return $this->query_value;
  }
  
  /**
   * provides the IPN data array combined with the participant record data
   * 
   * @return array
   */
  public function data()
  {
    $record = array();
    if ( isset( $this->post['custom'] ) && $participant = \Participants_Db::get_participant( $this->post['custom'] ) ) {
      $record = $participant;
    }
    return array_merge( $record, $this->post );
  }
  
  /**
   * provides a data value
   * 
   * @param string  $name of the field
   * @return string the value
   */
  public function __get( $name )
  {
    return isset( $this->post[$name] ) ? $this->post[$name] : false;
  }

  /**
   * validates the postback from PayPal
   */
  private function _validateMessage()
  {
    global $PDb_Member_Payments;
    
    // set the command value
    $_POST['cmd'] = "_notify-validate";

    /**
     * return the POST array to PayPal
     */
    $args = array(
        'body' => $_POST,
        'sslverify' => apply_filters( 'pdb-member_payments_sslverify', false ),
        'httpversion' => '1.1',
        'timeout' => 30,
    );

    // post the request
    $response = new post_check( wp_remote_post( $PDb_Member_Payments->paypal_url(), $args ) );
    
    return $response->is_verified();
  }

  /**
   * sanitizes the post array
   * 
   * @return null
   */
  private function sanitize_post()
  {
    $this->post = filter_input_array( INPUT_POST, FILTER_SANITIZE_STRING );
  }

}
