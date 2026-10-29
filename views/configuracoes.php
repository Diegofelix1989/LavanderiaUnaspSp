<?php
// views/configuracoes.php - Configuracoes Globais do Sistema

require_once __DIR__ . '/../includes/auth.php';
exigirPermissao('usuarios_gerenciar');

$pdo       = getDB();
$pageTitle = 'Configurações do Sistema';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!validarTokenCsrf()) { http_response_code(403); exit('Requisicao invalida.'); }
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'salvar_logo') {
        $base64Data = $_POST['logo_base64'] ?? '';
        if (preg_match('/^data:image\/(jpeg|png|webp|gif);base64,(.+)$/', $base64Data, $matches)) {
            $mime = $matches[1] === 'jpeg' ? 'image/jpeg' : 'image/' . $matches[1];
            $ext = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
            $binary = base64_decode($matches[2], true);
            if ($binary && strlen($binary) <= 2 * 1024 * 1024) {
                $config = obterConfiguracoes();
                try {
                    $nome = 'logo_' . bin2hex(random_bytes(12)) . '.' . $ext;
                    $rel = storeImage($binary, 'logos', $nome, $mime);
                    if (!salvarConfiguracao('sistema_logo', $rel)) {
                        throw new RuntimeException('Não foi possível salvar a configuração do logo.');
                    }
                    $alertType = 'success';
                    $alertMessage = 'Logo atualizado com sucesso!';
                    if (!empty($config['sistema_logo']) && $config['sistema_logo'] !== $rel) {
                        try {
                            deleteStoredImage($config['sistema_logo']);
                        } catch (Throwable $e) {
                            error_log('Falha ao remover logo antigo: ' . $e->getMessage());
                            $alertType = 'warning';
                            $alertMessage = 'O logo foi atualizado, mas não foi possível remover o arquivo antigo.';
                        }
                    }
                    definirAlerta($alertType, $alertMessage);
                } catch (Throwable $e) {
                    error_log('Falha ao salvar logo: ' . $e->getMessage());
                    definirAlerta('danger', 'Não foi possível salvar o logo. Verifique a conexão com o armazenamento Blob.');
                }
            } else { definirAlerta('danger', 'Arquivo invalido ou maior que 2MB.'); }
        } else { definirAlerta('danger', 'Dados do logo nao recebidos.'); }
        header('Location: configuracoes.php'); exit;

    } elseif ($acao === 'remover_logo') {
        $config = obterConfiguracoes();
        try {
            if (!salvarConfiguracao('sistema_logo', '')) {
                throw new RuntimeException('Não foi possível remover o logo das configurações.');
            }
            $alertType = 'info';
            $alertMessage = 'Logo removido. O icone padrao sera exibido.';
            if (!empty($config['sistema_logo'])) {
                try {
                    deleteStoredImage($config['sistema_logo']);
                } catch (Throwable $e) {
                    error_log('Falha ao remover arquivo do logo: ' . $e->getMessage());
                    $alertType = 'warning';
                    $alertMessage = 'O logo foi removido das configurações, mas o arquivo antigo não pôde ser excluído.';
                }
            }
            definirAlerta($alertType, $alertMessage);
        } catch (Throwable $e) {
            error_log('Falha ao remover logo: ' . $e->getMessage());
            definirAlerta('danger', 'Não foi possível remover o logo.');
        }
        header('Location: configuracoes.php'); exit;

    } elseif ($acao === 'salvar_textos') {
        $nome      = trim($_POST['sistema_nome'] ?? '');
        $subtitulo = trim($_POST['sistema_subtitulo'] ?? '');
        if (empty($nome)) {
            definirAlerta('danger', 'O nome do sistema nao pode estar vazio.');
            header('Location: configuracoes.php'); exit;
        }
        salvarConfiguracao('sistema_nome',      mb_substr($nome, 0, 60));
        salvarConfiguracao('sistema_subtitulo', mb_substr($subtitulo, 0, 80));
        definirAlerta('success', 'Nome e subtitulo atualizados com sucesso!');
        header('Location: configuracoes.php'); exit;

    } elseif ($acao === 'salvar_relatorio') {
        $qtdPadrao = (int)($_POST['relatorio_assinatura_qtd'] ?? 2);
        if ($qtdPadrao < 0 || $qtdPadrao > 3) $qtdPadrao = 2;
        $cargo1  = trim($_POST['relatorio_assinatura_1_cargo'] ?? '');
        $titulo2 = trim($_POST['relatorio_assinatura_2_titulo'] ?? '');
        $cargo2  = trim($_POST['relatorio_assinatura_2_cargo'] ?? '');
        $titulo3 = trim($_POST['relatorio_assinatura_3_titulo'] ?? '');
        $cargo3  = trim($_POST['relatorio_assinatura_3_cargo'] ?? '');

        salvarConfiguracao('relatorio_assinatura_qtd', (string)$qtdPadrao);
        salvarConfiguracao('relatorio_assinatura_1_cargo', mb_substr($cargo1, 0, 100));
        salvarConfiguracao('relatorio_assinatura_2_titulo', mb_substr($titulo2, 0, 100));
        salvarConfiguracao('relatorio_assinatura_2_cargo', mb_substr($cargo2, 0, 100));
        salvarConfiguracao('relatorio_assinatura_3_titulo', mb_substr($titulo3, 0, 100));
        salvarConfiguracao('relatorio_assinatura_3_cargo', mb_substr($cargo3, 0, 100));

        definirAlerta('success', 'Configurações de relatórios e assinaturas salvas com sucesso!');
        header('Location: configuracoes.php'); exit;
    }
}

require_once __DIR__ . '/../includes/header.php';
$config  = obterConfiguracoes();
$temLogo = storedImageExists($config['sistema_logo'] ?? '');
?>
<style>
.config-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: flex-start; }
@media (max-width: 760px) { .config-grid { grid-template-columns: 1fr; } }
.logo-preview-wrap {
    width: 130px; height: 130px; border-radius: 18px;
    background: #f1f5f9; border: 2px dashed #cbd5e1;
    display: flex; align-items: center; justify-content: center;
    font-size: 58px; overflow: hidden; margin: 0 auto 16px; transition: border-color 0.2s;
}
.logo-preview-wrap img { width: 100%; height: 100%; object-fit: cover; border-radius: 16px; }
#cropModal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.72); z-index: 9999; align-items: center; justify-content: center; }
#cropModal.active { display: flex; }
.crop-box { background: #fff; border-radius: 16px; padding: 24px; width: min(480px, 95vw); box-shadow: 0 25px 60px rgba(0,0,0,0.3); }
#cropCanvas { border-radius: 10px; width: 100%; max-height: 290px; cursor: move; touch-action: none; background: #0f172a; display: block; }
.crop-controls { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; align-items: center; }
.preview-brand { display: flex; align-items: center; gap: 14px; padding: 16px 20px; background: #0f172a; border-radius: 12px; margin-top: 18px; }
.preview-brand-icon { width: 46px; height: 46px; border-radius: 12px; background: linear-gradient(135deg,#3b82f6,#1d4ed8); color: white; font-size: 24px; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; }
.preview-brand-icon img { width: 100%; height: 100%; object-fit: cover; border-radius: 10px; }
.preview-brand-text h4 { color: #f8fafc; font-weight: 800; font-size: 1rem; margin-bottom: 2px; }
.preview-brand-text span { font-size: 0.72rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
</style>

<div class="page-header" style="margin-bottom: 20px;">
    <div class="page-title-group">
        <h1 style="font-size: 1.45rem; font-weight: 800; color: #0f172a;">⚙️ Configurações do Sistema</h1>
        <p style="font-size: 0.85rem; color: #64748b;">Personalize o nome, subtítulo e o logo exibido na barra lateral.</p>
    </div>
</div>

<div class="config-grid">
    <!-- Card Logo -->
    <div class="card" style="border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <div class="card-header" style="padding: 14px 18px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
            <div class="card-title" style="font-size: 0.95rem; font-weight: 700; color: #1e293b;"><span>🖼️</span> <span>Logo do Sistema</span></div>
        </div>
        <div class="card-body" style="padding: 24px; text-align: center;">
            <div class="logo-preview-wrap" id="logoPreviewWrap">
                <?php if ($temLogo): ?>
                    <img id="logoPreviewImg" src="<?= htmlspecialchars(storedImageSrc($config['sistema_logo']), ENT_QUOTES, 'UTF-8') ?>" alt="Logo">
                <?php else: ?>
                    <span id="logoEmoji">🧺</span>
                <?php endif; ?>
            </div>
            <p style="font-size: 0.79rem; color: #64748b; margin-bottom: 16px;">PNG, JPG ou GIF · máx 2MB · ideal 512×512 px</p>
            <div style="display: flex; flex-direction: column; gap: 10px; align-items: center;">
                <input type="file" id="inputLogoArquivo" accept="image/png,image/jpeg,image/jpg,image/gif" style="display:none;" onchange="aoSelecionarLogo(this)">
                <button type="button" class="btn btn-primary" onclick="document.getElementById('inputLogoArquivo').click()" style="width:100%;max-width:240px;font-weight:700;">📷 Alterar Logo</button>
                <?php if ($temLogo): ?>
                <form method="POST" action="configuracoes.php" onsubmit="return confirm('Remover logo e voltar ao ícone padrão?');" style="width:100%;max-width:240px;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="acao" value="remover_logo">
                    <button type="submit" class="btn btn-outline" style="width:100%;color:#ef4444;border-color:#fecaca;">🗑️ Remover Logo</button>
                </form>
                <?php endif; ?>
            </div>
            <!-- Prévia Sidebar -->
            <div class="preview-brand">
                <div class="preview-brand-icon" id="previewBrandIcon">
                    <?php if ($temLogo): ?>
                        <img src="<?= htmlspecialchars(storedImageSrc($config['sistema_logo']), ENT_QUOTES, 'UTF-8') ?>" alt="">
                    <?php else: ?>
                        <span id="previewBrandEmoji">🧺</span>
                    <?php endif; ?>
                </div>
                <div class="preview-brand-text">
                    <h4 id="previewNomeSistema"><?= htmlspecialchars($config['sistema_nome']) ?></h4>
                    <span id="previewSubtituloSistema"><?= htmlspecialchars($config['sistema_subtitulo']) ?></span>
                </div>
            </div>
            <p style="font-size: 0.72rem; color: #94a3b8; margin-top: 8px;">Prévia da barra lateral</p>
        </div>
    </div>

    <!-- Card Textos -->
    <div class="card" style="border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <div class="card-header" style="padding: 14px 18px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
            <div class="card-title" style="font-size: 0.95rem; font-weight: 700; color: #1e293b;"><span>✏️</span> <span>Nome e Identificação</span></div>
        </div>
        <div class="card-body" style="padding: 24px;">
            <form method="POST" action="configuracoes.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="acao" value="salvar_textos">

                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="form-label" for="sistema_nome" style="font-size: 0.85rem; font-weight: 700;">Nome do Sistema *</label>
                    <input type="text" name="sistema_nome" id="sistema_nome" class="form-control"
                           value="<?= htmlspecialchars($config['sistema_nome']) ?>" maxlength="60" required
                           placeholder="Ex: Lavanderia, Lavanderia Central..."
                           oninput="document.getElementById('previewNomeSistema').textContent = this.value || 'Sistema'">
                    <small style="color:#64748b;font-size:0.75rem;">Exibido na sidebar e na aba do navegador. Máx. 60 caracteres.</small>
                </div>

                <div class="form-group" style="margin-bottom: 24px;">
                    <label class="form-label" for="sistema_subtitulo" style="font-size: 0.85rem; font-weight: 700;">Subtítulo / Slogan</label>
                    <input type="text" name="sistema_subtitulo" id="sistema_subtitulo" class="form-control"
                           value="<?= htmlspecialchars($config['sistema_subtitulo']) ?>" maxlength="80"
                           placeholder="Ex: Sistema Integrado, Gestão de Roupas..."
                           oninput="document.getElementById('previewSubtituloSistema').textContent = this.value">
                    <small style="color:#64748b;font-size:0.75rem;">Texto menor abaixo do nome. Máx. 80 caracteres.</small>
                </div>

                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:20px;">
                    <div style="font-size:0.8rem;font-weight:700;color:#475569;margin-bottom:8px;">ℹ️ Onde aparece:</div>
                    <ul style="font-size:0.78rem;color:#64748b;padding-left:16px;line-height:1.8;margin:0;">
                        <li>Barra lateral – nome e subtítulo em destaque</li>
                        <li>Aba do navegador (título da página)</li>
                        <li>Barra mobile e cabeçalho dos relatórios</li>
                    </ul>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%;font-weight:700;height:42px;">💾 Salvar Nome e Subtítulo</button>
            </form>
    </div>
</div>

<!-- Card Relatórios e Assinaturas -->
<div class="card" style="margin-top: 20px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
    <div class="card-header" style="padding: 14px 18px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
        <div class="card-title" style="font-size: 0.95rem; font-weight: 700; color: #1e293b;">
            <span>✍️</span> <span>Padrões de Assinaturas e Emissão nos Relatórios</span>
        </div>
    </div>
    <div class="card-body" style="padding: 24px;">
        <form method="POST" action="configuracoes.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="acao" value="salvar_relatorio">

            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label class="form-label" for="relatorio_assinatura_qtd" style="font-size: 0.85rem; font-weight: 700;">Quantidade de Assinaturas Padrão</label>
                    <select name="relatorio_assinatura_qtd" id="relatorio_assinatura_qtd" class="form-control" style="height: 42px;">
                        <option value="2" <?= ($config['relatorio_assinatura_qtd'] ?? '2') === '2' ? 'selected' : '' ?>>2 Assinaturas (Lavanderia + Setor)</option>
                        <option value="1" <?= ($config['relatorio_assinatura_qtd'] ?? '') === '1' ? 'selected' : '' ?>>1 Assinatura (Somente Lavanderia)</option>
                        <option value="3" <?= ($config['relatorio_assinatura_qtd'] ?? '') === '3' ? 'selected' : '' ?>>3 Assinaturas (+ Supervisão / Controladoria)</option>
                        <option value="0" <?= ($config['relatorio_assinatura_qtd'] ?? '') === '0' ? 'selected' : '' ?>>0 (Sem linhas de assinatura)</option>
                    </select>
                    <small style="color: #64748b; font-size: 0.75rem;">Você também poderá alterar em tempo real antes de imprimir.</small>
                </div>

                <div class="form-group">
                    <label class="form-label" for="relatorio_assinatura_1_cargo" style="font-size: 0.85rem; font-weight: 700;">Assinatura 1: Subtítulo / Descrição</label>
                    <input type="text" name="relatorio_assinatura_1_cargo" id="relatorio_assinatura_1_cargo" class="form-control"
                           value="<?= htmlspecialchars($config['relatorio_assinatura_1_cargo'] ?? 'Responsável pela Lavanderia (Emissão & Conferência)') ?>" maxlength="100">
                    <small style="color: #64748b; font-size: 0.75rem;">O nome exibido é o do usuário logado que gerou o relatório.</small>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px;">
                    <div style="font-size: 0.82rem; font-weight: 700; color: #1e293b; margin-bottom: 10px;">Assinatura 2 (Setor / Centro de Custo)</div>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label class="form-label" style="font-size: 0.78rem;">Título:</label>
                        <input type="text" name="relatorio_assinatura_2_titulo" class="form-control"
                               value="<?= htmlspecialchars($config['relatorio_assinatura_2_titulo'] ?? 'Gestor / Responsável pelo Setor') ?>" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-size: 0.78rem;">Subtítulo:</label>
                        <input type="text" name="relatorio_assinatura_2_cargo" class="form-control"
                               value="<?= htmlspecialchars($config['relatorio_assinatura_2_cargo'] ?? 'Centro de Custo (De Acordo & Recebimento)') ?>" maxlength="100">
                    </div>
                </div>

                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px;">
                    <div style="font-size: 0.82rem; font-weight: 700; color: #1e293b; margin-bottom: 10px;">Assinatura 3 (Supervisão / Auditoria)</div>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label class="form-label" style="font-size: 0.78rem;">Título:</label>
                        <input type="text" name="relatorio_assinatura_3_titulo" class="form-control"
                               value="<?= htmlspecialchars($config['relatorio_assinatura_3_titulo'] ?? 'Supervisão Geral / Controladoria') ?>" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-size: 0.78rem;">Subtítulo:</label>
                        <input type="text" name="relatorio_assinatura_3_cargo" class="form-control"
                               value="<?= htmlspecialchars($config['relatorio_assinatura_3_cargo'] ?? 'Visto de Auditoria Interna & Rateio') ?>" maxlength="100">
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="font-weight: 700; height: 42px;">💾 Salvar Padrões de Assinatura</button>
        </form>
    </div>
</div>

<!-- Modal Crop -->
<div id="cropModal">
    <div class="crop-box">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <h3 style="font-size:1rem;font-weight:800;color:#0f172a;margin:0;">Ajustar Logo</h3>
            <button type="button" onclick="fecharCropModal()" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#64748b;">✕</button>
        </div>
        <canvas id="cropCanvas" width="432" height="280"></canvas>
        <div class="crop-controls">
            <button type="button" class="btn btn-outline btn-sm" onclick="zoomCrop(0.1)">🔍+</button>
            <button type="button" class="btn btn-outline btn-sm" onclick="zoomCrop(-0.1)">🔍−</button>
            <button type="button" class="btn btn-outline btn-sm" onclick="resetCrop()">↺ Reset</button>
            <span style="flex:1;"></span>
            <button type="button" class="btn btn-outline btn-sm" onclick="fecharCropModal()">Cancelar</button>
            <button type="button" class="btn btn-primary btn-sm" onclick="confirmarLogo()">✅ Confirmar</button>
        </div>
        <p style="font-size:0.73rem;color:#94a3b8;margin-top:10px;text-align:center;">Arraste para reposicionar · Scroll ou botões para zoom</p>
    </div>
</div>

<form id="formSalvarLogo" method="POST" action="configuracoes.php" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="acao" value="salvar_logo">
    <input type="hidden" name="logo_base64" id="hiddenLogoBase64">
</form>

<script>
let cropImg = null, cropCanvas = null, cropCtx = null;
let imgScale = 1, imgX = 0, imgY = 0;
let isDragging = false, lastX = 0, lastY = 0;

function aoSelecionarLogo(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    if (file.size > 2 * 1024 * 1024) { alert('Arquivo maior que 2MB. Escolha uma imagem menor.'); return; }
    const reader = new FileReader();
    reader.onload = e => abrirCropModal(e.target.result);
    reader.readAsDataURL(file);
}

function abrirCropModal(src) {
    cropCanvas = document.getElementById('cropCanvas');
    cropCtx = cropCanvas.getContext('2d');
    cropImg = new Image();
    cropImg.onload = () => { resetCrop(); document.getElementById('cropModal').classList.add('active'); };
    cropImg.src = src;
}

function resetCrop() {
    if (!cropImg || !cropCanvas) return;
    const cw = cropCanvas.width, ch = cropCanvas.height;
    imgScale = Math.min(cw / cropImg.width, ch / cropImg.height, 1.4);
    imgX = (cw - cropImg.width * imgScale) / 2;
    imgY = (ch - cropImg.height * imgScale) / 2;
    desenharCrop();
}

function desenharCrop() {
    if (!cropCtx || !cropImg) return;
    cropCtx.clearRect(0, 0, cropCanvas.width, cropCanvas.height);
    cropCtx.fillStyle = '#0f172a';
    cropCtx.fillRect(0, 0, cropCanvas.width, cropCanvas.height);
    cropCtx.drawImage(cropImg, imgX, imgY, cropImg.width * imgScale, cropImg.height * imgScale);
}

function zoomCrop(delta) {
    const cx = cropCanvas.width / 2, cy = cropCanvas.height / 2;
    const old = imgScale;
    imgScale = Math.min(Math.max(0.08, imgScale + delta), 6);
    imgX = cx - (cx - imgX) * (imgScale / old);
    imgY = cy - (cy - imgY) * (imgScale / old);
    desenharCrop();
}

function fecharCropModal() {
    document.getElementById('cropModal').classList.remove('active');
    document.getElementById('inputLogoArquivo').value = '';
}

function confirmarLogo() {
    const exp = document.createElement('canvas');
    exp.width = 512; exp.height = 512;
    const ctx = exp.getContext('2d');
    const r = 512 / cropCanvas.width;
    ctx.fillStyle = '#f1f5f9';
    ctx.fillRect(0, 0, 512, 512);
    ctx.drawImage(cropImg, imgX * r, imgY * r, cropImg.width * imgScale * r, cropImg.height * imgScale * r);
    const dataURL = exp.toDataURL('image/png');
    const wrap = document.getElementById('logoPreviewWrap');
    wrap.innerHTML = '<img src="' + dataURL + '" style="width:100%;height:100%;object-fit:cover;border-radius:16px;">';
    document.getElementById('previewBrandIcon').innerHTML = '<img src="' + dataURL + '" style="width:100%;height:100%;object-fit:cover;border-radius:10px;">';
    document.getElementById('hiddenLogoBase64').value = dataURL;
    document.getElementById('formSalvarLogo').submit();
}

document.addEventListener('DOMContentLoaded', function() {
    const canvas = document.getElementById('cropCanvas');
    canvas.addEventListener('mousedown',  e => { isDragging = true; lastX = e.offsetX; lastY = e.offsetY; });
    canvas.addEventListener('mousemove',  e => {
        if (!isDragging) return;
        imgX += e.offsetX - lastX; imgY += e.offsetY - lastY;
        lastX = e.offsetX; lastY = e.offsetY;
        desenharCrop();
    });
    canvas.addEventListener('mouseup',   () => isDragging = false);
    canvas.addEventListener('mouseleave',() => isDragging = false);
    canvas.addEventListener('wheel', e => { e.preventDefault(); zoomCrop(e.deltaY < 0 ? 0.1 : -0.1); }, { passive: false });
    let lx = 0, ly = 0;
    canvas.addEventListener('touchstart', e => { lx = e.touches[0].clientX; ly = e.touches[0].clientY; });
    canvas.addEventListener('touchmove', e => {
        e.preventDefault();
        imgX += e.touches[0].clientX - lx; imgY += e.touches[0].clientY - ly;
        lx = e.touches[0].clientX; ly = e.touches[0].clientY;
        desenharCrop();
    }, { passive: false });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
