# Consignações 3D — versão PHP + Banco de Dados

Gestão de consignações (impressões 3D) com clientes, stock, acertos de visita, assinatura digital do cliente e recibos em duas vias. Interface responsiva, pensada para o telemóvel.

## Funcionalidades

- **Login com sessão** — palavra-passe com hash seguro (`password_hash`), proteção CSRF em todos os formulários.
- **Banco de dados real** — MySQL ou SQLite via PDO (prepared statements em todas as consultas).
- **Clientes** — ficha com NIF, telefone, email, comissão própria e histórico.
- **Consignações** — entrega inicial de produtos com assinatura do cliente no ecrã.
- **Acertos de visita** — registo do que vendeu, comissão calculada, novo stock, tudo assinado.
- **Recibos** — duas vias (cliente/fornecedor), impressão/PDF pelo browser, envio por email.
- **Perfil** — logótipo, marca, dados do fornecedor e comissão padrão.
- **Tema claro/escuro automático** conforme o sistema.

## Como rodar

### Opção A — teste rápido (SQLite, zero configuração)

O `config.php` já vem com `DB_DRIVER = 'sqlite'`. Basta ter PHP 8+:

```bash
# macOS (instalar PHP via Homebrew, se necessário)
brew install php

cd consignacoes-php
php -S 0.0.0.0:8000
```

Abra `http://localhost:8000/install.php`, crie a sua conta e pronto.
O banco fica em `data/consignacoes.sqlite`.

> Para aceder pelo celular na mesma rede Wi-Fi: `http://IP-DO-COMPUTADOR:8000`

### Opção B — produção (MySQL, ex.: cPanel/hospedagem)

1. Crie um banco MySQL e edite o `config.php`:
   ```php
   define('DB_DRIVER', 'mysql');
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'consignacoes');
   define('DB_USER', 'seu_usuario');
   define('DB_PASS', 'sua_senha');
   ```
2. Envie a pasta para o servidor (ex.: `public_html/consignacoes/`).
3. Abra `https://seusite.com/consignacoes/install.php` — as tabelas são criadas automaticamente e a sua conta de acesso é configurada.

### MAMP / XAMPP

Copie a pasta para `htdocs/`, ligue o Apache (+ MySQL se for usar a opção B) e abra `http://localhost/consignacoes-php/install.php`.

## Estrutura

```
consignacoes-php/
├── config.php              ← configuração (driver do banco, credenciais)
├── install.php             ← instalador: cria tabelas + 1º utilizador
├── login.php / logout.php
├── index.php               ← lista de clientes + estatísticas
├── perfil.php              ← os meus dados / logótipo / comissão padrão
├── cliente_novo.php        ← novo cliente + produtos + assinatura
├── cliente.php             ← ficha do cliente (stock, totais, histórico)
├── acerto.php              ← registar visita: vendas, novo stock, assinatura
├── recibo.php              ← recibo em 2 vias, imprimir/PDF, email
├── excluir_cliente.php
├── includes/
│   ├── db.php              ← ligação PDO (MySQL/SQLite)
│   ├── auth.php            ← sessão, login obrigatório, CSRF
│   ├── functions.php       ← formatação, comissões, validações
│   ├── layout_header.php / layout_footer.php
│   └── sig_overlay.php     ← ecrã de assinatura partilhado
├── assets/
│   ├── css/style.css
│   └── js/signature.js     ← canvas de assinatura
└── data/                   ← banco SQLite (quando DB_DRIVER = 'sqlite')
```

## Banco de dados

| Tabela           | Conteúdo                                                       |
|------------------|----------------------------------------------------------------|
| `users`          | conta de acesso + dados do fornecedor (marca, logo, comissão)  |
| `clients`        | clientes, comissão própria opcional, última visita             |
| `products`       | stock atual em consignação por cliente                         |
| `movements`      | histórico: entregas e acertos, totais, assinatura, nº de recibo|
| `movement_items` | linhas de cada movimento (entregue / vendido / stock)          |

## Segurança incluída

- Palavras-passe com `password_hash()` / `password_verify()`
- Tokens CSRF em todos os POST
- Prepared statements (sem SQL injection)
- Escape de HTML em toda a saída (sem XSS)
- Cada utilizador só vê os seus próprios clientes
