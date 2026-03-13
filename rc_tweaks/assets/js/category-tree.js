jQuery(document).ready(function($){
  // Delegate to handle dynamically-injected widgets, too
  $('body').on('click', '.rc-toggle-icon.closed', function(){
    var $icon = $(this);
    var $childrenUl = $icon.closest('li').children('ul.children');

    // Slide toggle the nested list
    $childrenUl.slideDown(200);
    // Swap classes so next click collapses
    $icon.removeClass('closed').addClass('open').text('▼');
  });

  $('body').on('click', '.rc-toggle-icon.open', function(){
    var $icon = $(this);
    var $childrenUl = $icon.closest('li').children('ul.children');

    $childrenUl.slideUp(200);
    $icon.removeClass('open').addClass('closed').text('▶');
  });
});
