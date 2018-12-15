<?php

/**
 * manages logging of PayPal return data
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPLv3
 * @version    0.9
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\payment_log;

class PayPal_Log extends log_field {
  
  /**
   * @var string  the PP date format
   * 
   * @todo  we use this as a general date format for the logs...we should use a 
   *        global format instead
   */
  const date_format = 'H:i:s M j, Y T';
  
  /**
   * adds a new log entry
   * 
   * @param array $data log data as $name => $value
   * @param bool  $overwrite  if true, overwrite a log entry with a matching TXN ID, 
   *                          if false, a matching entry will not be logged to prevent 
   *                          duplicates
   */
  public function add_log_entry( $data, $overwrite = false )
  {
    $this->trans_id_fieldname = 'txn_id';
    $this->payment_portal = 'paypal';
    
    parent::add_log_entry( $data, $overwrite );
  }
  
  /**
   * provides the payment portal date format
   * 
   * @return string
   */
  public function date_format()
  {
    return self::date_format;
  }

}
