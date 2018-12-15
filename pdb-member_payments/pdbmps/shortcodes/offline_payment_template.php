<?php

/**
 * handles an offline payment template functions
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

namespace pdbmps\shortcodes;

class offline_payment_template {

  /**
   * prints the offline payments label
   * 
   */
  public function print_label()
  {
    echo apply_filters( 'pdbmps-offline_type_label', '' );
  }

  /**
   * prints the offline payments label
   * 
   */
  public function print_help()
  {
    echo apply_filters( 'pdbmps-offline_payment_help_text', '' );
  }

  /**
   * provides a payment mode control for the form
   * 
   * @return string HTML
   */
  public function print_payment_mode_control()
  {
    $options = apply_filters( 'pdbmps-payment_type_options_array', array() );
    if ( $i = array_search( 'none', $options ) ) {
      unset( $options[$i] );
    }
    \PDb_FormElement::print_element(
            array(
                'name' => \pdbmps\fields\last_payment_type::status_field_name,
                'type' => 'radio',
                'value' => 'paypal',
                'options' => $options,
                'attributes' => array('id' => 'pdbmps_payment_mode_control'),
                'class' => 'pdbmps_payment_mode_control',
            )
    );
  }
  
  /**
   * prints the label for the payment type selector control
   */
  public function type_selector_label()
  {
    echo apply_filters( 'pdbmps-payment_type_selector_label', '' );
  }

  /**
   * tells if offline payments are enabled
   * 
   * @param string $module the current module
   * @return  bool
   */
  public function enabled( $module = 'signup' )
  {
    global $PDb_Member_Payments;
    return $PDb_Member_Payments->offline_payments_enabled( $module );
  }

  /**
   * supplies the submit button text
   * 
   * @return string
   */
  public function submit_button_text()
  {
    return apply_filters( 'pdbmps-offline_submit_button', 'Submit' );
  }

  /**
   * supplies the thanks message
   * 
   * @return string
   */
  public function thanks_message()
  {
    return apply_filters( 'pdb-member_payments_offline_payment_thanks_message', '' );
  }

  /**
   * prints the payment mode javascript
   */
  public function print_js()
  {
    ?>
    <script>
      PDbMemberPaymentMode = (function ($) {
        var selector, modes;
        var hide_others = function (e) {
          var selected_mode = selector.filter(':checked').val() || 'offline';
          selector.val([selected_mode]);
          hide_all();
          open( $('.' + selected_mode + '-payment-submit') );
        }
        var hide_all = function () {
          modes.hide();
        }
        var open = function (el) {
          if ( ! el.length ) {
            // default to the generic offline method if the chosen method doesn't 
            // have it's own section in the form
            el = $('.offline-payment-submit');
          }
          el.show();
        }
        return {
          init : function () {
            selector = $('input.pdbmps_payment_mode_control');
            modes = $('[class$="-payment-submit"]');
            selector.on('change', hide_others);
            selector.trigger('change');
          }
        }
      }(jQuery));
      jQuery(function () {
        PDbMemberPaymentMode.init();
      });
    </script>
    <?php

  }

}
