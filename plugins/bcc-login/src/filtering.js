jQuery(function ($) {
  $("body").on("click", '[aria-label="open-bcc-filtering"]', function (e) {
    e.preventDefault();
    $(".bcc-groups-filtering").show();
  });

  $("body").on("click", '[aria-label="clear-bcc-groups"]', function (e) {
    e.preventDefault();
    window.history.pushState(null, null, window.location.href.split("?")[0]);
    location.reload();
  });

  $("body").on("click", '[aria-label="close-bcc-filtering"]', function (e) {
    e.preventDefault();
    $(".bcc-groups-filtering").hide();
  });
});
