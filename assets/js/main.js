// =============================================================
//  DemoPropertyRent — JavaScript principal
// =============================================================

// Auto-ocultar alertas de URL después de 4 segundos
document.addEventListener('DOMContentLoaded', function () {
  const alerts = document.querySelectorAll('.dpr-alert');
  alerts.forEach(function (el) {
    setTimeout(function () {
      el.style.transition = 'opacity .5s';
      el.style.opacity = '0';
      setTimeout(function () { el.remove(); }, 500);
    }, 4000);
  });

  // Confirmar antes de eliminar (formularios con data-confirm)
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!confirm(form.dataset.confirm || '¿Estás seguro?')) {
        e.preventDefault();
      }
    });
  });

  // Formatear inputs de moneda en tiempo real
  document.querySelectorAll('input[data-format="money"]').forEach(function (input) {
    input.addEventListener('blur', function () {
      const val = parseFloat(this.value.replace(/[^0-9.]/g, ''));
      if (!isNaN(val)) this.value = Math.round(val);
    });
  });
});
