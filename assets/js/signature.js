/* Bloco de assinatura no canvas — usado em cliente_novo.php e acerto.php */
const Sig = (() => {
  let canvas, ctx, drawing = false, hasMark = false, onConfirmCb = null, bound = false;

  function getPos(e) {
    const r = canvas.getBoundingClientRect();
    const t = e.touches ? e.touches[0] : e;
    return { x: t.clientX - r.left, y: t.clientY - r.top };
  }
  function start(e) {
    e.preventDefault();
    drawing = true; hasMark = true;
    const p = getPos(e);
    ctx.beginPath(); ctx.moveTo(p.x, p.y);
  }
  function move(e) {
    if (!drawing) return;
    e.preventDefault();
    const p = getPos(e);
    ctx.lineTo(p.x, p.y); ctx.stroke();
  }
  function end() { drawing = false; }

  function setup() {
    canvas = document.getElementById('sig-canvas');
    ctx = canvas.getContext('2d');
    const r = canvas.getBoundingClientRect();
    canvas.width = r.width * devicePixelRatio;
    canvas.height = r.height * devicePixelRatio;
    ctx.scale(devicePixelRatio, devicePixelRatio);
    ctx.strokeStyle = '#1a1a18';
    ctx.lineWidth = 2.5;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    if (!bound) {
      canvas.addEventListener('touchstart', start, { passive: false });
      canvas.addEventListener('touchmove', move, { passive: false });
      canvas.addEventListener('touchend', end);
      canvas.addEventListener('mousedown', start);
      canvas.addEventListener('mousemove', move);
      canvas.addEventListener('mouseup', end);
      canvas.addEventListener('mouseleave', end);
      bound = true;
    }
  }

  return {
    /* open({title, instructionHtml, resumoHtml, onConfirm(dataUrl)}) */
    open(opts) {
      document.getElementById('sig-title').textContent = opts.title || 'Assinatura do cliente';
      document.getElementById('sig-instruction-text').innerHTML = opts.instructionHtml || '';
      document.getElementById('sig-resumo').innerHTML = opts.resumoHtml || '';
      onConfirmCb = opts.onConfirm;
      hasMark = false;
      document.getElementById('sig-screen').classList.add('open');
      requestAnimationFrame(setup);
    },
    close() {
      document.getElementById('sig-screen').classList.remove('open');
    },
    clear() {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      hasMark = false;
    },
    confirm() {
      if (!hasMark) { alert('Por favor, assine antes de confirmar.'); return; }
      const data = canvas.toDataURL('image/png');
      Sig.close();
      if (onConfirmCb) onConfirmCb(data);
    }
  };
})();

/* Utilidades partilhadas pelos formulários */
function fmtEUR(v) {
  return (Math.round(v * 100) / 100).toFixed(2).replace('.', ',') + '€';
}
function escHtml(s) {
  return String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}
