<?php

namespace pdbmps\shortcodes;

/*
 * prints a signup form
 * provides user feedback
 * emails a receipt and a notification
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdeign@xnau.com>
 * @copyright  2015 xnau webdesign
 * @license    GPL2
 * @version    1.6
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    xnau_FormElement class, Shortcode class
 */
if ( !defined( 'ABSPATH' ) )
  die;

class PDb_Member_Payment extends \PDb_Shortcode {

  /**
   *
   * @var bool holds the submission status: false if the form has not been submitted
   */
  var $submitted = false;

  /**
   *
   * @var string the user's email address
   */
  var $recipient;

  /**
   * @var bool reciept email sent status
   */
  var $send_reciept;

  /**
   *
   * @var array holds the notify recipient emails
   */
  public $notify_recipients;

  /**
   *
   * @var string holds the current email body
   */
  var $current_body;

  /**
   *
   * @var string header added to receipts and notifications
   */
  private $email_header;

  /**
   * @var \pdbmps\offline_payment_template holds the offline payments template methods
   */
  public $offline_payments;

  /**
   * @var \pdbmps\paypal_payment_template holds the paypal payments template methods
   */
  public $paypal_payment;

  /**
   * instantiates the member payment form object
   * 
   * @global \pdbmps\Plugin $PDb_Member_Payments
   *
   * @param array $shortcode_atts   this array supplies the display parameters for the instance
   *
   */
  public function __construct( $shortcode_atts )
  {
    wp_enqueue_script( 'pdb-member-payments' );
    // define shortcode-specific attributes to use
    $shortcode_defaults = array(
        'module' => 'member-payment',
        'submit_button' => \Participants_Db::plugin_setting( 'signup_button_text' ),
        'edit_record_page' => \Participants_Db::plugin_setting( 'registration_page' ),
        'fields' => implode( ',', apply_filters( 'pdb-member_payments_match_fields', array() ) ),
    );

    $this->offline_payments = new offline_payment_template();
    $this->paypal_payment = new paypal_payment_template();

    /*
     * status values: normal (signup form submission) or multipage
     */
    $form_status = $this->get_form_status();

    /*
     * get the record ID from the last submission or current multiform
     */
    $this->participant_id = \Participants_Db::$session->get( 'pdbid' );
    /*
     * if we've got a record ID it means we're coming back from a successful submission
     */
    if ( $this->participant_id !== false ) {

      /*
       * if we arrive here, the form has been submitted and is complete or is a multipage 
       * form and we've come back to the signup shortcode before the form was completed: 
       * in which case we show the saved values from the record
       */
      $this->participant_values = \Participants_Db::get_participant( $this->participant_id );

      if ( $this->participant_values && ($form_status === 'normal' || ( strpos( $shortcode_atts['module'], 'thanks' ) !== false && \Participants_Db::is_multipage_form())) ) {
        /*
         * the submission (single or multi-page) is successful, set the submitted flag
         */
        $this->submitted = true;
      }
      $shortcode_atts['id'] = $this->participant_id;
    }
    // set up the shortcode attributes
    if ( array_key_exists( 'button_html', $shortcode_atts ) ) {
      add_filter( 'pdb-member_payments_button_html', function() use ($shortcode_atts) {
        return $shortcode_atts['button_html'];
      } );
    }
    global $post;
    $this->hidden_fields['cancel_return'] = get_permalink( $post );
    $this->hidden_fields['return'] = get_permalink( $post );
    if ( array_key_exists( 'return_url', $shortcode_atts ) ) {
      $this->hidden_fields['return'] = $shortcode_atts['return_url'];
    }
    if ( array_key_exists( 'cancel_return', $shortcode_atts ) ) {
      $this->hidden_fields['cancel_return'] = $shortcode_atts['cancel_return'];
    }

    // add the payment method field
    $this->hidden_fields[\pdbmps\fields\last_payment_type::status_field_name] = ''; // this will be filled in when the form is submitted
    // run the parent class initialization to set up the $shortcode_atts property
    parent::__construct( $shortcode_atts, $shortcode_defaults );

    // set up the member_payment form email preferences
    $this->_set_email_prefs();

    // set the action URI for the form
    $this->_set_submission_page();

    // set up the template iteration object
    $this->_setup_iteration();

    if ( $this->submitted ) {

      global $PDb_Member_Payments;

      if ( $this->payment_was_made() ) {
        
        // get the latest payemnt status values
        $user_status_info = $PDb_Member_Payments->user_status_info( $this->participant_values['id'] );
        /*
         * filter provides access to the freshly-stored record and the email and 
         * thanks message properties so user feedback can be altered.
         * 
         * filter: pdbmps-before_member_payment_thanks
         */
        if ( has_action( 'pdbmps-before_member_payment_thanks' ) ) {

          $member_payment_feedback_props = array('payment_module', 'recipient', 'receipt_subject', 'receipt_body', 'notify_recipients', 'notify_subject', 'notify_body', 'thanks_message', 'participant_values');
          $member_payment_feedback = new stdClass();
          foreach ( $member_payment_feedback_props as $prop ) {
            $member_payment_feedback->$prop = &$this->$prop;
          }
          $member_payment_feedback->user_payment_status = $user_status_info;

          do_action( 'pdbmps-before_member_payment_thanks', $member_payment_feedback, $this->get_form_status() );
        }
        
        $member_data = array_merge( $this->participant_values, $PDb_Member_Payments->transaction_data(), $user_status_info );
        
        $module = $this->payment_module();
        
        /**
         * @action pdbmps-{$payment_module}_payment_return
         * 
         * $payment_module could be: signup, member, profile, member_offline
         * 
         * also "cancel" but that happens below
         * 
         * @param array participant data
         */
        do_action( 'pdbmps-' . ( $module !== 'offline' ? $module : $module . '_offline' ) . '_payment_return', apply_filters( 'pdbmps-template_data', $member_data ) );
        
        if ( $module === 'offline') {
          $this->module = $this->module . '-thanks';
          /**
           * @action pdbmps-offline_payment_return
           * 
           * triggers a generic offline payment action
           * 
           * @param array participant data
           */
          do_action( 'pdbmps-offline_payment_return', apply_filters( 'pdbmps-template_data', $member_data ) );
        }
        

        $this->_send_email();
        
        // set up the thanks template
        $this->set_template('default');
      }
      $this->_clear_multipage_session();
      $this->_clear_captcha_session();
    }

    if ( $this->submitted && !$this->payment_was_made() ) {
      /**
       * @action pdbmps-cancel_payment_return
       * 
       * @param array participants data
       */
      do_action( 'pdbmps-cancel_payment_return', apply_filters( 'pdbmps-template_data', $this->participant_values ) );
    }

    // print the shortcode output
    $this->_print_from_template();

    $this->_clear_payment_session();
  }

  /**
   * prints a member payment form called by a shortcode
   *
   * this function is called statically to instantiate the member_payment object,
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
   * provides the current user's payment status
   * 
   * obviously, this can only be used by the "thanks" template: the user's identity 
   * isn't known before that
   * 
   * @return string|bool the user's payment status or bool false if not available 
   */
  public function user_payment_status()
  {
    if ( ! isset( $this->participant_values['id'] ) ) {
      return false;
    }
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

    $where = 'WHERE ' . $groups . ' AND field.form_element NOT IN ("placeholder", "hidden")';

    $sql = '
      SELECT field.name
      FROM ' . \Participants_Db::$fields_table . ' field
      JOIN ' . \Participants_Db::$groups_table . ' fieldgroup ON field.group = fieldgroup.name 
      ' . $where . ' ORDER BY fieldgroup.order, field.order ASC';

    $this->display_columns = $wpdb->get_col( $sql );
  }

  /**
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
    // replace it with the submitted value if provided, escaping the input
    $value = isset( $_POST[$field->name] ) ? $this->_esc_submitted_value( $_POST[$field->name] ) : $value;

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
      $dynamic_value = $field->is_dynamic_hidden_field() ? $this->get_dynamic_value( $field->default_value() ) : $field->default_value();
      $value = $this->_empty( $record_value ) ? $dynamic_value : $record_value;
      /*
       * add to the display columns if not already present so it will be processed 
       * in the form submission
       */
      $this->display_columns += array($field->name());
    }
    $field->set_value( $value );
  }

  /**
   * includes the shortcode template
   */
  protected function _include_template()
  {
    include $this->template;
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
    foreach ( \Participants_Db::$fields as $field ) {
      /* @var $field \PDb_Form_Field_Def */
      if ( $field->is_hidden_field() && $field->signup ) {
        $hidden_field = new \PDb_Field_Item( $field );
        $this->_set_field_value( $hidden_field );
        $this->hidden_fields[$hidden_field->name()] = $hidden_field->value();
      }
    }
  }

  /**
   * sets the form submission page
   * 
   * if the "action" attribute is not set in the shortcode, use the "thanks page" 
   * setting if set
   */
  protected function _set_submission_page()
  {

    $form_status = $this->get_form_status();
    $this->submission_page = false;
    /*
     * check for the "action" attribute
     */
    if ( !empty( $this->shortcode_atts['action'] ) ) {
      $this->submission_page = \Participants_Db::find_permalink( $this->shortcode_atts['action'] );
    }
    /*
     * it's not set in the global settings, or is set to "same page", use the current 
     * page as the submission page
     */
    if ( $this->submission_page === false ) {
      // the signup thanks page is not set up, so we submit to the page the form is on
      $this->submission_page = $_SERVER['REQUEST_URI'];
    }
    $this->set_form_status( $form_status );
  }

  /**
   * prints a signup form top
   * 
   * @param array array of hidden fields supplied in the template
   */
  public function print_form_head( $hidden = '' )
  {
    echo $this->_print_form_head( $hidden );
  }

  /**
   * prints the submit button
   *
   * @param string $class a classname for the submit button, defaults to 'button-primary'
   * @param string $button_value submit button text
   * 
   */
  public function print_submit_button( $class = 'button-primary', $button_value = false )
  {

    $button_value = $button_value ? $button_value : $this->shortcode_atts['submit_button'];

    \PDb_FormElement::print_element( array(
        'type' => 'submit',
        'value' => $button_value,
        'name' => 'submit_button',
        'class' => $class . ' pdb-submit',
        'module' => $this->module,
    ) );
  }

  /**
   * prints a private link retrieval link
   * 
   * @param string $linktext
   */
  public function print_retrieve_link( $linktext = '', $open_tag = '<span class="pdb-retrieve-link">', $close_tag = '</span>' )
  {
    
  }
  
  /**
   * name of the current payment module
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
    global $PDb_Member_Payments;
    $option = $this->payment_module() === 'member' ? 'member_payment_cancel_return_messsage' : 'signup_payment_cancel_return_messsage';
    return $this->get_message( empty( $template ) ? $PDb_Member_Payments->plugin_option( $option ) : $template  );
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
   * sets up the signup form email preferences
   */
  private function _set_email_prefs()
  {
    $this->notify_recipients = \Participants_Db::plugin_setting( 'email_signup_notify_addresses' );
    $this->notify_subject = \Participants_Db::plugin_setting( $this->payment_module() === 'signup' ? 'email_signup_notify_subject' : 'record_update_email_subject'  );
    $this->notify_body = \Participants_Db::plugin_setting( $this->payment_module() === 'signup' ? 'email_signup_notify_body' : 'record_update_email_body'  );
    $this->receipt_subject = \Participants_Db::plugin_setting( 'signup_receipt_email_subject' );
    $this->receipt_body = \Participants_Db::plugin_setting( 'signup_receipt_email_body' );
    $this->email_header = \Participants_Db::$email_headers;
    $this->recipient = @$this->participant_values[\Participants_Db::plugin_setting( 'primary_email_address_field' )];
  }

  /**
   * tells if the signup receipt email is enabled
   * 
   * @filter pdbmps-send_signup_receipt
   * @param bool the PDB setting
   * @param string the payment module used
   * @return bool true if the PDB signup receipt email should be sent
   * 
   * @return bool true if enabled
   */
  public function maybe_send_receipt()
  {
    return (bool) apply_filters( 'pdbmps-send_signup_receipt', \Participants_Db::plugin_setting_is_true( 'send_signup_receipt_email' ) && $this->payment_module() === 'signup', $this->payment_module() );
  }

  /**
   * tells if the signup receipt email is enabled
   * 
   * @filter pdbmps-send_payment_notifications
   * @param bool the PDB setting
   * @param string the payment module used
   * @return bool true if the PDB notification email should be sent
   * 
   * @return bool true if enabled
   */
  public function maybe_send_notification()
  {
    $setting = $this->payment_module() === 'signup' ? 'send_signup_notify_email' : 'send_record_update_notify_email';
    return (bool) apply_filters( 'pdbmps-send_payment_notifications', \Participants_Db::plugin_setting_is_true( $setting ), $this->payment_module() );
  }

  /**
   * sends the notification and receipt emails
   * 
   * this handles both signups and updates using multi-page forms
   *
   */
  private function _send_email()
  {
    // this is a normal signup form
    if ( $this->maybe_send_notification() ) {
      $this->_do_notify();
    }
    if ( $this->maybe_send_receipt() ) {
      $this->_do_receipt();
    }
  }

  /**
   * sends a user receipt email
   */
  private function _do_receipt()
  {

    if ( filter_var( $this->recipient, FILTER_VALIDATE_EMAIL ) === false ) {
      error_log( \Participants_Db::$plugin_title . ': no valid email address was found for the user receipt email, mail could not be sent.' );
      return NULL;
    }

    /**
     * filter
     * 
     * pdb-receipt_email_template 
     * pdb-receipt_email_subject
     * 
     * @param string email template
     * @param array of current record values
     * 
     * @return string template
     */
    \PDb_Template_Email::send( array(
        'to' => $this->recipient,
        'subject' => \Participants_Db::apply_filters( 'receipt_email_subject', $this->receipt_subject, $this->participant_values ),
        'template' => \Participants_Db::apply_filters( 'receipt_email_template', $this->receipt_body, $this->participant_values ),
        'context' => __METHOD__,
            ), $this->participant_values );
  }

  /**
   * sends a new signup notification email to the admin
   */
  private function _do_notify()
  {

    \PDb_Template_Email::send( array(
        'to' => $this->notify_recipients,
        'subject' => $this->notify_subject,
        'template' => $this->notify_body,
        'context' => __METHOD__,
            ), $this->participant_values );
  }

  /**
   * set the PHPMailer AltBody property with the text body of the email
   *
   * @param object $phpmailer an object of type PHPMailer
   * @return null
   */
  public function set_alt_body( &$phpmailer )
  {

    if ( is_object( $phpmailer ) )
      $phpmailer->AltBody = $this->_make_text_body( $this->current_body );
  }

  /**
   * strips the HTML out of an HTML email message body to provide the text body
   *
   * this is a fairly crude conversion here. I should include some kind of library
   * to do this properly.
   *
   * @param string $HTML the HTML body of the email
   * @return string
   */
  private function _make_text_body( $HTML )
  {

    return strip_tags( preg_replace( '#(</(p|h1|h2|h3|h4|h5|h6|div|tr|li) *>)#i', "\r", $HTML ) );
  }

  /**
   * changes the readonly status of fields used in the retrieve form
   * 
   * @param $field a PDb_Field_Item object
   */
  public function allow_readonly_fields_in_form( $field )
  {
    // if ($field->group !== 'internal') return $field;
    $field->readonly = 0;
    return $field;
  }

  /**
   * clears the multipage form session values
   */
  function _clear_multipage_session()
  {
    foreach ( array('pdbid', 'form_status', 'previous_multipage') as $value ) {
      \Participants_Db::$session->clear( $value );
    }
  }

  /**
   * clears the multipage form session values
   */
  function _clear_captcha_session()
  {
    foreach ( array('captcha_vars', 'captcha_result') as $value ) {
      \Participants_Db::$session->clear( $value );
    }
  }

  /**
   * clears the multipage form session values
   */
  function _clear_payment_session()
  {
    foreach ( array('payment_module') as $value ) {
      \Participants_Db::$session->clear( $value );
    }
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
                WHERE f.form_element <> "hidden" ' . ( $public_only ? 'AND g.display = "1"' : '' ) . ' ORDER BY ' . $orderby;


      $result = $wpdb->get_results( $sql, ARRAY_N );

      foreach ( $result as $group ) {
        $groups[] = current( $group );
      }
    }

    $this->display_groups = $groups;
  }

}
