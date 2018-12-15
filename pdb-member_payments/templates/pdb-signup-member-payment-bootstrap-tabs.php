<?php
/*
 * bootstrap template with field group tabs for signup payment form
 *
 * outputs a Twitter Bootstrap-compatible form
 * http://twitter.github.com/bootstrap/index.html
 *
 */
?>

<div class="wrap <?php echo $this->wrap_class ?>" >

  <?php if ( $this->offline_payments->enabled() ) $this->offline_payments->print_js(); ?>

  <?php
  // this is how the html wrapper for the error messages can be customized
  $this->print_errors( '<div class="alert %1$s">%2$s</div>', '<p>%s</p>' );
  ?>

  <?php $this->print_form_head(); // this must be included before any fields are output   ?>
  <?php
  pdb_field_group_tabs( $this );
  ?>

  <div class="form-horizontal pdb-signup">

    <?php while ( $this->have_groups() ) : $this->the_group(); ?>

      <fieldset class="field-group field-group-<?php echo $this->group->name ?>">
        <?php $this->group->print_title( '<legend>', '</legend>' ) ?>
        <?php $this->group->print_description() ?>

        <?php while ( $this->have_fields() ) : $this->the_field(); ?>

          <?php $feedback_class = $this->field->has_error() ? 'error' : ''; ?>

          <div class="<?php $this->field->print_element_class() ?> control-group <?php echo $feedback_class ?>">

            <label class="control-label" for="<?php $this->field->print_element_id() ?>" ><?php $this->field->print_label(); // this function adds the required marker     ?></label>
            <div class="controls"><?php $this->field->print_element_with_id(); ?>

              <?php if ( $this->field->has_help_text() ) : ?>
                <span class="help-block">
                  <?php $this->field->print_help_text() ?>
                </span>
              <?php endif ?>

            </div>

          </div>

        <?php endwhile; // fields   ?>

      </fieldset>
    <?php endwhile; // groups   ?>

    <div class="force-step ui-tabs-submit">

    <?php if ( $this->offline_payments->enabled() ) : ?>

      <fieldset>
        <div class="controls">
          <label class="control-label" ><?php $this->offline_payments->type_selector_label() ?></label>
          <?php $this->offline_payments->print_payment_mode_control() ?>
        </div>
      </fieldset>

      <fieldset class="offline-payment-submit">
        <label class="control-label" ><?php $this->offline_payments->print_label() ?></label>
        <div class="pdb-member_payment_button">
          <div class="payments-help-text"><?php $this->offline_payments->print_help() ?></div>
          <?php $this->print_submit_button( 'button-primary', $this->offline_payments->submit_button_text() ); //  ?>
        </div>
      </fieldset>
    <?php endif; // offline payments  ?>
      <fieldset class="field-group field-group-submit control-group paypal-payment-submit">
        <div id="submit-button" class="controls">
          <label class="control-label" ><?php $this->paypal_payment->print_button_label() ?></label>
          <?php
          global $PDb_Field_Group_Tabs;
          ob_start();
          $this->paypal_payment->print_signup_payment_button();
          ?>
      </fieldset>
      
    </div>
      <?php
      echo $PDb_Field_Group_Tabs->get_next_or_submit_button( $this->shortcode_atts['submit_button'], '', '', ob_get_clean() );
      ?>
    <?php if ( Participants_Db::plugin_setting_is_true( 'show_retrieve_link' ) ) : ?>
      <fieldset class="field-group field-group-submit">
        <span class="pdb-retrieve-link"><?php $this->print_retrieve_link(); ?></span>
      </fieldset>
    <?php endif ?>
  </div>
  <?php $this->print_form_close() ?>
</div>