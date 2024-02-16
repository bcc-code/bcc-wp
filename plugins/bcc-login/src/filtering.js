jQuery(function ($) {

  $(document).on("click", '#clear-bcc-groups', function (e) {
    e.preventDefault();
    window.history.pushState(null, null, window.location.href.split("?")[0]);
    location.reload();
  });

  $(document).on('click', '#expand-btn', function () {
    $(".bcc-filter ul").toggleClass("expanded");
    if ($(".bcc-filter ul").hasClass("expanded")) {
      $(this).text("- hide groups");
    } else {
      $(this).text("+ see all groups");
    }
  })

  $(document).on('click', '#toggle-bcc-filter', function () {
    $('#bcc-filter-groups').addClass('active');
    $('body').addClass('no-scroll')
  })


  $(document).on('click', '#close-bcc-groups', function () {
    $('#bcc-filter-groups').removeClass('active');
    $('body').removeClass('no-scroll')
  })

});
