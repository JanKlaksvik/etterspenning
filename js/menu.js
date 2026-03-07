document.addEventListener("DOMContentLoaded", function () {

  const toggle = document.querySelector(".menu-toggle");
  const menu = document.querySelector(".mobile-menu");
  const overlay = document.querySelector(".menu-overlay");

  if (!toggle || !menu || !overlay) {
    return;
  }

  function openMenu() {
    menu.classList.add("open");
    overlay.classList.add("active");
  }

  function closeMenu() {
    menu.classList.remove("open");
    overlay.classList.remove("active");
  }

  toggle.addEventListener("click", openMenu);
  overlay.addEventListener("click", closeMenu);

});
