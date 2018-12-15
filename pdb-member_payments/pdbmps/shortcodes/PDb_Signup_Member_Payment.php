<?php

/**
 * provides the functionality for the pdb_signup_member_payment shortcode
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

class PDb_Signup_Member_Payment extends \PDb_Signup {

  /**
   * @var array additional shortcode atts
   */
  private $additional_atts = array();

  /**
   * @var \pdbmps\offline_payment_template holds the offline payments template methods
   */
  public $offline_payments;

  /**
   * @var \pdbmps\paypal_payment_template holds the paypal payments template methods
   */
  public $paypal_payment;

  /**
   * @global \pdbmps\Plugin $PDb_Member_Payments
   * 
   */
  public function __construct( $shortcode_atts )
  {
    
    // use the standard module name for displaying form element values
    add_filter( 'pdb-before_display_form_element', array( $this, 'set_module' ), 10, 2 );
    
    add_filter('pdb-readonly_exempt_module', function ( $module ) {
      return 'signup-member-payment'; 
    } );

    $this->offline_payments = new offline_payment_template();
    $this->paypal_payment = new paypal_payment_template();

    wp_enqueue_script( 'pdb-member-payments' );

    $this->add_shortcode_atts( $shortcode_atts );

    if ( array_key_exists( 'button_html', $shortcode_atts ) ) {
      add_filter( 'pdb-member_payments_button_html', function() use ($shortcode_atts) {

        return preg_replace( '/(<\/?(?:form|br).*>)/', '', html_entity_decode( $shortcode_atts['button_html'] ) );
      } );
    }

    parent::__construct( $shortcode_atts );

    if ( $shortcode_atts['module'] === 'signup-member-payment' && $this->participant_id === false ) {
      $this->_clear_multipage_session();
      // override read-only in signup and link recovery forms
      add_action( 'pdb-before_field_added_to_iterator', array($this, 'allow_readonly_fields_in_form') );
    }

    global $PDb_Member_Payments;
    
    $module = '';

    if ( $this->participant_values && $this->payment_was_made() ) {

      // get the latest payemnt status values
      $user_status_info = $PDb_Member_Payments->user_status_info( $this->participant_values['id'] );
      $data = array_merge( $this->participant_values, $PDb_Member_Payments->transaction_data(), $user_status_info );
      $module = $this->payment_module();
      /**
       * @action pdbmps-{$payment_module}_payment_return
       * 
       * $payment_module could be: signup, member, profile, offline
       * 
       * also "cancel" but that happens below
       * 
       * @param array participants data
       */
      do_action( 'pdbmps-' . ( $module !== 'offline' ? : $module . '_offline' ) . '_payment_return', apply_filters( 'pdbmps-template_data', $data ) );
    }
    if ( $module === 'offline') {
      /**
       * @action pdbmps-offline_payment_return
       * 
       * triggers a generic offline payment action
       * 
       * @param array participant data
       */
      do_action( 'pdbmps-offline_payment_return', apply_filters( 'pdbmps-template_data', $data ) );
    }
  }

  /**
   * tells if the last payment was completed
   * 
   * @return string slug of the current payment module
   */
  public function payment_module()
  {
    global $PDb_Member_Payments;
    $payment = $PDb_Member_Payments->payment_module();
    return $payment;
  }

  /**
   * tells if the last payment was completed
   * 
   * @return bool
   */
  public function payment_was_made()
  {
    global $PDb_Member_Payments;
    return $PDb_Member_Payments->payment_was_made();
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
      $field->module = 'signup';
    }
    return $html;
  }

  /**
   * prints a thank you note
   * 
   * @param string $template an optional override template to use
   * @return string
   */
  protected function get_thanks_message( $template = '' )
  {
    global $PDb_Member_Payments;
    return $this->get_message( $PDb_Member_Payments->thanks_message_template( $template ) );
  }

  /**
   * prints a thank you note
   * 
   * @param string $template an optional override template to use
   * @return string
   */
  protected function get_non_payment_message( $template = '' )
  {
    return $this->get_message( empty( $template ) ? $this->get_message_setting( $this->payment_module() === 'member' ? 'member_payment_cancel_return_messsage' : 'signup_payment_cancel_return_messsage'  ) : $template  );
  }

  /**
   * prints a user feedback message
   * 
   * @param string $template an optional override template to use
   * @return string
   */
  private function get_message( $template )
  {
    // add in the PDT data so it can be accessed in the template
    $status_info = $this->participant_id ? \pdbmps\payment_status\user_status::info( $this->participant_id ) : array();
    $data = array_merge( (array) $this->participant_values, apply_filters( 'pdbmps-pdt_response_data', array() ), $status_info );

    // add the "record_link" tag
    if ( isset( $data['private_id'] ) ) {
      $data['record_link'] = \Participants_Db::get_record_link( $data['private_id'] );
    }

    $this->output = empty( $this->participant_values ) ? '' : \PDb_Tag_Template::replaced_rich_text( $template, $data );
    unset( $_POST );
    return $this->output;
  }

  /**
   * sets up the submission page and shortcode atts
   */
  protected function _set_submission_page()
  {
    parent::_set_submission_page();
  }

  /**
   * sets up the hidden fields array
   * 
   * in this class, this simply adds all defined hidden fields
   * 
   * @return null
   */
  protected function _setup_hidden_fields()
  {
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
    foreach ( array('return_url', 'cancel_return', 'button_html') as $att ) {
      if ( array_key_exists( $att, $shortcode_atts ) ) {
        $this->additional_atts[$att] = $shortcode_atts[$att];
      }
    }
  }

  /**
   * prints a signup form called by a shortcode
   *
   * this function is called statically to instantiate the Signup object,
   * which captures the processed template output and returns it for display
   *
   * @param array $params parameters passed by the shortcode
   * @return string form HTML
   */
  public static function print_form( $params )
  {

    self::$instance = new self( $params );

    return self::$instance->output;
  }

  /**
   * determines if a group has fields to display in the module context
   *
   * @param string $group name of the group to check
   * @return bool
   */
  private function _has_group_fields( $group )
  {

    foreach ( $this->fields as $field ) {
      if ( $field->group == $group ) {
        if ( $field->signup > 0 ) {
          return true;
        }
      }
    }
    return false;
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

    $where = 'WHERE field.signup = 1 AND ' . $groups . ' AND field.form_element NOT IN ("placeholder", "hidden")';

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
    if ( in_array( $field->name(), array('id', 'private_id') ) ) {
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

  /**
   * sets up the display groups
   * 
   * first, attempts to get the list from the shortcode, then uses the defined as 
   * visible list from the database
   *
   * if the shortcode "groups" attribute is used, it overrides the gobal group 
   * visibility settings
   *
   * @global object $wpdb
   * @param  bool $public_only if true, include only public groups, if false, include all groups
   * @return null
   */
  protected function _set_display_groups( $public_only = true )
  {

    global $wpdb;
    $groups = array();
    if ( !empty( $this->shortcode_atts['fields'] ) ) {

      foreach ( $this->display_columns as $column ) {
        $column = $this->fields[$column];
        $groups[$column->group] = true;
      }

      $groups = array_keys( $groups );
    } elseif ( !empty( $this->shortcode_atts['groups'] ) ) {

      /*
       * process the shortcode groups attribute and get the list of groups defined
       */
      $group_list = array();
      $groups_attribute = explode( ',', str_replace( array(' '), '', $this->shortcode_atts['groups'] ) );
      foreach ( $groups_attribute as $item ) {
        if ( \Participants_Db::is_group( $item ) )
          $group_list[] = trim( $item );
      }
      if ( count( $group_list ) !== 0 ) {
        /*
         * get a list of all defined groups
         */
        $sql = 'SELECT g.name 
                FROM ' . \Participants_Db::$groups_table . ' g ORDER BY FIELD( g.name, "' . implode( '","', $group_list ) . '")';

        $result = $wpdb->get_results( $sql, ARRAY_N );
        foreach ( $result as $group ) {
          if ( in_array( current( $group ), $group_list ) || $public_only === false ) {
            $groups[] = current( $group );
          }
        }
      }
    }
    if ( count( $groups ) === 0 ) {

      $orderby = empty( $this->shortcode_atts['fields'] ) ? 'g.order ASC' : 'FIELD( g.name, "' . implode( '","', $groups ) . '")';

      $sql = 'SELECT DISTINCT g.name 
                FROM ' . \Participants_Db::$groups_table . ' g 
                JOIN ' . \Participants_Db::$fields_table . ' f ON f.group = g.name 
                WHERE f.signup = "1" ' . ( $public_only ? 'AND g.display = "1"' : '' ) . ' AND f.form_element <> "hidden" ORDER BY ' . $orderby;


      $result = $wpdb->get_results( $sql, ARRAY_N );

      foreach ( $result as $group ) {
        $groups[] = current( $group );
      }
    }

    $this->display_groups = $groups;
  }

}
