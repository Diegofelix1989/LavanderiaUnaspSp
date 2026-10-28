// assets/js/app.js - Interatividade, Cálculos Dinâmicos e Controles

document.addEventListener('DOMContentLoaded', function () {
    // 0. Alternância de Tema Claro/Escuro (persistida no usuário via tema.php)
    const themeForms = document.querySelectorAll('.js-theme-form');

    function aplicarTema(tema) {
        const escuro = tema === 'escuro';
        const temaHtml = escuro ? 'dark' : 'light';

        document.documentElement.setAttribute('data-theme', temaHtml);

        document.querySelectorAll('.theme-toggle-label').forEach(el => {
            el.textContent = escuro ? 'Tema claro' : 'Tema escuro';
        });
        document.querySelectorAll('.theme-toggle-icon').forEach(el => {
            el.textContent = escuro ? '☀️' : '🌙';
        });
        document.querySelectorAll('.theme-toggle').forEach(el => {
            const rotulo = escuro ? 'Ativar tema claro' : 'Ativar tema escuro';
            el.setAttribute('aria-label', rotulo);
            el.setAttribute('title', rotulo);
        });

        const btnMobile = document.getElementById('btnThemeMobile');
        if (btnMobile) {
            const rotulo = escuro ? 'Ativar tema claro' : 'Ativar tema escuro';
            btnMobile.setAttribute('aria-label', rotulo);
            btnMobile.setAttribute('title', rotulo);
            const icone = btnMobile.querySelector('span');
            if (icone) icone.textContent = escuro ? '☀️' : '🌙';
        }

        const metaColor = document.querySelector('meta[name="theme-color"]');
        if (metaColor) metaColor.setAttribute('content', escuro ? '#0b1120' : '#f4f6fa');
        const metaScheme = document.querySelector('meta[name="color-scheme"]');
        if (metaScheme) metaScheme.setAttribute('content', escuro ? 'dark' : 'light');

        themeForms.forEach(form => {
            const input = form.querySelector('input[name="tema"]');
            if (input) input.value = escuro ? 'claro' : 'escuro';
        });
    }

    themeForms.forEach(form => {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            const botao = form.querySelector('button');
            if (botao) botao.disabled = true;

            try {
                const resposta = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                });

                if (!resposta.ok) throw new Error('Falha ao salvar o tema');
                const dados = await resposta.json();
                if (!dados.ok) throw new Error(dados.mensagem || 'Falha ao salvar o tema');

                aplicarTema(dados.tema);
            } catch (erro) {
                // Fallback: envia o formulário tradicionalmente caso o AJAX falhe
                form.submit();
            } finally {
                if (botao) botao.disabled = false;
            }
        });
    });

    const btnThemeMobile = document.getElementById('btnThemeMobile');
    if (btnThemeMobile) {
        btnThemeMobile.addEventListener('click', function () {
            const formTema = document.querySelector('.js-theme-form');
            if (formTema) formTema.requestSubmit();
        });
    }

    // 1. Controle do Submenu Cadastros (Expansão/Colapso)
    const submenuWrappers = document.querySelectorAll('.submenu-wrapper');
    submenuWrappers.forEach(wrapper => {
        const toggleBtn = wrapper.querySelector('.submenu-toggle');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function (e) {
                e.preventDefault();
                wrapper.classList.toggle('open');
            });
        }
    });

    // 2. Controle do Menu Mobile
    const btnMenuToggle = document.getElementById('btnMenuToggle');
    const sidebar = document.querySelector('.sidebar');
    if (btnMenuToggle && sidebar) {
        btnMenuToggle.addEventListener('click', function () {
            sidebar.classList.toggle('mobile-open');
        });

        // Fechar ao clicar fora no mobile
        document.addEventListener('click', function (e) {
            if (window.innerWidth <= 900 && 
                !sidebar.contains(e.target) && 
                !btnMenuToggle.contains(e.target) && 
                sidebar.classList.contains('mobile-open')) {
                sidebar.classList.remove('mobile-open');
            }
        });
    }

    // 3. Cálculo Automático em Lançamentos (Preço Unitário da Peça x Quantidade = Total)
    const selectProduto = document.getElementById('select_produto');
    const inputValorUnitario = document.getElementById('input_valor_unitario');
    const inputQuantidade = document.getElementById('input_quantidade');
    const inputValorTotal = document.getElementById('input_valor_total');

    function calcularTotalLancamento() {
        if (!inputValorUnitario || !inputQuantidade || !inputValorTotal) return;
        const qtd = parseFloat(inputQuantidade.value) || 0;
        const unitario = parseFloat(inputValorUnitario.value) || 0;
        const total = (qtd * unitario).toFixed(2);
        inputValorTotal.value = total;
    }

    if (selectProduto && inputValorUnitario) {
        selectProduto.addEventListener('change', function () {
            const selectedOption = selectProduto.options[selectProduto.selectedIndex];
            const precoSugerido = selectedOption.getAttribute('data-preco');
            if (precoSugerido !== null && precoSugerido !== '') {
                inputValorUnitario.value = parseFloat(precoSugerido).toFixed(2);
            }
            calcularTotalLancamento();
        });
    }

    if (inputQuantidade) {
        inputQuantidade.addEventListener('input', calcularTotalLancamento);
    }
    if (inputValorUnitario) {
        inputValorUnitario.addEventListener('input', calcularTotalLancamento);
    }

    // 4. Funções de Abertura e Fechamento de Modais
    window.abrirModal = function (modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('active');
        }
    };

    window.fecharModal = function (modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('active');
        }
    };

    // Fechar modal ao clicar fora ou com a tecla ESC
    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                modal.classList.remove('active');
            }
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.active').forEach(modal => {
                modal.classList.remove('active');
            });
        }
    });

    // 5. Alternador de Abas (Tabs) para Lixeira
    window.alternarAba = function (abaNome) {
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.toggle('active', btn.getAttribute('data-tab') === abaNome);
        });
        document.querySelectorAll('.tab-content').forEach(content => {
            content.style.display = content.id === 'tab-' + abaNome ? 'block' : 'none';
        });
    };

    // 6. Confirmação estilizada para exclusão permanente
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', function (e) {
            const msg = el.getAttribute('data-confirm') || 'Tem certeza que deseja prosseguir com esta ação?';
            if (!confirm(msg)) {
                e.preventDefault();
            }
        });
    });
});
