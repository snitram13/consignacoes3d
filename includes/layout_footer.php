  </main>
</div>
<script>
/* O Safari/iOS pode restaurar a página da memória ao voltar atrás, com stock
   antigo mesmo com os headers de no-store. Se isso acontecer, vai ao servidor. */
window.addEventListener('pageshow', e => { if (e.persisted) location.reload(); });
</script>
</body>
</html>
