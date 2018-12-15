<?php

/**
 * manages the plugins shortcodes
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

class controller {

  /**
   * @var array the incoming shortcode attribute values
   */
  private $shortcode_atts;
  
  /**
   * @var string the current shortcode module
   */
  private $module = '';

  /**
   * 
   */
  public function __construct()
  {
    foreach( array( 
        'pdb_signup_member_payment', 
        'pdb_record_member_payment',
        'pdb_member_payment',
        'pdb_member_payment_thanks',
        'pdb_signup_member_payment_thanks',
        ) as $tag ) {
      add_shortcode( $tag, array($this, 'print_shortcode') );
    }
    
    add_action( 'pdb-template_select', array($this, 'set_template') );
    
    add_filter( 'pdbmps-global_plugin_events', array( $this, 'register_events' ), 20 );
    
    add_filter( 'pdbmps-current_shortcode_module', array( $this, 'shortcode_module' ) );
    
    add_filter( 'pdbfgt-tab_module_list', function ( $list ) {
      $list[] = 'signup_member_payment';
      $list[] = 'record_member_payment';
      return $list;
    });

  }
  
  /**
   * supplies the current shortcode module
   * 
   * @return string slug id of the current module
   */
  public function shortcode_module()
  {
    return $this->module;
  }

  /**
   * common function for printing all shortcodes
   * 
   * @param array $params array of parameters passed in from the shortcode
   * @param string $content the content of the enclosure (empty string; we don't use enclosure tags)
   * @param string $tag the shortcode identification string
   * @return null 
   */
  public function print_shortcode( $params, $content, $tag )
  {
    /**
     * @version 1.6
     * 
     * 'pdb-shortcode_call_{$tag}' filter allows the shortcode attributes to be 
     * altered before instantiating the shortcode object
     */
    $this->shortcode_atts = \Participants_Db::apply_filters( 'shortcode_call_' . $tag, (array) $params );

    $output = '';
    switch ( $tag ) {
      case 'pdb_record_member_payment':
        $this->module = 'record';
        $output = $this->print_record_edit_form();
        break;

      case 'pdb_signup_member_payment':
        $this->module = 'signup';
        $output = $this->print_signup_form();
        break;

      case 'pdb_signup_member_payment_thanks':
        $this->module = 'signup thanks';
        $output = $this->print_signup_member_payment_thanks_form();
        break;

      case 'pdb_member_payment':
        $this->module = 'member';
        $output = $this->print_member_payment_form();
        break;

      case 'pdb_member_payment_thanks':
        $this->module = 'member thanks';
        $output = $this->print_member_payment_thanks_form();
        break;

    }
    
    return \Participants_Db::apply_filters( $this->module . '_shortcode_output', $output );
  }

  /**
   * sets the plugin template
   * 
   * @var string $template name of the currently selected template
   * @return string template path
   */
  public function set_template( $template )
  {
    global $PDb_Member_Payments;
    $path = empty( $PDb_Member_Payments->parent_path ) ? plugin_dir_path( $PDb_Member_Payments->plugin_path ) : $PDb_Member_Payments->parent_path ;
    /*
     * check for the plugin in the custom location
     * 
     * this will result in no template at all if there is a custom template named in the shortcode and it doesn't exist
     */
    if ( strpos( $template, 'member-payment' ) !== false && !is_file( \Participants_Db::apply_filters( 'custom_template_location', get_stylesheet_directory() . '/templates/' ) . $template ) ) {
      // if not, use the default template
      $template = $path . 'templates/' . $template;
    }
    
    return $template;
  }

  /**
   * prints a signup form
   * 
   * @param array $params the parameters passed in by the shortcode
   * @return string the output HTML
   */
  public function print_signup_form()
  {

    $this->shortcode_atts['module'] = 'signup-member-payment';
    $this->shortcode_atts['post_id'] = get_the_ID();
    
    return PDb_Signup_Member_Payment::print_form( $this->shortcode_atts );
  }

  /**
   * prints a signup member payment thanks form
   * 
   * @param array $params the parameters passed in by the shortcode
   * @return string the output HTML
   */
  public function print_signup_member_payment_thanks_form()
  {
    $this->shortcode_atts['module'] = 'signup-member-payment-thanks';
    $this->shortcode_atts['post_id'] = get_the_ID();
    
    return PDb_Signup_Member_Payment::print_form( $this->shortcode_atts );
  }

  /**
   * prints a member payment form
   * 
   * @param array $params the parameters passed in by the shortcode
   * @return string the output HTML
   */
  public function print_member_payment_form()
  {
    $this->shortcode_atts['module'] = 'member-payment';
    $this->shortcode_atts['post_id'] = get_the_ID();
    
    return PDb_Member_Payment::print_form( $this->shortcode_atts );
  }

  /**
   * prints a member payment thanks form
   * 
   * @param array $params the parameters passed in by the shortcode
   * @return string the output HTML
   */
  public function print_member_payment_thanks_form()
  {
    $this->shortcode_atts['module'] = 'member-payment-thanks';
    $this->shortcode_atts['post_id'] = get_the_ID();
    
    return PDb_Member_Payment::print_form( $this->shortcode_atts );
  }

  /**
   * shows the frontend edit screen called by the [pdb_record] shortcode
   *
   *
   * the ID of the record to show for editing can be provided one of three ways: 
   *    $_GET['pid'] (private link) or in the POST array (actively editing a record)
   *    $atts['id'](deprecated) or $atts['record_id'] (in the sortcode), or 
   *    self::$session->get('pdbid') (directly from the signup form)
   * 
   * 
   * @return string the HTML of the record edit form
   */
  public function print_record_edit_form()
  {
    $this->shortcode_atts['module'] = 'record-member-payment';
    
    $record_id = false;
    // get the pid from the get string if given (for backwards compatibility)
    $get_pid = filter_input( INPUT_GET, \Participants_Db::$record_query, FILTER_SANITIZE_STRING );

    if ( empty( $get_pid ) ) {
      $get_pid = filter_input( INPUT_POST, \Participants_Db::$record_query, FILTER_SANITIZE_STRING );
    }
    if ( !empty( $get_pid ) ) {
      $record_id = \Participants_Db::get_participant_id( $get_pid );
    }

    /*
     * get the id from the SESSION array. This will be present if the user has come 
     * from a form that is in a multi-page series
     */
    if ( $record_id === false && \Participants_Db::$session->get( 'pdbid' ) ) {
      $record_id = \Participants_Db::get_record_id_by_term( 'id', \Participants_Db::$session->get( 'pdbid' ) );
    }

    if ( $record_id === false && (isset( $this->shortcode_atts['id'] ) || isset( $this->shortcode_atts['record_id'] )) ) {
      if ( isset( $this->shortcode_atts['id'] ) & !isset( $this->shortcode_atts['record_id'] ) ) {
        $this->shortcode_atts['record_id'] = $this->shortcode_atts['id'];
        unset( $this->shortcode_atts['id'] );
      }
      $record_id = \Participants_Db::get_record_id_by_term( 'id', $this->shortcode_atts['record_id'] );
    }

    $this->shortcode_atts['record_id'] = $record_id;

    return PDb_Record_Member_Payment::print_form( $this->shortcode_atts );
  }

  /**
   * registers the member status events
   * 
   * @param array $events
   * @return array as $tag => $title
   */
  public function register_events( $events )
  { 
    $events['pdbmps-signup_payment_return'] = __( 'Member Payments: Signup Payment Return', 'pdb_member_payments' );
    $events['pdbmps-member_payment_return'] = __( 'Member Payments: Member Payment Return', 'pdb_member_payments' );
    $events['pdbmps-profile_payment_return'] = __( 'Member Payments: Profile Payment Return', 'pdb_member_payments' );
    $events['pdbmps-offline_payment_return'] = __( 'Member Payments: Offline Payment Return', 'pdb_member_payments' );
    $events['pdbmps-member_offline_payment_return'] = __( 'Member Payments: Member Offline Payment Return', 'pdb_member_payments' );
    $events['pdbmps-signup_offline_payment_return'] = __( 'Member Payments: Signup Offline Payment Return', 'pdb_member_payments' );
    $events['pdbmps-profile_offline_payment_return'] = __( 'Member Payments: Profile Offline Payment Return', 'pdb_member_payments' );
    $events['pdbmps-cancel_payment_return'] = __( 'Member Payments: Cancel Payment Return', 'pdb_member_payments' );
    
    return $events;
  }

}
