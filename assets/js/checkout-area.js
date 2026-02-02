jQuery(function ($) {
  function ensureAreaRowVisibility(show) {
    var $field = $('#ngabs_area');
    if (!$field.length) return;

    var $row = $field.closest('.form-row');
    if (show) {
      $row.show();
    } else {
      $row.hide();
      $field.val('');
    }
  }

  function populateAreas(options) {
    var $field = $('#ngabs_area');
    if (!$field.length) return;

    var current = $field.val() || '';
    $field.empty();
    $field.append($('<option>', { value: '', text: 'Select an area…' }));

    options.forEach(function (opt) {
      $field.append($('<option>', { value: opt.value, text: opt.label }));
    });

    if (current) {
      $field.val(current);
    }
  }

  function fetchAreasAndToggle() {
    var country = $('#shipping_country').val() || $('#billing_country').val() || '';
    var state = $('#shipping_state').val() || $('#billing_state').val() || '';

    country = (country || '').toUpperCase();
    state = (state || '').toUpperCase();

    if (country !== 'NG' || !state) {
      ensureAreaRowVisibility(false);
      $('body').trigger('update_checkout');
      return;
    }

    $.post(
      NGABS.ajax_url,
      {
        action: 'ngabs_get_areas',
        nonce: NGABS.nonce,
        country: country,
        state: state
      },
      function (resp) {
        if (!resp || !resp.success) {
          ensureAreaRowVisibility(false);
          $('body').trigger('update_checkout');
          return;
        }

        var hasAreas = !!resp.data.has_areas;
        if (!hasAreas) {
          ensureAreaRowVisibility(false);
          $('body').trigger('update_checkout');
          return;
        }

        populateAreas(resp.data.options || []);
        ensureAreaRowVisibility(true);
        $('body').trigger('update_checkout');
      }
    );
  }

  $(document.body).on('updated_checkout', function () {
    fetchAreasAndToggle();
  });

  $(document).on('change', '#shipping_country, #shipping_state, #billing_country, #billing_state', function () {
    fetchAreasAndToggle();
  });

  $(document).on('change', '#ngabs_area', function () {
    $('body').trigger('update_checkout');
  });

  ensureAreaRowVisibility(false);
});
