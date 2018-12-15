<?php

/**
 * defines the next due date status field
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPL3
 * @version    0.1
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\payment_status;

class next_due_date extends \pdbmps\fields\status_field {
  

  /**
   * @var string the name of the payment status field
   * 
   * this can be overriden by a filter
   */
  const status_field_name = 'pdbmps_next_due_date';
  
  /**
   * 
   */
  public function __construct()
  {
    parent::__construct( self::status_field_name, array(
        'title' => __( 'Next Payment Due Date', 'pdb-member_payments' ),
        'form_element' => 'date',
            )
    );
  }

  /**
   * displays the field's value
   * 
   * @param mixed $value
   * @param \PDb_Field_Item  $field
   * @return string
   */
  public function display_value( $value, $field )
  {
    if ( $field->name === $this->status_field_name() ) {
      $value = \PDb_Date_Display::get_date( parent::display_value( $value, $field ) );
    }
    return $value;
  }

  /**
   * provides the current status value
   * 
   * this is used to show the status label in a read-only context
   * 
   * @return string
   */
  protected function get_status_value()
  {
    $user_status = new user_status( $this->record_id );
    $info = $user_status->user_status_info();
    
    return $info['next_due_date'];
  }
}
