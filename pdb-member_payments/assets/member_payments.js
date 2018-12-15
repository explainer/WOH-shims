/**
 * 
 * @version 1.3
 * 
 */
jQuery(document).ready(function ($) {
  var formwrap = $('.pdb-signup-member-payment, .pdb-member-payment, .pdb-record-member-payment');
  var form = formwrap.find('form');
  var payment_type_selector = $('input[type=radio][name=' + pdb_ajax.payment_type_field + ']');
  var spinner = $(pdb_ajax.loading_indicator).clone();
  if (form.length) {
    form.find('input[name=submit], input[name=submit_button]').click(function (e) {
      e.preventDefault();
      var submitButton = $(this);
      /**
       * check for the radio button payment type selector, if it's not there, assume 
       * it's a paypal-only form
       */
      var payment_type = payment_type_selector.length ? payment_type_selector.filter(':checked').val() : 'paypal';
      if (payment_type === 'offline') {
        var actionval = form.find('input[name=action]').val();
        var action_type = actionval.match(/^signup/) ? 'signup':actionval.match(/^profile/) ? 'update':'member-payment';
        form.find('input[name=action]').val(action_type);
        form.prop('action', form.find('input[name=thanks_page]').val() );
      }
      form.find('input[name=' + pdb_ajax.payment_type_field + ']').val(payment_type);
      // rename the action field so it doesn't conflict with the ajax action field
      if (!form.find('input[name=submit_action]').length) {
        form.find('input[name=action]').attr('name', 'submit_action');
      }
      form
              .append($('<input>', {
                name : 'action',
                type : 'hidden',
                value : pdb_ajax.action
              }))
              .append($('<input>', {
                name : 'nonce',
                type : 'hidden',
                value : pdb_ajax.nonce
              }));
      var postData = new FormData(form[0]);
      // take out the error messages
      $('div.pdb-error').prev('style').remove().end().remove();
      var posting = $.ajax({
        type : 'POST',
        url : pdb_ajax.ajaxurl,
        data : postData,
        processData : false,
        contentType : false,
        beforeSend : function () {
          submitButton.closest('div').append(spinner);
        },
        error : function (jqXHR, status) {
          spinner.replaceWith($('<span>error ' + jqXHR.status + ' ' + jqXHR.statusText + '</span>'));
        }
      });
      posting.done(function (response, status) {
        if (response.registration_id === false) {
          formwrap.prepend(response.errorHTML);
          spinner.remove();
        } else {
          switch (payment_type) {
            case 'paypal':
              add_ipn_query_var(response.registration_id);
              form.attr('action', pdb_ajax.paypalURL);
              form.submit();
              break;
            default:
              form.submit();
          }
        }
      });
    });
  }
  var add_ipn_query_var = function (id) {
    $('input[name=cmd][value=_s-xclick]').after($('<input>', {
      name : 'notify_url',
      type : 'hidden',
      value : pdb_ajax.notify_url + pdb_ajax.query_var + '=' + id
    })).after($('<input>', {
      name : 'custom',
      type : 'hidden',
      value : id
    }));
  }
});