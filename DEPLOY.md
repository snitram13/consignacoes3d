# Pôr no ar: Render (app) + Supabase (base de dados)

Arquitetura: o **Render** serve o PHP (grátis) e o **Supabase** guarda os dados em
PostgreSQL (grátis e permanente). O link final é **um só** e funciona no **computador
e no telemóvel** de qualquer lado: `https://consignacoes-3d.onrender.com`.

A app já está pronta (Docker + render.yaml + suporte a Postgres testado).

---

## 1. Base de dados no Supabase
1. Cria conta em https://supabase.com → **New project** (escolhe uma região da Europa,
   ex.: *Frankfurt*). Guarda a **Database Password** que defines aqui.
2. No projeto: **Connect** (ou Settings → Database) → escolhe **"Session pooler"**
   (diz *IPv4 compatible*, porta **5432** — é a que funciona no Render).
3. Anota destes valores (vais pô-los no Render como variáveis separadas):
   - **host** → ex.: `aws-1-eu-central-1.pooler.supabase.com`
   - **port** → `5432`
   - **database** → `postgres`
   - **user** → `postgres.<ref-do-projeto>`
   - **password** → a password do passo 1

> As tabelas são criadas sozinhas no primeiro acesso ao `install.php`. Não precisas de SQL.

## 2. Código no GitHub
```bash
cd "consignacoes-php"          # já tem repositório Git com commits
git remote add origin https://github.com/<o-teu-utilizador>/consignacoes-3d.git
git push -u origin main
```
(Cria primeiro o repositório vazio em github.com → New repository → "consignacoes-3d".)

## 3. Serviço no Render
1. https://render.com → regista-te (podes entrar com o GitHub)
2. **New + → Blueprint** → escolhe o repositório `consignacoes-3d`
3. O Render lê o `render.yaml` e pede os segredos (`sync: false`). Define:
   - **DB_HOST** = host do Supabase (ex.: `aws-1-eu-central-1.pooler.supabase.com`)
   - **DB_USER** = `postgres.<ref-do-projeto>`
   - **DB_PASS** = a password do Supabase (crua, tal como é)
   - **SMTP_PASS** = a tua palavra-passe de app do Gmail (de myaccount.google.com/apppasswords)
   (DB_DRIVER, DB_PORT=5432, DB_NAME já vêm preenchidos do `render.yaml`.)
4. **Apply / Create** → espera o build (~3-5 min)

## 4. Primeiro arranque
- No fim tens o link `https://...onrender.com`
- Abre `https://...onrender.com/install.php` → cria a tua conta de acesso
- Pronto! Esse link serve para o **computador e o telemóvel**.

---

## Notas
- **Grátis no Render**: o serviço "adormece" após 15 min sem uso; o 1.º acesso seguinte
  demora ~1 min a acordar. (Plano pago fica sempre ligado.)
- **Supabase grátis**: o projeto pausa após ~1 semana sem qualquer atividade; se isso
  acontecer, basta reativá-lo no painel.
- Segredos (`config.local.php`, password do Gmail, `DATABASE_URL`) **nunca** vão para o
  Git — ficam só em variáveis de ambiente.
- Localmente continuas a usar SQLite (via `config.local.php`), sem precisares do Supabase.
