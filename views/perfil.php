<?php
// views/perfil.php - Perfil do Usuário, Foto com Ajuste Interativo e Sessões Ativas

require_once __DIR__ . '/../includes/auth.php';
$pdo = getDB();

$pageTitle = 'Meu Perfil';

// Processamento de Ações POST
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!validarTokenCsrf()) {
        http_response_code(403);
        exit('Requisição inválida.');
    }

    $acao = $_POST['acao'] ?? '';

    // ── Salvar Foto Recortada / Ajustada ──────────────────────────────────
    if ($acao === 'salvar_avatar_crop') {
        $base64Data = $_POST['avatar_base64'] ?? '';
        
        if (preg_match('/^data:image\/(jpeg|png|webp);base64,(.+)$/', $base64Data, $matches)) {
            $ext = ($matches[1] === 'png') ? 'png' : 'jpg';
            $binary = base64_decode($matches[2]);

            if ($binary && strlen($binary) <= 5 * 1024 * 1024) {
                $dirAvatars = __DIR__ . '/../uploads/avatars';
                if (!is_dir($dirAvatars)) {
                    mkdir($dirAvatars, 0755, true);
                }

                $nomeArquivo = 'avatar_' . $currentUser['id'] . '_' . time() . '.' . $ext;
                $caminhoRelativo = 'uploads/avatars/' . $nomeArquivo;
                $caminhoAbsoluto = $dirAvatars . '/' . $nomeArquivo;

                if (file_put_contents($caminhoAbsoluto, $binary)) {
                    // Remove avatar anterior se foi upload customizado
                    if (!empty($currentUser['avatar']) && strpos($currentUser['avatar'], 'uploads/avatars/') !== false) {
                        $antigo = __DIR__ . '/../' . $currentUser['avatar'];
                        if (file_exists($antigo)) {
                            @unlink($antigo);
                        }
                    }

                    $stmt = $pdo->prepare("UPDATE usuarios SET avatar = ? WHERE id = ?");
                    $stmt->execute([$caminhoRelativo, $currentUser['id']]);

                    // Atualiza sessão
                    $_SESSION['usuario']['avatar'] = $caminhoRelativo;
                    $currentUser['avatar'] = $caminhoRelativo;

                    definirAlerta('success', 'Foto de perfil ajustada e atualizada com sucesso!');
                } else {
                    definirAlerta('danger', 'Não foi possível salvar o arquivo da imagem.');
                }
            } else {
                definirAlerta('danger', 'Arquivo de imagem inválido ou tamanho excedido.');
            }
        } else {
            definirAlerta('danger', 'Dados da imagem não foram recebidos corretamente.');
        }

        header('Location: perfil.php');
        exit;

    // ── Remover Foto e Voltar para Iniciais ───────────────────────────────
    } elseif ($acao === 'remover_avatar') {
        if (!empty($currentUser['avatar']) && strpos($currentUser['avatar'], 'uploads/avatars/') !== false) {
            $antigo = __DIR__ . '/../' . $currentUser['avatar'];
            if (file_exists($antigo)) {
                @unlink($antigo);
            }
        }

        $stmt = $pdo->prepare("UPDATE usuarios SET avatar = '' WHERE id = ?");
        $stmt->execute([$currentUser['id']]);

        $_SESSION['usuario']['avatar'] = '';
        $currentUser['avatar'] = '';

        definirAlerta('info', 'Foto de perfil removida. O sistema voltará a exibir seu avatar com as iniciais.');
        header('Location: perfil.php');
        exit;

    // ── Atualizar Dados e Senha ───────────────────────────────────────────
    } elseif ($acao === 'atualizar_perfil') {
        $nome = trim($_POST['nome'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $senhaNova = trim($_POST['senha_nova'] ?? '');
        $confirmarSenha = trim($_POST['confirmar_senha'] ?? '');
        $cargo = trim($_POST['cargo'] ?? $currentUser['cargo']);

        if (empty($nome) || empty($email)) {
            definirAlerta('danger', 'Nome e e-mail são obrigatórios.');
            header('Location: perfil.php');
            exit;
        }

        if (!empty($senhaNova)) {
            if (strlen($senhaNova) < 6) {
                definirAlerta('danger', 'A nova senha deve ter no mínimo 6 caracteres.');
                header('Location: perfil.php' . ((int)$currentUser['senha_alterada'] === 0 ? '?trocar_senha=1' : ''));
                exit;
            }
            if ($senhaNova !== $confirmarSenha) {
                definirAlerta('danger', 'A confirmação de senha não confere com a nova senha digitada.');
                header('Location: perfil.php' . ((int)$currentUser['senha_alterada'] === 0 ? '?trocar_senha=1' : ''));
                exit;
            }
            try {
                $senhaHash = password_hash($senhaNova, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE usuarios SET nome = ?, email = ?, senha = ?, cargo = ?, senha_alterada = 1 WHERE id = ?");
                $stmt->execute([$nome, $email, $senhaHash, $cargo, $currentUser['id']]);

                $_SESSION['usuario']['nome'] = $nome;
                $_SESSION['usuario']['email'] = $email;
                $_SESSION['usuario']['cargo'] = $cargo;
                $_SESSION['usuario']['senha_alterada'] = 1;

                definirAlerta('success', 'Perfil e nova senha atualizados com sucesso!');
                header('Location: perfil.php');
                exit;
            } catch (PDOException $e) {
                definirAlerta('danger', 'O e-mail informado já está em uso por outro usuário.');
                header('Location: perfil.php');
                exit;
            }
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE usuarios SET nome = ?, email = ?, cargo = ? WHERE id = ?");
                $stmt->execute([$nome, $email, $cargo, $currentUser['id']]);

                $_SESSION['usuario']['nome'] = $nome;
                $_SESSION['usuario']['email'] = $email;
                $_SESSION['usuario']['cargo'] = $cargo;

                definirAlerta('success', 'Perfil atualizado com sucesso!');
                header('Location: perfil.php');
                exit;
            } catch (PDOException $e) {
                definirAlerta('danger', 'O e-mail informado já está em uso por outro usuário.');
                header('Location: perfil.php');
                exit;
            }
        }
    } elseif ($acao === 'encerrar_outras') {
        encerrarOutrasSessoes($currentUser['id'], $currentUser['token_sessao']);
        definirAlerta('info', 'Todas as outras sessões ativas foram encerradas.');
        header('Location: perfil.php');
        exit;
    } elseif ($acao === 'encerrar_sessao_especifica') {
        $tokenSessao = $_POST['token_sessao'] ?? '';
        if (!empty($tokenSessao) && $tokenSessao !== $currentUser['token_sessao']) {
            $stmt = $pdo->prepare("DELETE FROM sessoes WHERE id = ? AND usuario_id = ?");
            $stmt->execute([$tokenSessao, $currentUser['id']]);
            definirAlerta('info', 'Sessão específica encerrada com sucesso.');
        }
        header('Location: perfil.php');
        exit;
    }
}

require_once __DIR__ . '/../includes/header.php';

// Lista de sessões ativas do usuário
$sessoesAtivas = listarSessoesUsuario($currentUser['id']);
$precisaTrocarSenha = ((int)$currentUser['senha_alterada'] === 0 || isset($_GET['trocar_senha']));
$temFotoPersonalizada = !empty($currentUser['avatar']) && file_exists(__DIR__ . '/../' . $currentUser['avatar']);
?>

<div class="page-header" style="margin-bottom: 20px;">
    <div class="page-title-group">
        <h1 style="font-size: 1.45rem; font-weight: 800; color: #0f172a;">Meu Perfil & Segurança</h1>
        <p style="font-size: 0.85rem; color: #64748b;">Gerencie sua foto de identificação, credenciais de acesso e sessões ativas</p>
    </div>
</div>

<?php if ($precisaTrocarSenha): ?>
    <div class="alert alert-warning" style="margin-bottom: 20px;">
        <span>🔐</span>
        <div>
            <strong>Atualização de Senha Recomendada:</strong> Você está utilizando a senha padrão inicial (<code>admin123</code>). 
            Preencha os campos de <strong>Nova Senha</strong> e <strong>Confirmar Senha</strong> abaixo para registrar sua senha definitiva e garantir total segurança.
        </div>
    </div>
<?php endif; ?>

<div class="form-row" style="grid-template-columns: 1.1fr 1.4fr; gap: 20px; align-items: flex-start;">
    
    <!-- Card Dados Pessoais & Foto -->
    <div class="card" style="border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <div class="card-header" style="padding: 14px 18px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
            <div class="card-title" style="font-size: 0.95rem; font-weight: 700; color: #1e293b;">
                <span>👤</span>
                <span>Dados do Operador</span>
            </div>
        </div>

        <div class="card-body" style="padding: 20px;">
            <!-- Seção de Foto do Perfil com Ajuste Interativo -->
            <div style="display: flex; align-items: center; gap: 18px; padding-bottom: 20px; margin-bottom: 18px; border-bottom: 1px solid #e2e8f0;">
                <div style="position: relative; width: 84px; height: 84px; flex-shrink: 0;">
                    <?php if ($temFotoPersonalizada): ?>
                        <img src="../<?= htmlspecialchars($currentUser['avatar']) ?>?t=<?= time() ?>" 
                             alt="Avatar" 
                             id="avatarPreviewImg"
                             style="width: 84px; height: 84px; border-radius: 50%; object-fit: cover; border: 3px solid #3b82f6; box-shadow: 0 4px 10px rgba(0,0,0,0.08);">
                    <?php else: ?>
                        <div id="avatarFallback" style="width: 84px; height: 84px; border-radius: 50%; background: linear-gradient(135deg, #2563eb, #1d4ed8); color: white; display: flex; align-items: center; justify-content: center; font-size: 32px; font-weight: 800; border: 3px solid #e2e8f0; box-shadow: 0 4px 10px rgba(37,99,235,0.2);">
                            <?= strtoupper(substr($currentUser['nome'] ?? 'U', 0, 1)) ?>
                        </div>
                    <?php endif; ?>

                    <span style="position: absolute; bottom: 2px; right: 2px; width: 14px; height: 14px; background: #10b981; border: 2.5px solid #fff; border-radius: 50%;" title="Online"></span>
                </div>

                <div style="flex: 1;">
                    <div style="font-weight: 800; font-size: 1.05rem; color: #0f172a; margin-bottom: 2px;">
                        <?= htmlspecialchars($currentUser['nome']) ?>
                    </div>
                    <div style="font-size: 0.8rem; color: #64748b; margin-bottom: 10px;">
                        <span class="badge badge-info" style="font-size: 0.72rem; padding: 2px 8px;"><?= htmlspecialchars($currentUser['cargo']) ?></span>
                    </div>

                    <!-- Botões de Ação da Foto -->
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <!-- Input File Invisível -->
                        <input type="file" id="inputFotoArquivo" accept="image/png, image/jpeg, image/jpg, image/webp" style="display: none;" onchange="aoSelecionarFoto(this)">

                        <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('inputFotoArquivo').click()" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.8rem; padding: 6px 12px;">
                            <span>📷</span>
                            <span>Alterar / Ajustar Foto</span>
                        </button>

                        <?php if ($temFotoPersonalizada): ?>
                            <form method="POST" action="perfil.php" onsubmit="return confirm('Deseja remover sua foto personalizada e voltar a exibir as iniciais?');" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="acao" value="remover_avatar">
                                <button type="submit" class="btn btn-outline btn-sm" style="color: #ef4444; border-color: #fecaca; font-size: 0.8rem; padding: 6px 10px;" title="Remover Foto">
                                    🗑️ Remover
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Formulário de Dados Cadastrais -->
            <form method="POST" action="perfil.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="acao" value="atualizar_perfil">

                <div class="form-group" style="margin-bottom: 12px;">
                    <label class="form-label" for="nome" style="font-size: 0.82rem; font-weight: 700;">Nome Completo *</label>
                    <input type="text" name="nome" id="nome" class="form-control" value="<?= htmlspecialchars($currentUser['nome']) ?>" required>
                </div>

                <div class="form-group" style="margin-bottom: 12px;">
                    <label class="form-label" for="email" style="font-size: 0.82rem; font-weight: 700;">E-mail de Login *</label>
                    <input type="email" name="email" id="email" class="form-control" value="<?= htmlspecialchars($currentUser['email']) ?>" required>
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="form-label" for="cargo" style="font-size: 0.82rem; font-weight: 700;">Função / Cargo</label>
                    <input type="text" name="cargo" id="cargo" class="form-control" value="<?= htmlspecialchars($currentUser['cargo']) ?>">
                </div>

                <!-- Box de Troca de Senha -->
                <div style="padding: 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: var(--radius-md); margin-bottom: 16px;">
                    <div style="font-weight: 700; font-size: 0.85rem; color: #1e293b; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                        <span>🔑</span>
                        <span>Alteração de Senha <?= $precisaTrocarSenha ? '<span style="color:#ef4444;">*</span>' : '(Opcional)' ?></span>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 8px;">
                        <div>
                            <label class="form-label" for="senha_nova" style="font-size: 0.78rem;">Nova Senha</label>
                            <input type="password" name="senha_nova" id="senha_nova" class="form-control" placeholder="Mínimo 6 dígitos" minlength="6" <?= $precisaTrocarSenha ? 'required' : '' ?>>
                        </div>
                        <div>
                            <label class="form-label" for="confirmar_senha" style="font-size: 0.78rem;">Confirmar Senha</label>
                            <input type="password" name="confirmar_senha" id="confirmar_senha" class="form-control" placeholder="Repita a nova senha" minlength="6" <?= $precisaTrocarSenha ? 'required' : '' ?>>
                        </div>
                    </div>
                    <small style="color: #64748b; font-size: 0.76rem; display: block;">
                        <?= $precisaTrocarSenha ? 'Defina uma senha pessoal para substituir o padrão "admin123".' : 'Preencha somente se desejar alterar sua senha atual.' ?>
                    </small>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; font-weight: 700; height: 40px;">
                    Salvar Alterações do Perfil
                </button>
            </form>
        </div>
    </div>

    <!-- Card Controle de Sessões no Banco MySQL -->
    <div class="card" style="border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <div class="card-header" style="padding: 14px 18px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
            <div class="card-title" style="font-size: 0.95rem; font-weight: 700; color: #1e293b;">
                <span>🔐</span>
                <span>Sessões Ativas no Banco</span>
            </div>
            <?php if (count($sessoesAtivas) > 1): ?>
                <form method="POST" action="perfil.php" onsubmit="return confirm('Deseja desconectar todas as outras sessões ativas?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="acao" value="encerrar_outras">
                    <button type="submit" class="btn btn-outline btn-sm" style="color: #ef4444; border-color: #fecaca; font-size: 0.76rem; padding: 3px 8px;">
                        Encerrar Outras
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <div class="card-body" style="padding: 0;">
            <div style="padding: 12px 18px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-size: 0.8rem; color: #64748b;">
                O sistema rastreia os dispositivos conectados em tempo real na tabela <code>sessoes</code>.
            </div>

            <div class="table-responsive">
                <table class="table-compact-report" style="width: 100%;">
                    <thead>
                        <tr style="background: #f1f5f9; color: #475569;">
                            <th style="padding: 8px 14px; text-align: left; font-size: 0.76rem;">Dispositivo / Navegador</th>
                            <th style="padding: 8px 14px; text-align: left; font-size: 0.76rem;">IP</th>
                            <th style="padding: 8px 14px; text-align: left; font-size: 0.76rem;">Último Acesso</th>
                            <th style="padding: 8px 10px; width: 60px; text-align: center;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sessoesAtivas as $s): 
                            $isAtual = ($s['id'] === ($currentUser['token_sessao'] ?? ''));
                        ?>
                            <tr style="<?= $isAtual ? 'background: #eff6ff;' : '' ?>">
                                <td style="padding: 10px 14px;">
                                    <div style="font-weight: 600; font-size: 0.82rem; color: #0f172a;">
                                        <?= htmlspecialchars(substr($s['user_agent'], 0, 48)) ?>...
                                    </div>
                                    <?php if ($isAtual): ?>
                                        <span style="font-size: 0.68rem; font-weight: 700; color: #2563eb; background: #dbeafe; padding: 1px 6px; border-radius: 4px;">
                                            SESSÃO ATUAL
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px 14px; font-size: 0.78rem; color: #64748b;">
                                    <?= htmlspecialchars($s['ip_address'] ?? '127.0.0.1') ?>
                                </td>
                                <td style="padding: 10px 14px; font-size: 0.78rem; color: #64748b;">
                                    <?= date('d/m/Y H:i', strtotime($s['ultimo_acesso'])) ?>
                                </td>
                                <td style="padding: 10px; text-align: center;">
                                    <?php if (!$isAtual): ?>
                                        <form method="POST" action="perfil.php" onsubmit="return confirm('Deseja desconectar este dispositivo?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="acao" value="encerrar_sessao_especifica">
                                            <input type="hidden" name="token_sessao" value="<?= htmlspecialchars($s['id']) ?>">
                                            <button type="submit" class="btn btn-outline btn-sm btn-icon" style="color: #ef4444; width: 26px; height: 26px;" title="Desconectar sessão">
                                                ✕
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================
     MODAL DE AJUSTE / RECORTE DE FOTO INTERATIVO (CANVAS)
     ======================================================================== -->
<div class="modal-overlay" id="modalAjustarFoto">
    <div class="modal-container" style="max-width: 460px;">
        <div class="modal-header" style="border-bottom: 1px solid #e2e8f0;">
            <div>
                <h3 class="modal-title" style="display: flex; align-items: center; gap: 8px; font-size: 1.1rem;">
                    <span>✂️</span>
                    <span>Ajustar Foto do Perfil</span>
                </h3>
                <div style="font-size: 0.78rem; color: #64748b; margin-top: 2px;">
                    Arraste a foto e use o zoom para enquadrar seu rosto no círculo.
                </div>
            </div>
            <button type="button" class="modal-close" onclick="fecharModalAjusteFoto()">&times;</button>
        </div>

        <div class="modal-body" style="padding: 18px; text-align: center;">
            <!-- Área de Visualização e Ajuste (Canvas) -->
            <div style="position: relative; width: 300px; height: 300px; margin: 0 auto; border-radius: 12px; overflow: hidden; background: #0f172a; box-shadow: 0 4px 15px rgba(0,0,0,0.15); cursor: grab;" id="areaCanvasCrop">
                <canvas id="cropCanvas" width="300" height="300" style="display: block; width: 100%; height: 100%;"></canvas>
            </div>

            <!-- Controles de Ajuste (Zoom & Rotação) -->
            <div style="margin-top: 16px; padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                    <span style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; color: #64748b;">
                        🔍 Zoom da Imagem
                    </span>
                    <span id="zoomValorLabel" style="font-size: 0.8rem; font-weight: 700; color: #2563eb;">100%</span>
                </div>
                
                <div style="display: flex; align-items: center; gap: 10px;">
                    <button type="button" class="btn btn-outline btn-sm" onclick="ajustarZoomPasso(-0.1)" style="width: 32px; height: 32px; padding: 0; font-weight: 800;">-</button>
                    <input type="range" id="sliderZoom" min="0.5" max="3.0" step="0.05" value="1.0" style="flex: 1; accent-color: #2563eb; cursor: pointer;" oninput="aoMudarZoom(this.value)">
                    <button type="button" class="btn btn-outline btn-sm" onclick="ajustarZoomPasso(0.1)" style="width: 32px; height: 32px; padding: 0; font-weight: 800;">+</button>
                </div>

                <div style="display: flex; justify-content: center; gap: 10px; margin-top: 10px;">
                    <button type="button" class="btn btn-outline btn-sm" onclick="girarFoto(90)" style="font-size: 0.78rem; display: inline-flex; align-items: center; gap: 4px;">
                        <span>🔄</span> Girar 90°
                    </button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="resetarAjustesFoto()" style="font-size: 0.78rem;">
                        ↺ Centralizar
                    </button>
                </div>
            </div>
        </div>

        <!-- Formulário Oculto de Envio do Corte -->
        <form method="POST" action="perfil.php" id="formSalvarCrop">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="acao" value="salvar_avatar_crop">
            <input type="hidden" name="avatar_base64" id="avatar_base64_input" value="">

            <div class="modal-footer" style="padding: 12px 18px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between;">
                <button type="button" class="btn btn-outline btn-sm" onclick="fecharModalAjusteFoto()">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="confirmarSalvarFoto()" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 700; padding: 8px 16px;">
                    <span>✓</span>
                    <span>Salvar Foto Ajustada</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Motor Interativo de Recorte e Ajuste de Foto (Canvas 2D) ─────────────
let imagemOriginal = null;
let cropCanvas = null;
let cropCtx = null;

let estadoCrop = {
    x: 0,
    y: 0,
    zoom: 1.0,
    rotacao: 0, // em graus: 0, 90, 180, 270
    isDragging: false,
    startX: 0,
    startY: 0
};

document.addEventListener('DOMContentLoaded', function() {
    cropCanvas = document.getElementById('cropCanvas');
    if (cropCanvas) {
        cropCtx = cropCanvas.getContext('2d');
        configurarEventosDrag();
    }
});

function aoSelecionarFoto(input) {
    if (!input.files || !input.files[0]) return;
    const arquivo = input.files[0];

    // Valida tipo
    if (!arquivo.type.match(/^image\//)) {
        alert('Por favor, selecione um arquivo de imagem válido (PNG, JPG, JPEG ou WEBP).');
        input.value = '';
        return;
    }

    const leitor = new FileReader();
    leitor.onload = function(e) {
        imagemOriginal = new Image();
        imagemOriginal.onload = function() {
            resetarAjustesFoto();
            abrirModal('modalAjustarFoto');
            desenharCanvasCrop();
        };
        imagemOriginal.src = e.target.result;
    };
    leitor.readAsDataURL(arquivo);
}

function resetarAjustesFoto() {
    if (!imagemOriginal || !cropCanvas) return;
    
    estadoCrop.rotacao = 0;
    
    // Calcula zoom inicial para cobrir o círculo de 300px
    const menorLado = Math.min(imagemOriginal.width, imagemOriginal.height);
    estadoCrop.zoom = (300 / menorLado);
    
    // Centraliza
    estadoCrop.x = 150;
    estadoCrop.y = 150;

    const slider = document.getElementById('sliderZoom');
    if (slider) {
        slider.min = (estadoCrop.zoom * 0.5).toFixed(2);
        slider.max = (estadoCrop.zoom * 3.5).toFixed(2);
        slider.value = estadoCrop.zoom.toFixed(2);
    }
    atualizarLabelZoom();
    desenharCanvasCrop();
}

function aoMudarZoom(val) {
    estadoCrop.zoom = parseFloat(val);
    atualizarLabelZoom();
    desenharCanvasCrop();
}

function ajustarZoomPasso(passo) {
    const slider = document.getElementById('sliderZoom');
    if (!slider) return;
    let novoVal = parseFloat(slider.value) + passo;
    novoVal = Math.max(parseFloat(slider.min), Math.min(parseFloat(slider.max), novoVal));
    slider.value = novoVal;
    aoMudarZoom(novoVal);
}

function atualizarLabelZoom() {
    const label = document.getElementById('zoomValorLabel');
    if (label && imagemOriginal) {
        const perc = Math.round((estadoCrop.zoom / (300 / Math.min(imagemOriginal.width, imagemOriginal.height))) * 100);
        label.innerText = perc + '%';
    }
}

function girarFoto(graus) {
    estadoCrop.rotacao = (estadoCrop.rotacao + graus) % 360;
    desenharCanvasCrop();
}

function configurarEventosDrag() {
    const area = document.getElementById('areaCanvasCrop');
    if (!area) return;

    // Mouse Events
    area.addEventListener('mousedown', function(e) {
        estadoCrop.isDragging = true;
        estadoCrop.startX = e.clientX - estadoCrop.x;
        estadoCrop.startY = e.clientY - estadoCrop.y;
        area.style.cursor = 'grabbing';
    });

    window.addEventListener('mousemove', function(e) {
        if (!estadoCrop.isDragging) return;
        estadoCrop.x = e.clientX - estadoCrop.startX;
        estadoCrop.y = e.clientY - estadoCrop.startY;
        desenharCanvasCrop();
    });

    window.addEventListener('mouseup', function() {
        if (estadoCrop.isDragging) {
            estadoCrop.isDragging = false;
            if (area) area.style.cursor = 'grab';
        }
    });

    // Touch Events (Celular / Tablet)
    area.addEventListener('touchstart', function(e) {
        if (e.touches.length === 1) {
            estadoCrop.isDragging = true;
            estadoCrop.startX = e.touches[0].clientX - estadoCrop.x;
            estadoCrop.startY = e.touches[0].clientY - estadoCrop.y;
        }
    }, { passive: false });

    window.addEventListener('touchmove', function(e) {
        if (!estadoCrop.isDragging || e.touches.length !== 1) return;
        estadoCrop.x = e.touches[0].clientX - estadoCrop.startX;
        estadoCrop.y = e.touches[0].clientY - estadoCrop.startY;
        desenharCanvasCrop();
        e.preventDefault();
    }, { passive: false });

    window.addEventListener('touchend', function() {
        estadoCrop.isDragging = false;
    });

    // Zoom via Scroll do Mouse no Canvas
    area.addEventListener('wheel', function(e) {
        e.preventDefault();
        const passo = (e.deltaY < 0) ? 0.08 : -0.08;
        ajustarZoomPasso(passo);
    }, { passive: false });
}

function desenharCanvasCrop() {
    if (!imagemOriginal || !cropCtx) return;

    const w = cropCanvas.width;
    const h = cropCanvas.height;

    // Limpa fundo
    cropCtx.clearRect(0, 0, w, h);
    cropCtx.fillStyle = '#0f172a';
    cropCtx.fillRect(0, 0, w, h);

    // Salva contexto para aplicar transformações da imagem
    cropCtx.save();
    cropCtx.translate(estadoCrop.x, estadoCrop.y);
    cropCtx.rotate((estadoCrop.rotacao * Math.PI) / 180);
    cropCtx.scale(estadoCrop.zoom, estadoCrop.zoom);

    // Desenha imagem centralizada no ponto x, y
    cropCtx.drawImage(
        imagemOriginal, 
        -imagemOriginal.width / 2, 
        -imagemOriginal.height / 2
    );
    cropCtx.restore();

    // ── Desenha a Máscara Circular Translúcida (Guia Visual) ───────────────
    cropCtx.save();
    cropCtx.fillStyle = 'rgba(15, 23, 42, 0.65)';
    cropCtx.beginPath();
    // Retângulo externo
    cropCtx.rect(0, 0, w, h);
    // Círculo central recortado
    cropCtx.arc(w / 2, h / 2, 140, 0, Math.PI * 2, true);
    cropCtx.fill();

    // Borda do círculo
    cropCtx.strokeStyle = '#ffffff';
    cropCtx.lineWidth = 2.5;
    cropCtx.beginPath();
    cropCtx.arc(w / 2, h / 2, 140, 0, Math.PI * 2);
    cropCtx.stroke();
    cropCtx.restore();
}

function fecharModalAjusteFoto() {
    fecharModal('modalAjustarFoto');
    const input = document.getElementById('inputFotoArquivo');
    if (input) input.value = '';
}

function confirmarSalvarFoto() {
    if (!imagemOriginal) return;

    // Cria um canvas temporário de 320x320 com o recorte exato do círculo central
    const exportCanvas = document.createElement('canvas');
    exportCanvas.width = 320;
    exportCanvas.height = 320;
    const expCtx = exportCanvas.getContext('2d');

    // Escala equivalente (320 export vs 280 círculo guia)
    const escalaFinal = 320 / 280;

    expCtx.save();
    // Centraliza no canvas export
    expCtx.translate(160, 160);
    // Aplica o offset do usuário relativo ao centro (150, 150)
    const offsetX = (estadoCrop.x - 150) * escalaFinal;
    const offsetY = (estadoCrop.y - 150) * escalaFinal;
    expCtx.translate(offsetX, offsetY);
    expCtx.rotate((estadoCrop.rotacao * Math.PI) / 180);
    expCtx.scale(estadoCrop.zoom * escalaFinal, estadoCrop.zoom * escalaFinal);

    expCtx.drawImage(
        imagemOriginal, 
        -imagemOriginal.width / 2, 
        -imagemOriginal.height / 2
    );
    expCtx.restore();

    // Exporta imagem em JPEG de alta qualidade
    const dataUrl = exportCanvas.toDataURL('image/jpeg', 0.92);

    document.getElementById('avatar_base64_input').value = dataUrl;
    document.getElementById('formSalvarCrop').submit();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
