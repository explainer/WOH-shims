<?php

/**
 * sets up the member payment form functionality
 * 
 * a payment form is a form that won't accept a submission unless it matches a database entry
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPL3
 * @version    0.2
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps;

class Payment_Form {

  /**
   * @var array matching fields to use
   */
  private $match_fields = array();

  /**
   * 
   */
  public function __construct()
  {
    add_action( 'plugins_loaded', array($this, 'init') );
    add_filter( 'pdb_member_payments_record_match_id', array($this, 'find_record') );
    add_action( 'pdb-member_payments_before_process_form', array($this, 'check_submission'), 10, 3 );
    add_filter( 'pdb-validation_error_messages', array($this, 'set_not_found_message') );
    
    add_filter( 'pdb-error_css', array($this, 'error_css') );

    /**
     * applies the member payments setting
     */
    add_filter( 'pdb_member_payments_member_payment', function () {
      return '1';
    } );
  }

  /**
   * initializes the plugin
   */
  public function init()
  {
    $this->set_match_fields();
  }

  /**
   * checks the submission for a match, shows error message if not
   * 
   * @param bool $enable  if true, process the form
   * @param string $id the current record ID 
   * @param array $post the sanitized post array
   * 
   */
  public function check_submission( $enable, $id, $post )
  {
    if ( $this->check_form( $post ) && $id === false ) {
      \Participants_Db::$validation_errors->add_error( reset( $this->match_fields ), 'not_found' );
    }
  }

  /**
   * alters the error CSS to hightlight the required fields
   * 
   * @param string $CSS
   * @return string CSS
   */
  public function error_css( $CSS )
  {
    foreach( \Participants_Db::$validation_errors->errors as $error ) {
      if ( $error->slug === 'not_found' ) {  
        $add_selector = array();
        foreach ( $this->match_fields as $field ) {
          $add_selector[] = sprintf( '[class*="pdb-"] [name=%s]', $field );
        }
        $add_selector = '], ' . implode( ',', $add_selector ) . '{';
        $CSS = str_replace( ']{', $add_selector, $CSS );
      }
    }
    return $CSS;
  }

  /**
   * finds a matching record based on the value of two fields
   * 
   * @param bool $match incoming match status
   * 
   * @return int|bool record id if match, bool false otherwise
   */
  public function find_record( $match )
  {
    $post = filter_input_array( INPUT_POST, FILTER_SANITIZE_STRING );
    if ( !$this->check_form( $post ) )
      return $match;
    if ( isset( $post['cmd'] ) && $post['cmd'] == '_s-xclick' ) {
      $match = false;
      $postvalues = array();
      foreach ( $this->match_fields as $field ) {
        $postvalues[] = isset( $post[$field] ) ? $post[$field] : '';
      }
      global $wpdb;
      $result = $wpdb->get_results( $wpdb->prepare( 'SELECT `id` FROM ' . \Participants_Db::$participants_table . ' WHERE ' . $this->match_where( $this->match_fields ), $postvalues ) );
      
      if ( WP_DEBUG ) assert( count( $result ) === 1, ' record query resulted in ' . (count( $result ) > 1 ? 'too many' : 'no' ) . ' matches with query: ' . $wpdb->last_query . ' Assertion ' );

      $match = empty( $result ) || count( $result ) > 1 ? false : current( $result )->id;
    }
    return $match;
  }

  /**
   * sets up the record not found error message
   * 
   * @param array $messages the error message asscoiative array
   * 
   * @return array the amended array
   */
  public function set_not_found_message( $messages )
  {
    $messages['not_found'] = apply_filters( 'pdb-member_payments_not_found_message', 'Your record could not be found with the information supplied.' );
    return $messages;
  }

  /**
   * builds a where statement from a list of fields
   * 
   * @param array $field_list
   * @return string where statement
   */
  private function match_where( $field_list )
  {
    $where = array();
    foreach ( $field_list as $field ) {
      $where[] = '`' . $field . '` = "%s"';
    }
    return implode( ' AND ', $where );
  }

  /**
   * sets up the match fields property
   */
  private function set_match_fields()
  {
    $this->match_fields = apply_filters( 'pdb-member_payments_match_fields', $this->match_fields );
  }

  /**
   * checks for the payment form value
   * 
   * @param array $poat data
   * @return bool true if the submission os for a member payment form
   */
  private function check_form( $post )
  {
    if ( isset( $post['submit_action'] ) && $post['submit_action'] == 'member-payment' ) {
      return true;
    }
    return false;
  }

}
