<?php

/**
 * models a particular member
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

namespace pdbmps;

class Member {
  /**
   * @var int the record id
   */
  private $id;
  /**
   * @vat array $data the member's data as $name => $value
   */
  private $data;
  /**
   * creates a member instance
   * 
   * @param int $id the record id
   */
  public function __construct( $id )
  {
    $this->id = $id;
    $this->data = \Participants_Db::get_participant($id);
  }
  
  /**
   * provides a data value
   * 
   * @param string $name of the data field
   * @return mixed the data value
   */
  public function __get( $name )
  {
    switch ( $name ) {
      case 'id':
        return $this->id;
      case 'status':
        if ( ! isset( $this->data['pdbmps_member_payment_status'] ) ) {
          $this->data['pdbmps_member_payment_status'] = apply_filters( 'pdbmps-initial_status', '' );
        }
        return $this->data['pdbmps_member_payment_status'];
      default:
        if ( isset( $this->data[$name] ) ) {
          return maybe_unserialize($this->data[$name]);
        }
    }
    return null;
  }
}
