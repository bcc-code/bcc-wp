jQuery(function ($) {
  $(document).on("click", "#clear-bcc-groups", function (e) {
    e.preventDefault();
    window.history.pushState(null, null, window.location.href.split("?")[0]);
    location.reload();
  });

  $(document).on("click", "#expand-btn, #minimize-btn", function () {
    $(".bcc-filter ul").toggleClass("expanded");
    $(".bcc-filter #expand-btn").toggle();
    $(".bcc-filter #minimize-btn").toggle();
  });

  $(document).on("click", "#toggle-bcc-filter", function () {
    $("#bcc-filter-groups").addClass("active");
    $("body").addClass("no-scroll");
  });

  $(document).on("click", "#close-bcc-groups", function () {
    $("#bcc-filter-groups").removeClass("active");
    $("body").removeClass("no-scroll");
  });
});
