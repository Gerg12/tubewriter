jQuery(document).ready(function($) {

    

    
    // Initialize AOS
    AOS.init({
        duration: 800,
        once: false,
    });

    // Handle smooth scrolling with header offset for anchor links
    $('a[href*="#"]:not([href="#"])').click(function() {
        if (location.pathname.replace(/^\//, '') == this.pathname.replace(/^\//, '') && location.hostname == this.hostname) {
            var target = $(this.hash);
            target = target.length ? target : $('[name=' + this.hash.slice(1) + ']');
            if (target.length) {
                // Close mobile menu if this anchor link is within the mobile menu
                if ($(this).closest('#mobile-menu').length > 0) {
                    closeMobileMenu();
                }
                
                // Calculate header height for offset
                var headerHeight = $('.site-header').outerHeight() || 60;
                var targetOffset = target.offset().top - headerHeight - 20; // 20px extra padding
                
                $('html, body').animate({
                    scrollTop: targetOffset
                }, 800, 'swing');
                return false;
            }
        }
    });

    // Mobile menu toggle
    const mobileMenuButton = document.getElementById('mobile-menu-button');
    const mobileMenu = document.getElementById('mobile-menu');
    const mobileContactButton = document.querySelector('.mobile-contact-button');
    
    // Function to close mobile menu
    function closeMobileMenu() {
        mobileMenu.classList.remove('is-active');
        mobileMenuButton.setAttribute('aria-expanded', 'false');
        
        // Hide mobile contact button
        if (mobileContactButton) {
            mobileContactButton.classList.remove('show-button');
        }
    }
    
    mobileMenuButton.addEventListener('click', () => {
        const isExpanded = mobileMenuButton.getAttribute('aria-expanded') === 'true';
        mobileMenu.classList.toggle('is-active');
        mobileMenuButton.setAttribute('aria-expanded', !isExpanded);
        
        // Show/hide mobile contact button with mobile menu
        if (mobileContactButton) {
            mobileContactButton.classList.toggle('show-button');
        }
    });


	console.log('ready2');
	$('.calendar-button a').click(function(event) {
     // Prevent the default behavior of the link
		 event.preventDefault();

		 // Get the href value
		 var hrefValue = $(this).attr('href');

		 // Log the href value to the console (you can use it as needed)
		 console.log(hrefValue);

		$(hrefValue).toggle();
	});
	handleScroll('.fadein', 'faded');
	handleScroll('.fadeinleft', 'faded-left');
	handleScroll('.fadeinright', 'faded-right');

	$(window).scroll(function() {
		handleScroll('.fadein', 'faded');
		handleScroll('.fadeinleft', 'faded-left');
		handleScroll('.fadeinright', 'faded-right');
	});

	function handleScroll(element, className) {
		$(element).each(function() {
			var top_of_element = $(this).offset().top;
			var bottom_of_element = top_of_element + $(this).outerHeight();
			var top_of_window = $(window).scrollTop();
			var bottom_of_window = top_of_window + $(window).height();

			if (bottom_of_window > top_of_element && top_of_window < bottom_of_element) {
				$(this).addClass(className);
			} else {
				$(this).removeClass(className);
			}
		});
	}

	$('.mobile-menu__button').click(function(e) {
		console.log('clicked');
		e.preventDefault();
		$('.mobile-menu-overlay').toggleClass('overlay-active');
		$('.mobile-menu').toggleClass('menu-open');
        $('html').toggleClass('menu-open');
	});
	$('.close-button, .mobile-menu-overlay, .mobile-menu-bg-overlay').click(function(e) {
		e.preventDefault();
		$('.mobile-menu-overlay').removeClass('overlay-active');
		$('.mobile-menu').removeClass('menu-open');
        $('html').removeClass('menu-open');
	});

	// Close mobile menu when anchor links within mobile menu are clicked (alternative implementation)
	$('.mobile-menu a[href*="#"]:not([href="#"])').click(function() {
		$('.mobile-menu-overlay').removeClass('overlay-active');
		$('.mobile-menu').removeClass('menu-open');
        $('html').removeClass('menu-open');
	});

	$('.hero-slider > .wp-block-group__inner-container').slick({
		// Your slick options here
		dots: true,
		arrows: true,
		autoplay: true,
		autoplaySpeed: 5000,
		// etc.
	});
});

function initializeImageRotation() {
    const cards = document.querySelectorAll('.flex-cards__item-inner');
    
    cards.forEach(card => {
        const images = card.querySelectorAll('.flex-cards__image');
        
        // Only proceed if we have multiple images
        if (images && images.length > 1) {
            let currentIndex = 0;
            images[0].classList.add('active'); // Ensure first image is visible
            
            // Generate random interval between 2000ms (2s) and 5000ms (5s)
            const randomInterval = Math.floor(Math.random() * (5000 - 2000 + 1) + 2000);
            
            setInterval(() => {
                // Remove active class from current image
                images[currentIndex].classList.remove('active');
                
                // Move to next image
                currentIndex = (currentIndex + 1) % images.length;
                
                // Add active class to next image
                images[currentIndex].classList.add('active');
            }, randomInterval); // Random speed for each card
        } else if (images && images.length === 1) {
            // If only one image, make sure it's visible
            images[0].classList.add('active');
        }
    });
}

// Initialize when document is ready
jQuery(document).ready(function($) {
    initializeImageRotation();
});

// Re-initialize when new content might be loaded
jQuery(document).on('ajaxComplete', function() {
    initializeImageRotation();
});


document.addEventListener('DOMContentLoaded', () => {
  const header = document.querySelector('#masthead');
  
  const handleScroll = () => {
    if (window.scrollY > 100) {
      header.classList.add('sticky');
    } else {
      header.classList.remove('sticky');
    }
  };

  // Initial check in case page is loaded scrolled down
  handleScroll();
  
  // Add scroll event listener
  window.addEventListener('scroll', handleScroll);
});
