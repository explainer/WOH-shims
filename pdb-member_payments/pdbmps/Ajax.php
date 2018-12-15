<?php

/**
 * handles the AJAX form submission for both signup and record forms
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPLv3
 * @version    0.6
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps;

class Ajax {

  /**
   * @var int|bool the record id or bool false
   */
  private $id = false;

  /**
   * @var array the posted data
   */
  private $post_data;

  /**
   * sets up and executes the submission processing
   */
  public static function process()
  {
    $ajax = new self();
    $ajax->process_ajax_submission();
  }

  /**
   * processes the signup or record form AJAX submission
   */
  public function process_ajax_submission()
  {
    if ( !wp_verify_nonce( filter_input( INPUT_POST, 'nonce', FILTER_SANITIZE_STRING ), Plugin::ajax ) )
      die( 'nonce failed' );

    /*
     * route the $_POST data through a callback if defined
     * 
     * filter: pdb-before_submit_signup or pdb-before_submit_update
     */
    $shortcode = stripos( filter_input( INPUT_POST, 'submit_action', FILTER_SANITIZE_STRING ), 'signup' ) !== false ? 'signup' : 'update';
    $this->post_data = \Participants_Db::apply_filters( 'before_submit_' . $shortcode, $_POST );
    add_filter( 'pdb-post_action_override', function ( $action ) use ( $shortcode ) { 
      return $shortcode === 'signup' ? 'signup' : 'update'; 
    } );

    // add the payment mode value
    $this->post_data[fields\last_payment_type::status_field_name] = filter_input( INPUT_POST, fields\last_payment_type::status_field_name, FILTER_SANITIZE_STRING );

//    error_log(__METHOD__.' post data: '.print_r($this->post_data,1));
//    error_log(__METHOD__.' post: '.print_r($_POST,1));

    if ( array_key_exists( 'submit_action', $this->post_data ) ) {
      /*
       * this is needed because the shortcode-specific action is not noramlly triggered by an ajax call
       * 
       * this will be:
       *  pdb-shortcode_call_pdb_member_payment
       *  pdb-shortcode_call_pdb_signup_member_payment
       */
      do_action( 'pdb-shortcode_call_pdb_' . str_replace( '-', '_', $this->post_data['submit_action'] ), $this->post_data );
    }

    if ( !is_object( \Participants_Db::$validation_errors ) )
      \Participants_Db::$validation_errors = new \PDb_FormValidation();

    $this->get_record_id_from_post();
    $action = $this->write_mode();
    
    // if a member payment doesn't match a record, return as error
    if ( $action === 'error' ) {
      global $PDb_Member_Payments;
      \Participants_Db::$validation_errors->add_error( \Participants_Db::plugin_setting( 'unique_field', 'id' ), $PDb_Member_Payments->plugin_option('not_found_message', 'Member not found.' ) );
      wp_send_json( array(
        'registration_id' => false,
        'errorHTML' => \Participants_Db::$validation_errors->get_error_html(),
      ) );
    }

    // set up the submission columns
    $columns = false;
    if ( array_key_exists( 'pdb_data_keys', $this->post_data ) ) {
      $columns = \Participants_Db::get_data_key_columns( filter_input( INPUT_POST, 'pdb_data_keys', FILTER_SANITIZE_STRING ) );
    }

    /**
     * don't try to save the record if there are no columns of data #60
     * 
     */
    if ( count( $columns ) > 0 ) {

      /**
       * @action pdb-member_payments_validate_submission
       * 
       * @param bool $enable  if true, process the form
       * @param string $id the current record ID 
       * @param array $post the sanitized post array
       */
      do_action( 'pdb-member_payments_before_process_form', true, $this->id, $this->post_data );

      $participant_id = \Participants_Db::process_form( array_filter( $this->post_data, array($this, 'not_empty') ), $action, $this->id, $columns );

      if ( $participant_id ) {
        /*
         * hook: pdb-after_submit_signup
         */
        $wp_hook = \Participants_Db::$prefix . ( $action === 'insert' ? 'after_submit_signup' : 'after_submit_update' );
        do_action( $wp_hook, \Participants_Db::get_participant( $participant_id ) );

        $payment_module = $this->post_data['submit_action'] . '-' . $this->post_data['pdbmps_payment_type'];

        \Participants_Db::$session->set( 'pdbid', $participant_id );
        \Participants_Db::$session->set( 'previous_multipage', $this->post_data['shortcode_page'] );
        \Participants_Db::$session->set( 'payment_module', $payment_module );
        
        $this->id = $participant_id;
      }
    }

    do_action( 'pdbmps-set_last_payment_type', isset( $participant_id ) ? $participant_id : $this->id, $this->post_data[fields\last_payment_type::status_field_name] );

    // generate the response
    wp_send_json( array(
        'registration_id' => $this->id ? Plugin::pp_custom_value( $this->id ) : false, // if the record wasn't saved or updated, use the matched ID
        'errorHTML' => \Participants_Db::$validation_errors->get_error_html(),
    ) );
  }

  /**
   * checks for a valid submission value
   * 
   * @param string|int|array $value the saved value
   * @return bool true if there is something to save
   */
  public function not_empty( $value )
  {
    if ( is_array( $value ) ) {
      $value = implode( '', $value );
    }
    return strlen( trim( $value ) ) !== 0;
  }

  /**
   * sets the record ID if available
   * 
   */
  private function get_record_id_from_post()
  {
    $id = '';
    if ( isset( $this->post_data['id'] ) ) {
      $id = filter_var( $this->post_data['id'], FILTER_SANITIZE_NUMBER_INT );
    }
    $this->id = empty( $id ) ? false : $id;
  }

  /**
   * determines if the action is an insert or update
   * 
   * @return  string write mode
   */
  private function write_mode()
  {
    if ( $this->id ) {
      if ( \Participants_Db::get_participant( $this->id ) !== false ) {
        return 'update'; // this is a profile payment
      } else {
        return 'error'; // not a real ID
      }
    }
    /*
     * if we're overwriting a matched record, get the id of the matched record and make it an update
     */
    if ( $this->matching_records() && $id = $this->record_match_id() ) {
      $this->id = $id;
      return 'update'; // it is a member payment
    }
    return $this->matching_records() ? 'error' : 'insert';
  }

  /**
   * checks for a matching record
   * 
   * @retrun int|bool record ID or bool false if no match
   */
  private function record_match_id()
  {
    $match_field = \Participants_Db::plugin_setting( 'unique_field', 'id' );
    $record_match = false;
    if ( $match_field_value = filter_input( INPUT_POST, $match_field, FILTER_SANITIZE_STRING, array('flags' => FILTER_NULL_ON_FAILURE) ) ) {
      $record_match = $match_field_value !== '' && \Participants_Db::field_value_exists( $match_field_value, $match_field );
    }
    /**
     * @filter pdb_member_payments_record_match_id
     * 
     * @return matched record ID or bool false if no match
     */
    return apply_filters( 'pdb_member_payments_record_match_id', ( $record_match ? \Participants_Db::get_record_id_by_term( $match_field, $match_field_value ) : false ) );
  }

  /**
   * tells if record matching is in use
   * 
   * @return bool true if records need to be matched
   */
  private function matching_records()
  {
    return $this->post_data['submit_action'] === 'member-payment' && 
            ( \Participants_Db::plugin_setting( 'unique_email', '0' ) == 1 || 
            apply_filters( 'pdb_member_payments_member_payment', '0' ) == '1' );
  }

  /**
   * adds values to the template tag array
   * 
   * @param array $data the incoming data
   * @param string  $context the call contaxt identifier\
   * 
   * @return  array the data array
   */
  public function add_template_tags( $data, $context )
  {
//    error_log(__METHOD__.' context: '.$context.' data: '.print_r($data,1));
    return $data;
  }

}
