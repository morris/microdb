(function(window) {
	var $ = window.jQuery;
	
	$(function() {
		var $nav = $('#nav');
		var $sections = $('#main section');
		
		// navigation
		function show(section) {
			$sections.hide();
			$(section).show();
			$nav.find('a').removeClass('active');
			$nav.find('a[href='+section+']').addClass('active');
		}
		
		// intial section
		if(window.location.hash)
			show(window.location.hash);
		else
			show('#basics');

		// history
		$(document).on('click', 'a[href^=#]', function(e) {
			e.preventDefault();
			var href = $(this).attr('href');
			window.history.pushState(null, null, href);
			show(href);
		});
		
		$(window).on('popstate', function(e) {
			show(window.location.hash);
		});
		
		// don't focus
		$nav.on('focus', 'a', function() { $(this).blur() });
		
		// replace tabs with spaces
		$('pre').each(function() {
			var $this = $(this);
			$this.html($this.html().replace(/\t/gi, '    '));
		});
	});
})(this);