// Shared nav & footer injected on every page
(function () {
  const page = location.pathname.split('/').pop() || 'index.html';

  const navHTML = `
  <nav id="mainNav">
    <a href="index.html"><img src="FullLogo.png" alt="Logo" class="half"> </a>
    <ul class="nav-links" id="navLinks">
      <li><a href="index.html" ${page==='index.html'?'class="active"':''}>Home</a></li>
      <li><a href="services.html" ${page==='services.html'?'class="active"':''}>Services</a></li>
      <li><a href="about.html" ${page==='about.html'?'class="active"':''}>About</a></li>
      <li><a href="team.html" ${page==='team.html'?'class="active"':''}>Team</a></li>
      <li class="nav-cta"><a href="contact.html" ${page==='contact.html'?'class="active"':''}>Contact Us</a></li>
    </ul>
    <div class="hamburger" id="hamburger">
      <span></span><span></span><span></span>
    </div>
  </nav>`;
  const footerHTML = `
  <footer>
    <div class="footer-grid">
      <div class="footer-brand">
        <a href="index.html" class="logo">JUNE<span>LEEZ</span> TECH</a>
        <p>Delivering innovative technology solutions that drive growth and digital transformation for businesses across Africa and the globe.</p>
      </div>
      <div class="footer-col">
        <h4>Services</h4>
        <ul>
          <li><a href="services.html#software">Software Dev</a></li>
          <li><a href="services.html#cloud">Cloud Services</a></li>
          <li><a href="services.html#ai">AI &amp; Automation</a></li>
          <li><a href="services.html#security">Cybersecurity</a></li>
          <li><a href="services.html#data">Data Analytics</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h4>Company</h4>
        <ul>
          <li><a href="about.html">About Us</a></li>
          <li><a href="team.html">Our Team</a></li>
          <li><a href="contact.html">Careers</a></li>
          <li><a href="contact.html">Blog</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h4>Contact</h4>
        <ul>
          <li><a href="contact.html">Tororo, Uganda</a></li>
          <li><a href="mailto:juneleeztech@gmail.com">juneleeztech@gmail.com</a></li>
          <li><a href="tel:+256 783 986424">+256 702 348127</a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      © 2025 Juneleez Tech. All rights reserved. — Built for the Future.
    </div>
  </footer>
  <div class="toast" id="toast">✓ Message sent! We'll be in touch shortly.</div>`;
  document.body.insertAdjacentHTML('afterbegin', navHTML);
  document.body.insertAdjacentHTML('beforeend', footerHTML);

  // Hamburger toggle
  document.getElementById('hamburger').addEventListener('click', () => {
    document.getElementById('navLinks').classList.toggle('open');
  });

  // Scroll reveals
  const observer = new IntersectionObserver((entries) => {
    entries.forEach((e, i) => {
      if (e.isIntersecting) setTimeout(() => e.target.classList.add('visible'), i * 80);
    });
  }, { threshold: 0.08 });
  document.querySelectorAll('.reveal').forEach(el => observer.observe(el));
})();
