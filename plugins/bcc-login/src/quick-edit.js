jQuery(function ($) {
  var wp_inline_edit_function = inlineEditPost.edit;

  inlineEditPost.edit = function (post_id) {
    wp_inline_edit_function.apply(this, arguments);

    var id = 0;
    if (typeof post_id == "object") {
      id = parseInt(this.getId(post_id));
    }

    if (id > 0) {
      var specific_post_row = $("#post-" + id);
      var post_audience = $(".column-post_audience", specific_post_row).text();
      var post_groups = $(".column-post_groups", specific_post_row)
        .text()
        .split(",");

      // uncheck option from previous opened post
      $(':input[name="bcc_login_visibility"]').attr("checked", false);
      // populate the inputs with column data
      $(
        ':input[name="bcc_login_visibility"][id="option-' + post_audience + '"]'
      ).attr("checked", true);

      // uncheck option from previous opened post
      $(':input[name="bcc_groups[]"]').attr("checked", false);
      // populate the inputs with column data
      for (const post_group of post_groups) {
        $(':input[name="bcc_groups[]"][id="option-' + post_group + '"]').attr(
          "checked",
          true
        );
      }
    }
  };

  // Hide the setting from the Screen Options because we overwrite it with CSS
  $('#screen-options-wrap :input[value="post_audience"]').parent().hide();
});
