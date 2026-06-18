# Pôr no ar no Render

A app já está pronta (Docker + render.yaml + segredos por variáveis de ambiente).
O link final é **um só** e funciona no **computador e no telemóvel** (de qualquer lado):
`https://consignacoes-3d.onrender.com` (o nome exato é gerado no deploy).

---

## ⚠️ Importante: onde ficam os dados

No Render **grátis** o disco é apagado a cada novo deploy/reinício → com SQLite os
clientes/stock/recibos **perdem-se**. Escolhe uma opção:

| Opção | Custo | Dados | Para quê |
|---|---|---|---|
| **A. Grátis + SQLite** | 0€ | ❌ apagados a cada deploy | só demonstração |
| **B. Starter + disco** | ~7$/mês | ✅ permanentes | uso real (recomendado) |
| **C. Grátis + MySQL externo** | 0€ | ✅ permanentes | uso real sem pagar (mais passos) |

Para a **opção B**: no `render.yaml` muda `plan: free` → `plan: starter` e descomenta o
bloco `disk:`. Os dados passam a viver no disco montado em `/var/www/html/data`.

---

## Passos

### 1. Pôr o código no GitHub
```bash
cd "consignacoes-php"
# repositório já iniciado e com 1 commit
git remote add origin https://github.com/<o-teu-utilizador>/consignacoes-3d.git
git push -u origin main
```
(Cria primeiro o repositório vazio em github.com → New repository → "consignacoes-3d".)

### 2. Criar o serviço no Render
1. Entra em https://render.com (regista-te, dá para entrar com o GitHub)
2. **New + → Blueprint** → escolhe o repositório `consignacoes-3d`
3. O Render lê o `render.yaml` e cria o serviço automaticamente

### 3. Definir o segredo do email
No serviço criado → **Environment** → confirma/define:
- `SMTP_PASS` = `REMOVIDO`  (a palavra-passe de app do Gmail)

(As outras variáveis já vêm do `render.yaml`.)

### 4. Primeiro arranque
- Espera o build (~2-4 min). No fim tens o link `https://...onrender.com`
- Abre `https://...onrender.com/install.php` → cria a tua conta de acesso
- Pronto. Esse link serve para o computador **e** para o telemóvel.

---

## Notas
- **Grátis**: o serviço "adormece" após 15 min sem uso; o primeiro acesso seguinte
  demora ~1 min a acordar. Os planos pagos ficam sempre ligados.
- O `config.local.php` (segredos locais) e a base SQLite **nunca** vão para o Git.
- Para mudar para MySQL (opção C): define no Render `DB_DRIVER=mysql`, `DB_HOST`,
  `DB_NAME`, `DB_USER`, `DB_PASS` do teu fornecedor de MySQL. As tabelas criam-se
  sozinhas no `install.php`.
