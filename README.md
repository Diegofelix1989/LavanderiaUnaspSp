# 🧺 Sistema de Lavanderia (PHP + SQLite)

Sistema web completo para controle de lavanderia por departamentos e peças, desenvolvido em **PHP** com banco de dados **SQLite**, controle de sessão em banco e retenção na lixeira por 60 dias.

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

### 4. 🗄️ Controle de Sessão no Banco de Dados SQLite
* As sessões dos usuários são gravadas na tabela `sessoes` no SQLite, rastreando token seguro, IP do cliente, navegador (User Agent), data do último acesso e expiração.
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
│   ├── database.php        # Conexão SQLite, tabelas e auto-seed inicial
│   └── session.php         # Controle de sessão persistida no SQLite
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
│   └── perfil.php          # Perfil e monitor de sessões SQLite
├── database/
│   └── lavanderia.db       # Banco de dados local SQLite
├── iniciar_servidor.bat    # Executável para iniciar no Windows
└── index.php               # Roteamento inicial
```
