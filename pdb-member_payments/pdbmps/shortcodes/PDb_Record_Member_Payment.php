<?php

/**
 * provides a the functionality for the pdb_record_member_payment shortcode
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

namespace pdbmps\shortcodes;

class PDb_Record_Member_Payment extends \PDb_Record {
  
  
  /**
   * @var array additional shortcode atts
   */
  private $additional_atts = array();

  /**
   * @var \pdbmps\offline_payment_template holds the offline payments template methods
   */
  public $offline_payments;

  /**
   * 
   * @global \pdbmps\Plugin $PDb_Member_Payments
   *
   * @param array $shortcode_atts   this array supplies the display parameters for the instance
   */
  public function __construct( $shortcode_atts )
  {
    
    wp_enqueue_script( 'pdb-member-payments' );
    
    // set up the offline payments functionality
    $this->offline_payments = new offline_payment_template();
    
    // use the standard module name for displaying form element values
    add_filter( 'pdb-before_display_form_element', array( $this, 'set_module' ), 10, 2 );
    
    $this->add_shortcode_atts($shortcode_atts);

    if ( array_key_exists( 'button_html', $shortcode_atts ) ) {
      add_filter( 'pdb-member_payments_button_html', function() use ($shortcode_atts) {
        return $shortcode_atts['button_html'];
      } );
    }
    
    parent::__construct( $shortcode_atts );

    global $PDb_Member_Payments;

    if ( $this->participant_values['id'] && $PDb_Member_Payments->payment_was_made() ) {

      // get the latest payemnt status values
      $user_status_info = $PDb_Member_Payments->user_status_info( $this->participant_values['id'] );
      $member_data = array_merge( $this->participant_values, $PDb_Member_Payments->transaction_data(), $user_status_info );
      $module = $this->payment_module();
      /**
       * @action pdbmps-{$payment_module}_payment_return
       * 
       * $payment_module could be: signup, member, profile
       * 
       * also "cancel" but that happens below
       * 
       * @param array participants data
       */
      do_action( 'pdbmps-' . ( $module !== 'offline' ? : $module . '_offline' ) . '_payment_return', apply_filters( 'pdbmps-template_data', $member_data ) );
      if ( $module === 'offline') {
        /**
         * @action pdbmps-offline_payment_return
         * 
         * triggers a generic offline payment action
         * 
         * @param array participant data
         */
        do_action( 'pdbmps-offline_payment_return', apply_filters( 'pdbmps-template_data', $member_data ) );
      }      
    }
  }
  
  /**
   * provides the current user's payment status
   * 
   * @return string|bool the user's payment status or bool false if not available 
   */
  public function user_payment_status()
  {
    global $PDb_Member_Payments;
    $user_status_info = $PDb_Member_Payments->user_status_info( $this->participant_values['id'] );
    return isset( $user_status_info['payment_status'] ) && ! empty( $user_status_info['payment_status'] ) ? $user_status_info['payment_status'] : false;
  }
  
  /**
   * provides the display title for the user's payment status
   * 
   * @return string
   */
  public function user_payment_status_title()
  {
    $status = $this->user_payment_status();
    $title = $status ? apply_filters( 'pdbmps-' . $status . '_label', '' ) : '';
    return is_array( $title ) ? $title['title'] : $title;
  }
  
  
  
  /**
   * sets the module value for display purposes
   * 
   * @param string $return the value display
   * @param PDb_Field_Item $field the field object
   * 
   * @return string HTML
   */
  public function set_module( $html, $field )
  {
    if ( $field->module === $this->module ) {
      $field->module = 'record';
    }
    return $html;
  }
  
  /**
   * provides the payment module string
   * 
   * @return string
   */
  protected function payment_module()
  {
    return 'profile';
  }

  /**
   * prints the form header and hidden fields
   */
  public function print_form_head() {

    $hidden = array(
        'action' => 'profile-member-payment',
        'id' => $this->participant_id,
        \Participants_Db::$record_query => $this->participant_values['private_id'],
    );

    $this->_print_form_head($hidden);
  }
  

  /**
   * sets up the hidden fields array
   * 
   * in this class, this simply adds all defined hidden fields
   * 
   * @return null
   */
  protected function _setup_hidden_fields() {
    foreach ( \Participants_Db::$fields as $field) {
      /* @var $field \PDb_Form_Field_Def */
      if ($field->is_hidden_field()) {
        $hidden_field = new \PDb_Field_Item( $field );
        $this->_set_field_value( $hidden_field );
        $this->hidden_fields[$hidden_field->name()] = $hidden_field->value();
      }
    }
    global $post;
    $this->hidden_fields['cancel_return'] = get_permalink( $post );
    if ( array_key_exists( 'return_url', $this->additional_atts ) ) {
      $this->hidden_fields['return'] = $this->additional_atts['return_url'];
    }
    if ( array_key_exists( 'cancel_return', $this->additional_atts ) ) {
      $this->hidden_fields['cancel_return'] = $this->additional_atts['cancel_return'];
    }
    
    // add the payment method field
    $this->hidden_fields[\pdbmps\fields\last_payment_type::status_field_name] = ''; // this will be filled in when the form is submitted
  }
  
  /**
   * adds special attributes to the shortcode atts
   */
  private function add_shortcode_atts( $shortcode_atts )
  {
    foreach( array( 'return_url', 'cancel_return', 'button_html' ) as $att ) {
      if ( array_key_exists( $att, $shortcode_atts ) ) {
        $this->additional_atts[$att] = $shortcode_atts[$att];
      } else {
        if ( strpos( $att, 'return' ) !== false ) {
          $this->additional_atts[$att] = filter_var( 'http' . ( isset($_SERVER['HTTPS'] ) ? 's' : '' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], FILTER_SANITIZE_URL );
        }
      }
    }
  }

  /**
   * prints a signup form called by a shortcode
   *
   * this function is called statically to instantiate the Signup object,
   * which captures the output and returns it for display
   *
   * @param array $params parameters passed by the shortcode
   * @return string form HTML
   */
  public static function print_form( $params )
  {

    $record = new self( $params );

    return $record->output;
  }

  /**
   * sets up the array of display columns
   * 
   * @global object $wpdb
   */
  protected function _set_shortcode_display_columns()
  {

    if ( empty( $this->display_groups ) ) {
      $this->_set_display_groups();
    }

    $groups = 'field.group IN ("' . implode( '","', $this->display_groups ) . '")';

    global $wpdb;

    $where = 'WHERE ' . $groups . ' AND field.form_element NOT IN ("captcha","placeholder","hidden")';

    $sql = '
      SELECT field.name
      FROM ' . \Participants_Db::$fields_table . ' field
      JOIN ' . \Participants_Db::$groups_table . ' fieldgroup ON field.group = fieldgroup.name 
      ' . $where . ' ORDER BY fieldgroup.order, field.order ASC';

    $this->display_columns = $wpdb->get_col( $sql );
  }

  /**
   * sets the field value; uses the default value if no stored value is present
   * 
   * as of version 1.5.5 we slightly changed how this works: formerly, the default 
   * value was only used in the record module if the "persistent" flag was set, now 
   * the default value is used anyway. Seems more intuitive to let the default value 
   * be used if it's set, and not require the persistent flag. The default value is 
   * always used in the signup module.
   *
   *
   * @param \PDb_Field_Item $field the current field object
   * @return string the value of the field
   */
  protected function _set_field_value( $field )
  {
    /*
     * get the value from the record; if it is empty, use the default value if the 
     * "persistent" flag is set.
     */
    $record_value = isset( $this->participant_values[$field->name()] ) ? $this->participant_values[$field->name()] : '';
    $value = $record_value;
    $default_value = $this->_empty( $field->default_value() ) ? '' : $field->default_value();
    // replace it with the submitted value if provided, escaping the input
    $value = isset( $_POST[$field->name()] ) ? $this->_esc_submitted_value( $_POST[$field->name()] ) : $value;

    /*
     * make sure id and private_id fields are read only
     */
    if ( in_array( $field->name, array('id', 'private_id') ) ) {
      $this->display_as_readonly( $field );
    }
    if ( $field->is_hidden_field() ) {
      /**
       * use the dynamic value if no value has been set
       * 
       * @version 1.6.2.6 only set this if the value is empty
       */
      $dynamic_value = \Participants_Db::is_dynamic_value( $field->default_value() ) ? $this->get_dynamic_value( $field->default_value() ) : $field->default_value();
      $value = $this->_empty( $record_value ) ? $dynamic_value : $record_value;
      /*
       * add to the display columns if not already present so it will be processed 
       * in the form submission
       */
      $this->display_columns += array($field->name);
    }
    $field->set_value( $value );
  }

}
