<!-- ==============================
   FOOTER (Hormuud University)
   ============================== -->
<footer class="hu-footer" id="huFooter">
  <div class="footer-content">
    
    <!-- Left: Logo & Brand -->
    <div class="footer-left">
      <div class="footer-brand">
        <div class="footer-logo">
          <img src="../images.png" alt="Hormuud University Logo" width="30" height="30" style="border-radius:6px; object-fit:contain;">
        </div>
        <div class="footer-brand-info">
          <h3 class="footer-title">Hormuud University</h3>
          <p class="footer-tagline">Excellence in Education & Innovation</p>
        </div>
      </div>
    </div>

    <!-- Center: Copyright & Developed by -->
    <div class="footer-center">
      <p class="footer-copyright">
        <strong>Hormuud University</strong> © <span id="currentYear"><?= date("Y") ?></span> — All Rights Reserved.
      </p>
      <p class="footer-developed">
        Developed by <strong>BSE1 Student</strong>
      </p>
    </div>

    <!-- Right: Social Links -->
    <div class="footer-right">
      <div class="social-links">
        <a href="https://www.facebook.com/989824434439949" class="social-link" title="Facebook" target="_blank" rel="noopener noreferrer"><i class="fab fa-facebook-f"></i></a>
        <a href="https://twitter.com/HormuudUni" class="social-link" title="Twitter" target="_blank" rel="noopener noreferrer"><i class="fab fa-twitter"></i></a>
        <a href="https://www.linkedin.com/school/hormuud-university/" class="social-link" title="LinkedIn" target="_blank" rel="noopener noreferrer"><i class="fab fa-linkedin-in"></i></a>
        <a href="https://www.instagram.com/hormuud_university/" class="social-link" title="Instagram" target="_blank" rel="noopener noreferrer"><i class="fab fa-instagram"></i></a>
        <a href="https://www.tiktok.com/@hormuud_university" class="social-link" title="TikTok" target="_blank" rel="noopener noreferrer"><i class="fab fa-tiktok"></i></a>
        <a href="https://wa.me/252613311119" class="social-link" title="WhatsApp" target="_blank" rel="noopener noreferrer"><i class="fab fa-whatsapp"></i></a>
      </div>
    </div>

  </div>
</footer>

<style>
/* ==============================
   HORMUUD FOOTER - Optimized Small & Professional
   ============================== */
:root {
  --hu-green: #00843D;
  --hu-blue: #0072CE;
  --hu-light-green: #00A651;
  --hu-white: #FFFFFF;
}

.hu-footer {
  position: fixed;
  bottom: 0;
  left: 240px; /* Match sidebar width */
  right: 0;
  background: linear-gradient(135deg, var(--hu-green), var(--hu-blue));
  color: var(--hu-white);
  display: flex;
  justify-content: center;
  font-size: 13px;
  font-weight: 500;
  padding: 8px 20px;
  z-index: 998;
  box-shadow: 0 -2px 8px rgba(0,0,0,0.15);
  border-top: 1px solid rgba(255,255,255,0.2);
  transition: left 0.3s ease; /* Smooth transition with sidebar */
}

/* When sidebar is collapsed */
body.sidebar-collapsed .hu-footer {
  left: 60px; /* Match collapsed sidebar width */
}

.footer-content {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  max-width: 1200px;
  gap: 15px;
}

.footer-left, .footer-center, .footer-right {
  display: flex;
  align-items: center;
}

.footer-center {
  flex-direction: column;
  gap: 2px;
  text-align: center;
}

.footer-developed {
  font-size: 0.8em;
  color: rgba(255,255,255,0.8);
  font-style: italic;
  margin: 0;
}

.footer-brand {
  display: flex;
  align-items: center;
  gap: 8px;
}

.footer-logo img {
  display: block;
  border-radius: 6px;
}

.footer-title {
  font-size: 14px;
  font-weight: 700;
  margin: 0;
}

.footer-tagline {
  font-size: 10px;
  margin: 0;
  color: rgba(255,255,255,0.9);
}

.social-links {
  display: flex;
  gap: 8px;
}

.social-link {
  width: 28px;
  height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(255,255,255,0.1);
  border-radius: 50%;
  color: var(--hu-white);
  font-size: 12px;
  transition: all 0.2s ease;
}

.social-link:hover {
  background: var(--hu-light-green);
  transform: translateY(-2px);
}

/* Responsive Breakpoints */

/* Tablet (1024px) */
@media (max-width: 1024px) {
  .hu-footer { 
    left: 200px; /* Adjusted for tablet sidebar */
  }
  
  body.sidebar-collapsed .hu-footer {
    left: 60px;
  }
}

/* Tablet and smaller (768px) */
@media (max-width: 768px) {
  .hu-footer {
    left: 0 !important;
    width: 100% !important;
    flex-direction: column;
    padding: 12px 15px;
    position: fixed;
    bottom: 0;
  }
  
  .footer-content { 
    flex-direction: column; 
    gap: 10px; 
  }
  
  .footer-left, .footer-center, .footer-right { 
    justify-content: center; 
    width: 100%; 
  }
  
  .footer-brand-info { 
    text-align: center; 
    display: block; 
  }
  
  .social-links { 
    justify-content: center; 
  }
}

/* Mobile (480px) */
@media (max-width: 480px) {
  .footer-copyright,
  .footer-developed { 
    font-size: 11px; 
  }
  
  .social-link { 
    width: 26px; 
    height: 26px; 
    font-size: 11px; 
  }
  
  .footer-title {
    font-size: 12px;
  }
  
  .footer-tagline {
    font-size: 9px;
  }
}

/* Large Devices (768px - 1024px) */
@media (min-width: 769px) and (max-width: 1024px) {
  .hu-footer {
    left: 200px; /* Match sidebar width for large devices */
  }
  
  body.sidebar-collapsed .hu-footer {
    left: 60px;
  }
}

/* Landscape Mode for Mobile */
@media (max-height: 480px) and (orientation: landscape) {
  .hu-footer {
    padding: 6px 15px;
  }
  
  .footer-content {
    flex-direction: row;
    gap: 10px;
  }
  
  .footer-left, .footer-center, .footer-right {
    width: auto;
  }
}

/* Touch Device Optimizations */
@media (hover: none) and (pointer: coarse) {
  .social-link {
    min-height: 44px;
    min-width: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
  }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const yearSpan = document.getElementById('currentYear');
  if(yearSpan) yearSpan.textContent = new Date().getFullYear();
  
  // Update footer position on sidebar toggle
  const sidebarToggle = document.getElementById('sidebarToggle');
  const sidebar = document.getElementById('sidebar');
  const body = document.body;
  const footer = document.getElementById('huFooter');
  
  if (sidebarToggle && footer) {
    sidebarToggle.addEventListener('click', function() {
      // Wait for the sidebar transition to complete
      setTimeout(updateFooterPosition, 300);
    });
  }
  
  // Update footer position on window resize
  window.addEventListener('resize', updateFooterPosition);
  
  // Initial position update
  updateFooterPosition();
});

function updateFooterPosition() {
  const footer = document.getElementById('huFooter');
  const sidebar = document.getElementById('sidebar');
  const body = document.body;
  
  if (!footer || !sidebar) return;
  
  const isMobile = window.innerWidth <= 768;
  
  if (isMobile) {
    // On mobile, footer is always full width
    footer.style.left = '0';
    footer.style.width = '100%';
  } else {
    // On desktop, check if sidebar is collapsed
    const isCollapsed = body.classList.contains('sidebar-collapsed');
    
    if (isCollapsed) {
      footer.style.left = '60px';
    } else {
      footer.style.left = '240px';
    }
  }
}
</script>