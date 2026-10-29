# 🧺 Sistema de Lavanderia (PHP + MySQL)

Sistema web completo para controle de lavanderia por departamentos e peças, desenvolvido em **PHP** com banco de dados **MySQL**, controle de sessão em banco e retenção na lixeira por 60 dias.

---

## Configuração do MySQL

1. Preencha `DB_HOST`, `DB_NAME`, `DB_USER` e `DB_PASSWORD` no arquivo `.env` local, que é ignorado pelo Git.
2. Como alternativa, copie `config/database.local.example.php` para `config/database.local.php` e preencha as credenciais; esse arquivo também é ignorado pelo Git.
3. Habilite a extensão `pdo_mysql` do PHP e confirme que as tabelas do `database/mysql_schema.sql` já existem no banco.

O aplicativo prioriza as variáveis de ambiente do servidor, depois o arquivo `.env` local e, por fim, `config/database.local.php`. O `.env` não é enviado à Vercel; cadastre as mesmas variáveis nas configurações do projeto. O aplicativo conecta ao MySQL configurado e não cria nem altera tabelas automaticamente.

### Uploads no Vercel Blob

1. Crie um Blob Store com acesso **Public** e conecte-o ao projeto da lavanderia nos ambientes desejados. Um store privado não pode ser convertido em público.
2. A conexão deve disponibilizar `BLOB_READ_WRITE_TOKEN` ao projeto Vercel. O token é usado somente entre funções server-side; nunca o exponha no navegador.
3. Faça deploy do `package.json` e do `package-lock.json`; a função Node `api/blob.js` recebe os uploads e grava as imagens no Blob. O PHP mantém apenas a URL pública no MySQL.

Uploads locais continuam sendo gravados em `uploads/`. Na Vercel, avatares e logotipos são enviados ao Blob público e continuam disponíveis entre execuções.

### Deploy na Vercel

1. Importe este repositório na Vercel; o `vercel.json` configura o runtime PHP e encaminha as páginas para `api/index.php`.
2. Execute uma vez o arquivo `database/mysql_migracao_vercel.sql` no banco remoto para habilitar sessões PHP compartilhadas entre funções.
3. Em **Settings → Environment Variables**, configure `DB_HOST`, `DB_NAME`, `DB_USER` e `DB_PASSWORD` para Production, Preview e/ou Development.
4. Faça um novo deploy após salvar as variáveis.

O roteador publica as páginas em `views/` e os arquivos estáticos do projeto em `assets/`, bloqueando acesso direto às pastas internas. A pasta `uploads/` é excluída do deploy e o filesystem das funções da Vercel não é armazenamento persistente; avatares e logotipos enviados pela aplicação precisam de um serviço de armazenamento externo para funcionar de forma persistente.

---

## 🚀 Como Iniciar o Sistema

### Opção 1: Via executável em lote (Mais fácil no Windows)
Dê um duplo clique no arquivo [`iniciar_servidor.bat`](file:///c:/Users/Diego%20Felix/OneDrive/%C3%81rea%20de%20Trabalho/lavanderia/iniciar_servidor.bat). Ele iniciará o servidor PHP embutido e abrirá o navegador em `http://localhost:8000`.

### Opção 2: Via Terminal
```bash
php -S localhost:8000
```
Depois acesse no seu navegador: `http://localhost:8000`

---

## 🔑 Credenciais de Acesso Padrão

* **E-mail:** `admin@lavanderia.com`
* **Senha:** `admin123`
*(Na tela de login há um botão "Preencher Automaticamente" para agilizar o acesso).*

---

## 📋 Funcionalidades Implementadas

### 1. 📂 Menu Lateral Retrátil (Sidebar)
* **Perfil do Usuário:** Foto/Avatar, Nome, Cargo e Indicador de Status Online. Clicar leva para edição do perfil.
* **📋 Lançamentos:** Tela principal de movimentações.
* **📁 Cadastros (Submenu Expansível):**
  * 🏢 **Departamentos:** Gestão de setores (*Unasp Store, Ensino Médio, Biblioteca, ADM, etc.*).
  * 🏷️ **Produtos / Peças:** Cadastro de peças com valor unitário padrão (*Toalha de mesa, Tatame, Beca, etc.*).
* **📊 Relatórios:** Relatório hierárquico analítico.
* **🗑️ Lixeira:** Com indicador de quantidade de itens.
* **🚪 Encerrar Sessão:** Logout com encerramento de sessão no banco.

### 2. 🧺 Lançamentos Diários (Imagem 1)
* Tabela com colunas: **Data**, **Departamentos**, **Peças**, **Qtde**, **Valor**, **Total** e **Ações**.
* Ao selecionar a peça no formulário, o valor unitário padrão é preenchido automaticamente, calculando o Total em tempo real (`Quantidade × Valor`).
* Permite edição e exclusão (envio para a Lixeira).

### 3. 📊 Relatórios da Lavanderia (3 Modelos Disponíveis)
O sistema conta com **3 visões analíticas completas**, acessíveis pelas abas superiores ou pelo submenu lateral:
* **Modelo 1: Geral Detalhado por Departamento (Imagem 2):**
  * Agrupamento visual por **Departamento**, com exibição detalhada das peças lavadas, datas, quantidades, valores e subtotal por setor (ex: `Acarte Total: R$ 1.040,00`).
* **Modelo 2: Consolidado por Peças (Ranking & Volume):**
  * Ranking das peças mais lavadas (1º, 2º, 3º lugar), quantidade total de cada item, preço médio praticado, receita gerada, barra de progresso com a **% de participação** e lista dos setores solicitantes.
* **Modelo 3: Resumo por Centro de Custo (Demonstrativo Financeiro):**
  * Quadro sintético executivo para prestação de contas: total de lançamentos, variedade de peças, total de peças, custo total, ticket médio por peça e % de impacto no faturamento global.
* **Recursos dos Relatórios:**
  * Filtro por **Mês/Ano** e **Departamento**.
  * **Exportação para CSV:** Gera o arquivo de planilha compatível com Excel referente ao modelo ativo na tela.
  * **Impressão / PDF:** Layout profissional limpo via `window.print()`.

### 4. 🗄️ Controle de Sessão no Banco de Dados MySQL
* As sessões de autenticação são gravadas na tabela `sessoes`; os dados de sessão do PHP (incluindo o token CSRF) são gravados em `sessoes_php`, permitindo que instâncias serverless compartilhem o estado.
* Na tela de perfil, o usuário pode ver todos os dispositivos conectados e encerrar outras sessões remotamente.

### 5. 🗑️ Lixeira com Retenção de 60 Dias
* Itens excluídos recebem a data de exclusão (`deleted_at`).
* A tela da lixeira exibe os itens com contagem regressiva de **dias restantes de tolerância** (de 60 dias).
* Permite **Restaurar** o registro para o sistema ou **Excluir Definitivamente**.
* O sistema possui rotina automática que expurga dados que estiverem na lixeira há mais de 60 dias.

---

## 📁 Estrutura de Diretórios

```text
lavanderia/
├── config/
│   ├── database.php        # Conexão MySQL e auto-seed inicial
│   └── session.php         # Controle de sessão persistida no MySQL
├── includes/
│   ├── auth.php            # Middleware de autenticação
│   ├── header.php          # Topbar, alertas e layout
│   ├── sidebar.php         # Menu lateral responsivo com submenus
│   └── footer.php          # Scripts JS e encerramento de tags
├── assets/
│   ├── css/style.css       # Estilos modernos, responsivos e modo impressão
│   └── js/app.js           # Submenus, cálculos em tempo real e modais
├── views/
│   ├── login.php           # Tela de login
│   ├── logout.php          # Encerramento de sessão
│   ├── lancamentos.php     # Registro e tabela diária de lavagens
│   ├── departamentos.php   # Cadastro de departamentos
│   ├── produtos.php        # Cadastro de peças e valores
│   ├── relatorios.php      # Relatório por departamento conforme imagem 2
│   ├── lixeira.php         # Lixeira com política de 60 dias
│   └── perfil.php          # Perfil e monitor de sessões MySQL
├── database/
│   ├── mysql_schema.sql    # Schema do banco MySQL
│   └── mysql_migracao_local.sql
├── iniciar_servidor.bat    # Executável para iniciar no Windows
└── index.php               # Roteamento inicial
```
