<!-- Ecrã de assinatura (overlay partilhado) — requer assets/js/signature.js -->
<div id="sig-screen">
  <div class="sig-header">
    <button type="button" class="topbar-back" onclick="Sig.close()">‹ Voltar</button>
    <div class="sig-title" id="sig-title">Assinatura do cliente</div>
  </div>
  <div class="sig-instruction"><p id="sig-instruction-text"></p></div>
  <div class="sig-resumo" id="sig-resumo"></div>
  <canvas id="sig-canvas"></canvas>
  <div class="sig-footer">
    <button type="button" class="sig-clear" onclick="Sig.clear()">🔄 Limpar</button>
    <button type="button" class="sig-confirm" onclick="Sig.confirm()">✓ Confirmar</button>
  </div>
</div>
<script src="assets/js/signature.js"></script>
