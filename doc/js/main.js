(function(scope) {
	var $ = scope.jQuery;
	
	$(function() {
		// navigation
		var $sections = $('#content section');
		
		function goto(section) {
			$sections.hide();
			$(section).show();
			$('#nav a').removeClass('active');
			$('a[href='+section+']').addClass('active');
		}
		
		if(window.location.hash)
			goto(window.location.hash);
		else
			goto('#introduction');

		$('#nav').on('click', 'a', function(e) {
			goto($(this).attr('href'));
		});
		
		$('#nav').on('focus', 'a', function(e) {
			$(this).blur();
		});
		
		// replace tabs with spaces...
		$('code').each(function() {
			var $this = $(this);
			$this.html($this.html().replace(/\t/gi, '    '));
		});
	});
})(this);