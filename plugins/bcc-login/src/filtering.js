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

  $(document).on("click", "#bcc-filter-submit", function () {
    const param = "target-groups[]=";
    var filteredGroups = [];

    $('#bcc-filter-groups input[type="checkbox"]').each(function () {
      if (this.checked) filteredGroups.push(this.value);
    });

    const queryParams = param + filteredGroups.join("&" + param);

    if (history.pushState) {
      history.pushState(null, null, window.location.href + "?" + queryParams);
      location.reload();
    }
  });
});
