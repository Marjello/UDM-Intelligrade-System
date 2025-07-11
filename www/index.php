<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">

  <title>UDM IntelliGrade System</title>
  <meta content="" name="descriptison">
  <meta content="" name="keywords">

  <!-- Favicons -->
  <link href="public/assets/img/favicon.png" rel="icon">
  <link href="public/assets/img/apple-touch-icon.png" rel="apple-touch-icon">

  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Raleway:300,300i,400,400i,500,500i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i" rel="stylesheet">

  <!-- Vendor CSS Files -->
  <link rel="shortcut icon" href="assets/img/ronk1.jpg" />
  <link href="public/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <!-- <link href="assets/vendor/icofont/icofont.min.css" rel="stylesheet"> -->
  <link href="public/assets/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
  <link href="public/assets/vendor/owl.carousel/assets/owl.carousel.min.css" rel="stylesheet">
  <link href="public/assets/vendor/animate.css/animate.min.css" rel="stylesheet">
  <link href="public/assets/vendor/aos/aos.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="public/assets/css/style22.css" rel="stylesheet">

  <style>
    /* Add smooth scrolling behavior */
    html {
      scroll-behavior: smooth;
    }
    
    /* Style for the Core Values and other sections */
    .core-values-list, .institutional-goals-list, .strategic-direction-list {
      list-style-type: none;
      padding-left: 0;
    }
    
    .core-values-list li, .institutional-goals-list li, .strategic-direction-list li {
      margin-bottom: 8px;
    }
    
    .value-letter, .goal-letter, .direction-letter {
      font-weight: bold;
      color: #5fcf80;
    }
    
    /* Hero section description */
    .hero-description {
      font-size: 16px;
      line-height: 1.6;
      color: #fff;
      margin-top: 20px;
      max-width: 800px;
      text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.5);
    }
    
    @media (max-width: 768px) {
      .hero-description {
        font-size: 14px;
      }
    }
  </style>

  <!-- =======================================================
  * Template Name: Mentor - v2.0.0
  * Template URL: https://bootstrapmade.com/mentor-free-education-bootstrap-theme/
  * Author: BootstrapMade.com
  * License: https://bootstrapmade.com/license/
  ======================================================== -->
</head>

<body>

  <!-- ======= Header ======= -->
  <header id="header" class="fixed-top">
    <div class="container d-flex align-items-center">
      <a class="navbar-brand" href="index.php"><img src="public/assets/img/UDMLOGO.png" alt="Logo" style="width:50px;height:50px;"></a>
      <h1 class="logo mr-auto"><a href="index.php">UDM IntelliGrade System</a></h1>
      
      <!-- Uncomment below if you prefer to use an image logo -->
      <!-- <a href="index.html" class="logo mr-auto"><img src="assets/img/logo.png" alt="" class="img-fluid"></a>-->

      <nav class="nav-menu d-none d-lg-block">
        <ul>
          <li class="active"><a href="index.php">Home</a></li>
          <li><a href="#about">About</a></li>        
          <li><a href="#contact">Contact</a></li>

          <li class="drop-down"><a href="">Login <i class='bx bxs-down-arrow'></i></a> 
            <ul>
            <?php
  
      $loginLink = "public/login.php";
?>
<li><a href="<?= $loginLink ?>">Teacher Login</a></li>

             
            </ul>
          </li>
        </ul>
      </nav><!-- .nav-menu -->

      <!-- <a href="#" class="get-started-btn">Alumni</a> -->

    </div>
  </header><!-- End Header -->

  <!-- ======= Hero Section ======= -->
  <section id="hero" class="d-flex justify-content-center align-items-center" style="background-image: url('public/assets/img/udmganda.jpg');">
    <div class="container position-relative" data-aos="zoom-in" data-aos-delay="100">
      <h1>Universidad De Manila IntelliGrade System<br></h1>
      <p class="hero-description">
        Introducing the Universidad De Manila IntelliGrade System: A digital revolution in classroom management. Ditch manual grading and cluttered spreadsheets—this system automates attendance, grades, and reports with precision, saving time and reducing errors. Real-time updates, secure data, and actionable insights empower educators to focus on teaching excellence. Efficiency meets education—welcome to the future.
      </p>
    </div>
  </section><!-- End Hero -->

  <main id="main">

    <!-- ======= About Section ======= -->
    <section id="about" class="about">
      <div class="container" data-aos="fade-up">
        <div class="row">
          <!-- Mission Section -->
          <div class="col-lg-6">
            <div class="section-title">
              <h2>Our Mission</h2>
              <p>Our Mission</p>
            </div>
            <p>
              Universidad de Manila is committed to provide equal opportunities by developing the learners' knowledge, skills, and values, through quality education and dynamic technology-driven systems, in a diverse yet inclusive environment for learning, research, and community engagement.
            </p>
          </div>
          
          <!-- Vision Section -->
          <div class="col-lg-6">
            <div class="section-title">
              <h2>Our Vision</h2>
              <p>Our Vision</p>
            </div>
            <p>
              A leading Higher Education Institution that prepares visionary and ethical leaders who shall create a positive impact on society.
            </p>
          </div>
          
          <!-- Core Values Section -->
          <div class="col-lg-6 mt-4">
            <div class="section-title">
              <h2>Core Values</h2>
              <p>Core Values</p>
            </div>
            <ul class="core-values-list">
              <li><span class="value-letter">E</span> – <strong>Ethics and Integrity</strong></li>
              <li><span class="value-letter">Q</span> – <strong>Quality and Excellence</strong></li>
              <li><span class="value-letter">U</span> – <strong>Unity and Collaboration</strong></li>
              <li><span class="value-letter">A</span> – <strong>Achievement and Passion</strong></li>
              <li><span class="value-letter">L</span> – <strong>Leadership and Innovation</strong></li>
            </ul>
          </div>
          
          <!-- Institutional Goals Section -->
          <div class="col-lg-6 mt-4">
            <div class="section-title">
              <h2>Institutional Goals</h2>
              <p>Institutional Goals</p>
            </div>
            <ul class="institutional-goals-list">
              <li><span class="goal-letter">S</span> – <strong>Systems, Academic Support, Services Improvement</strong></li>
              <li><span class="goal-letter">C</span> – <strong>Center for Micro-credentialing and Industry Training Promotion</strong></li>
              <li><span class="goal-letter">A</span> – <strong>Academic Excellence</strong></li>
              <li><span class="goal-letter">L</span> – <strong>Leveling Up Linkages and Community Extension</strong></li>
              <li><span class="goal-letter">E</span> – <strong>Engagement in Research</strong></li>
            </ul>
          </div>
          
          <!-- Strategic Direction Section -->
          <div class="col-lg-6 mt-4">
            <div class="section-title">
              <h2>Strategic Direction</h2>
              <p>Strategic Direction</p>
            </div>
            <ul class="strategic-direction-list">
              <li><span class="direction-letter">Q</span> – <strong>Quality Systems and Performance</strong></li>
              <li><span class="direction-letter">S</span> – <strong>Stakeholders Satisfaction</strong></li>
              <li><span class="direction-letter">S</span> – <strong>Sustainability and Accountability</strong></li>
            </ul>
          </div>
          
          <!-- Quality Policy Section -->
          <div class="col-lg-6 mt-4">
            <div class="section-title">
              <h2>Quality Policy</h2>
              <p>Quality Policy</p>
            </div>
            <p>
              <strong>Universidad de Manila is committed to implement quality assurance standards and procedures, to improve the University's performance at all levels.</strong>
            </p>
          </div>
        </div>
      </div>
    </section><!-- End About Section -->

    <!-- ======= Contact Section ======= -->
    <section id="contact" class="contact">
      <div class="container" data-aos="fade-up">
        <div class="section-title">
          <h2>Contact</h2>
          <p>Contact Us</p>
        </div>
        <div class="row">
          <div class="col-lg-12">
            <div class="info">
              <div class="address">
                <i class="bx bx-map"></i>
                <h4>Location:</h4>
                <p>One Mehan Gardens Manila, Philippines 1000</p>
              </div>

              <div class="email">
                <i class="bx bx-envelope"></i>
                <h4>Email:</h4>
                <p>registrarsoffice@udm.edu.ph</p>
                 <p>treasuryoffice@udm.edu.ph</p>
                  <p>udmalumni.official@gmail.com</p>
              </div>

              <div class="phone">
                <i class="bx bx-phone-call"></i>
                <h4>Call:</h4>
                <p>09953484885</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section><!-- End Contact Section -->
   
  </main><!-- End #main -->

  <!-- ======= Footer ======= -->
  <footer id="footer">
    <div class="footer-top">
      <div class="container">
        <div class="row">
          <div class="col-lg-3 col-md-6 footer-contact">
          
          <div class="col-lg-2 col-md-6 footer-links">
          </div>
        </div>
      </div>
    </div>

    <div class="container d-md-flex py-4">
      <div class="mr-md-auto text-center text-md-left">
        <div class="copyright">
          &copy; <strong><span>Universdad De Manila IntelliGrade System</span></strong> - <?php echo date('Y');?> - Developed By CET
        </div>
        <div class="credits">
          <!-- All the links in the footer should remain intact. -->
          <!-- You can delete the links only if you purchased the pro version. -->
          <!-- Licensing information: https://bootstrapmade.com/license/ -->
          <!-- Purchase the pro version with working PHP/AJAX contact form: https://bootstrapmade.com/mentor-free-education-bootstrap-theme/ -->
          <!-- Designed by <a href="https://bootstrapmade.com/">BootstrapMade</a> -->
        </div>
      </div>
      <d
  </footer><!-- End Footer -->

  <a href="#" class="back-to-top"><i class="bx bx-up-arrow-alt"></i></a>
  <div id="preloader"></div>

  <!-- Vendor JS Files -->
  <script src="public/assets/vendor/jquery/jquery.min.js"></script>
  <script src="public/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="public/assets/vendor/jquery.easing/jquery.easing.min.js"></script>
  <script src="public/assets/vendor/php-email-form/validate.js"></script>
  <script src="public/assets/vendor/waypoints/jquery.waypoints.min.js"></script>
  <script src="public/assets/vendor/counterup/counterup.min.js"></script>
  <script src="public/assets/vendor/owl.carousel/owl.carousel.min.js"></script>
  <script src="public/assets/vendor/aos/aos.js"></script>

  <!-- Template Main JS File -->
  <script src="public/assets/js/main.js"></script>

  <!-- Script for smooth scrolling with header offset -->
  <script>
    $(document).ready(function() {
      // Add smooth scrolling to all links
      $("a[href^='#']").on('click', function(event) {
        // Make sure this.hash has a value before overriding default behavior
        if (this.hash !== "") {
          // Prevent default anchor click behavior
          event.preventDefault();

          // Store hash
          var hash = this.hash;
          
          // Header height to offset
          var headerHeight = $('#header').outerHeight();
          
          // Using jQuery's animate() method to add smooth page scroll
          $('html, body').animate({
            scrollTop: $(hash).offset().top - headerHeight
          }, 800, function(){
            // Add hash (#) to URL when done scrolling (default click behavior)
            window.location.hash = hash;
          });
        } // End if
      });
    });
  </script>

</body>

</html>