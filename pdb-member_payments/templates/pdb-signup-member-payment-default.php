<?php
/*
 * default template for signup payment form
 *
 *
 */
?>
<div class="wrap <?php echo $this->wrap_class ?>" >

  <?php if ( $this->offline_payments->enabled() ) $this->offline_payments->print_js(); ?>

  <?php
  // output any validation errors
  $this->print_errors();
  ?>
  <?php $this->print_form_head(); // this must be included before any fields are output. hidden fields may be added here as an array argument to the function ?>

  <table class="form-table pdb-signup">

    <?php while ( $this->have_groups() ) : $this->the_group(); ?>

      <tbody class="field-group field-group-<?php echo $this->group->name ?>">
        <?php if ( \Participants_Db::plugin_setting_is_true( 'signup_show_group_descriptions' ) ) : // are we printing group titles and descriptions? ?>
          <tr class="signup-group">
            <td colspan="2">

              <?php $this->group->print_title() ?>
              <?php $this->group->print_description() ?>

            </td>
          </tr>
        <?php else : ?>
        <?php endif; // end group title/description row ?>

        <?php while ( $this->have_fields() ) : $this->the_field(); ?>

          <tr class="<?php $this->field->print_element_class() ?>">

            <th for="<?php $this->field->print_element_id() ?>"><?php $this->field->print_label(); // this function adds the required marker   ?></th>

            <td>

              <?php $this->field->print_element_with_id(); ?>

              <?php if ( $this->field->has_help_text() ) : ?>
                <span class="helptext"><?php $this->field->print_help_text() ?></span>
              <?php endif ?>

            </td>

          </tr>

        <?php endwhile; // fields ?>

      </tbody>

    <?php endwhile; // groups ?>

    <tbody class="field-group field-group-submit field-group-payment">

      <?php if ( $this->offline_payments->enabled() ) : ?>

        <tr>
          <th><?php $this->offline_payments->type_selector_label() ?></th>
          <td><?php $this->offline_payments->print_payment_mode_control() ?></td>
        </tr>

        <tr class="offline-payment-submit">
          <th><?php $this->offline_payments->print_label() ?></th>

          <td class="submit-buttons">
            <div class="pdb-member_payment_button">
              <div class="payments-help-text"><?php $this->offline_payments->print_help() ?></div>
              <?php $this->print_submit_button( 'button-primary', $this->offline_payments->submit_button_text() ); // ?>
            </div>
          </td>
        </tr>

      <?php endif; // offline payments ?>
      <tr class="paypal-payment-submit">
        <th><?php $this->paypal_payment->print_button_label() ?></th>

        <td class="submit-buttons">
          <?php $this->paypal_payment->print_signup_payment_button() ?>
        </td>
      </tr>
      <tr>
        <td colspan="2"><?php $this->print_retrieve_link(); // this only prints if enabled in the settings   ?></td>
      </tr>

    </tbody>

  </table>

  <?php $this->print_form_close() ?>

</div>