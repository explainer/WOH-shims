<?php

/**
 * handles a paypal html button transaction
 * 
 * this class is inteneded to be instantiated on a page load where it checks for 
 * a tx value in the get string. It sends the authentication response to PayPal, 
 * then collects the returned data. The client class can then get information about 
 * the transaction
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPLv3
 * @version    0.1
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\transaction\paypal_pdt;

class process {
  
  /**
   * @var object holds the transaction object
   * 
   * this object is instantiated after getting a response from PayPal and provides 
   * methods for checking the status of the transaction and getting specific data
   */
  public $transaction;
  
  /**
   * @var string the merchant token
   */
  private $token;
  
  /**
   * instantiates the transaction negotiator
   * 
   * @param atring $token the merchant token
   */
  public function __construct( $token )
  {
    $this->token = $token;
    $this->process();
  }
  
  /**
   * supplies the response data from PayPal
   * 
   * @param string  $token
   * 
   * @return array the response data
   */
  public static function pdt ( $token )
  {
    $pdt = new self( $token );
    return $pdt->transaction->response->data();
  }
  
  /**
   * checks the transaction string and inititates the response
   */
  private function process()
  {
    $transaction_id = filter_input(INPUT_GET, 'tx', FILTER_SANITIZE_STRING );
    $this->transaction = new transaction( $transaction_id, $this->token );
  }
}
