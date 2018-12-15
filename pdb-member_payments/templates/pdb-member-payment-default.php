<?php
/*
 * bootstrap template for member payment form
 * 
 * jan 2018: adds offline payment support
 *
 * outputs a Twitter Bootstrap-compatible form
 * http://twitter.github.com/bootstrap/index.html
 *
 */
?>

<div class="wrap <?php echo $this->wrap_class ?>" >

  <?php if ( $this->offline_payments->enabled( $this->module ) ) $this->offline_payments->print_js(); ?>

  <?php
  // this is how the html wrapper for the error messages can be customized
  $this->print_errors( '<div class="alert %1$s">%2$s</div>', '<p>%s</p>' );
  ?>

<?php $this->print_form_head(); // this must be included before any fields are output   ?>

  <div class="form-horizontal pdb-signup">

<?php while ( $this->have_groups() ) : $this->the_group(); ?>

      <fieldset class="field-group field-group-<?php echo $this->group->name ?>">
        <?php // $this->group->print_title( '<legend>', '</legend>' ) ?>
        <?php // $this->group->print_description()  ?>

        <?php while ( $this->have_fields() ) : $this->the_field(); ?>

          <?php $this->field->readonly = 0; // allow readonly field to be used in this form  ?>

    <?php $feedback_class = $this->field->has_error() ? 'error' : ''; ?>

          <div class="<?php $this->field->print_element_class() ?> control-group <?php echo $feedback_class ?>">

            <label class="control-label" for="<?php $this->field->print_element_id() ?>" ><?php $this->field->print_label(); // this function adds the required marker   ?></label>
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
    <fieldset class="field-group field-group-submit control-group">

<?php if ( $this->offline_payments->enabled( $this->module ) ) : ?>

        <div id="payment-type-selector" class="controls">
          <label class="control-label" ><?php $this->offline_payments->type_selector_label() ?></label>
  <?php $this->offline_payments->print_payment_mode_control() ?>
        </div>


        <div id="submit-offlne-payment" class="controls offline-payment-submit">
          <label class="control-label" ><?php $this->offline_payments->print_label() ?></label>

          <div class="payments-help-text"><?php $this->offline_payments->print_help() ?></div>

  <?php $this->print_submit_button( 'button-primary', $this->offline_payments->submit_button_text() ); //  ?>

        </div>

<?php endif ?>

      <div id="submit-button" class="controls paypal-payment-submit">
        <label class="control-label" ><?php pdb_member_payments_print_payment_button_label() ?></label>
<?php pdb_member_payments_print_signup_payment_button() ?>
      </div>
      <span class="pdb-retrieve-link"><?php $this->print_retrieve_link( __( 'Forget your private link? Click here to have it emailed to you.', 'participants-database' ) ); ?></span>
    </fieldset>
  </div>
<?php $this->print_form_close() ?>
</div>