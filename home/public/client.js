// client.js - Interface client pour NeonMail (Version Scalingo)
const socket = io();

// Détecter si on est sur Scalingo
const isScalingo = window.location.hostname.includes('scalingo.io') || 
                   window.location.hostname.includes('scalingo');

// Éléments DOM
const form = document.getElementById('emailForm');
const sendBtn = document.getElementById('sendBtn');
const pauseBtn = document.getElementById('pauseBtn');
const stopBtn = document.getElementById('stopBtn');
const consoleOutput = document.getElementById('consoleOutput');
const progressBar = document.getElementById('progressBar');
const sentCountSpan = document.getElementById('sentCount');
const failCountSpan = document.getElementById('failCount');
const refreshPjBtn = document.getElementById('refreshPjFiles');
const retryFailedBtn = document.getElementById('retryFailedBtn');
const failedSection = document.getElementById('failedSection');
const failedInfo = document.getElementById('failedInfo');

// État de l'envoi
let isSending = false;
let isPaused = false;
let isStopped = false;
let isAuthenticated = false;

// Variables pour les pièces jointes
let selectedAttachments = [];
let availableFiles = [];

// === GESTION DES PIÈCES JOINTES ===

async function refreshPjFiles() {
    try {
        const response = await fetch('/api/pj-files');
        const data = await response.json();
        availableFiles = data.files || [];
        
        const pjFilesList = document.getElementById('pjFilesList');
        if (availableFiles.length === 0) {
            pjFilesList.innerHTML = '<div class="pj-empty">📁 Aucun fichier dans le dossier PJ</div>';
            return;
        }
        
        pjFilesList.innerHTML = availableFiles.map(file => `
            <div class="pj-file-item" data-filename="${file.name}">
                <span class="file-name">📄 ${file.name}</span>
                <span class="file-size">${formatFileSize(file.size)}</span>
                <button type="button" class="add-attachment-btn" onclick="addAttachment('${file.name.replace(/'/g, "\\'")}')">+</button>
            </div>
        `).join('');
    } catch (e) {
        console.error('Failed to load PJ files:', e);
        document.getElementById('pjFilesList').innerHTML = '<div class="pj-empty">❌ Erreur de chargement</div>';
    }
}

function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

window.addAttachment = function(filename) {
    if (!selectedAttachments.find(a => a.name === filename)) {
        const file = availableFiles.find(f => f.name === filename);
        if (file) {
            selectedAttachments.push(file);
            updateAttachmentList();
            addLog(`📎 Pièce jointe ajoutée: ${filename}`, 'info');
        }
    }
};

window.removeAttachment = function(filename) {
    selectedAttachments = selectedAttachments.filter(a => a.name !== filename);
    updateAttachmentList();
    addLog(`📎 Pièce jointe retirée: ${filename}`, 'info');
};

function updateAttachmentList() {
    const attachmentList = document.getElementById('attachmentList');
    if (selectedAttachments.length === 0) {
        attachmentList.innerHTML = '<div class="attachment-empty">📎 Aucune pièce jointe sélectionnée</div>';
        return;
    }
    
    attachmentList.innerHTML = selectedAttachments.map(file => `
        <div class="attachment-item">
            <span class="file-name">📎 ${file.name}</span>
            <span class="file-size">${formatFileSize(file.size)}</span>
            <button type="button" class="remove-attachment-btn" onclick="removeAttachment('${file.name.replace(/'/g, "\\'")}')">✖</button>
        </div>
    `).join('');
}

// === GESTION DYNAMIQUE DES CHAMPS OBLIGATOIRES ===
function updateRequiredFields() {
    // En mode Scalingo, les champs SMTP ne sont plus requis
    const smtpHostField = document.getElementById('smtpHost');
    const smtpUsernameField = document.getElementById('smtpUsername');
    const smtpPasswordField = document.getElementById('smtpPassword');
    const fromEmailField = document.getElementById('fromEmail');
    
    // Rendre les champs SMTP optionnels en mode Scalingo
    if (isScalingo) {
        if (smtpHostField) {
            smtpHostField.required = false;
            smtpHostField.placeholder = '127.0.0.1 (auto)';
        }
        if (smtpUsernameField) {
            smtpUsernameField.required = false;
            smtpUsernameField.placeholder = 'Non requis';
        }
        if (smtpPasswordField) {
            smtpPasswordField.required = false;
            smtpPasswordField.placeholder = 'Non requis';
        }
    } else {
        // Mode local : SMTP requis
        if (smtpHostField) {
            smtpHostField.required = true;
            smtpHostField.placeholder = 'smtp.gmail.com';
        }
        if (smtpUsernameField) {
            smtpUsernameField.required = true;
            smtpUsernameField.placeholder = 'votre@email.com';
        }
        if (smtpPasswordField) {
            smtpPasswordField.required = true;
            smtpPasswordField.placeholder = 'Mot de passe ou token';
        }
    }
    
    // From email est toujours requis
    if (fromEmailField) {
        fromEmailField.required = true;
        fromEmailField.placeholder = 'expediteur@domaine.com';
        fromEmailField.style.opacity = '1';
    }
}

// === AUTHENTIFICATION UNIQUE ===
async function authenticate() {
    return new Promise((resolve) => {
        const modal = document.createElement('div');
        modal.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.95);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10000;
            backdrop-filter: blur(10px);
        `;
        
        modal.innerHTML = `
            <div style="background: linear-gradient(135deg, #0a0a1a 0%, #1a1a2e 100%); border: 1px solid #00f3ff; border-radius: 10px; padding: 40px; width: 400px; box-shadow: 0 0 50px rgba(0, 243, 255, 0.3);">
                <h2 style="color: #00f3ff; font-family: 'Orbitron', monospace; margin-bottom: 20px; text-align: center;">🔐 AUTHENTIFICATION</h2>
                <p style="color: #888; text-align: center; margin-bottom: 20px; font-size: 0.85rem;">Entrez la clé d'accès pour déverrouiller le système</p>
                <p style="color: #ffaa00; text-align: center; margin-bottom: 15px; font-size: 0.8rem;">✨ "La Force est avec vous, jeune Padawan." ✨</p>
                <input type="password" id="passwordInput" placeholder="Entrez la clé d'accès..." style="width: 100%; padding: 12px; background: #0a0a1a; border: 1px solid #00f3ff; color: #00f3ff; font-family: monospace; border-radius: 5px; margin-bottom: 20px; font-size: 1rem;">
                <div style="display: flex; gap: 10px;">
                    <button id="submitPassword" style="flex: 1; background: #00f3ff; color: #0a0a1a; border: none; padding: 12px; font-weight: bold; cursor: pointer; border-radius: 5px; font-family: 'Orbitron', monospace;">🔓 DÉVERROUILLER</button>
                </div>
                <p style="color: #666; text-align: center; margin-top: 20px; font-size: 0.7rem;">Contactez l'administrateur pour obtenir la clé</p>
                <p style="color: #444; text-align: center; margin-top: 10px; font-size: 0.7rem;">Session valide 24h</p>
            </div>
        `;
        
        document.body.appendChild(modal);
        
        const passwordInput = modal.querySelector('#passwordInput');
        const submitBtn = modal.querySelector('#submitPassword');
        
        passwordInput.focus();
        
        submitBtn.onclick = async () => {
            const password = passwordInput.value;
            if (!password) {
                addLog('❌ Veuillez entrer un mot de passe', 'error');
                return;
            }
            
            addLog('🔐 Vérification du mot de passe en cours...', 'system');
            
            try {
                const response = await fetch('/verify-password', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ password: password })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    addLog('✅ Authentification réussie ! Session valide pour 24h.', 'success');
                    addLog('✨ "La Force est puissante en vous." - Maître Yoda', 'success');
                    isAuthenticated = true;
                    modal.remove();
                    resolve(true);
                } else {
                    addLog('❌ Mot de passe incorrect. Accès refusé.', 'error');
                    passwordInput.value = '';
                    passwordInput.focus();
                }
            } catch (e) {
                addLog(`❌ Erreur de vérification: ${e.message}`, 'error');
            }
        };
        
        passwordInput.onkeypress = (e) => {
            if (e.key === 'Enter') submitBtn.onclick();
        };
    });
}

// Vérifier l'état de l'authentification au chargement
async function checkAuthStatus() {
    try {
        const response = await fetch('/check-auth');
        const data = await response.json();
        
        if (data.authenticated) {
            isAuthenticated = true;
            addLog('✅ Session authentifiée trouvée !', 'success');
            if (data.validationTime) {
                const date = new Date(data.validationTime);
                addLog(`📅 Authentifiée le: ${date.toLocaleString()}`, 'info');
                addLog(`⏰ Session expirera dans 24h`, 'info');
            }
            return true;
        } else if (data.expired) {
            addLog('⚠️ Session expirée. Veuillez vous réauthentifier.', 'warning');
            return false;
        }
        return false;
    } catch (e) {
        console.error('Erreur vérification session:', e);
        return false;
    }
}

function addLog(message, type = 'info') {
    const logLine = document.createElement('div');
    logLine.className = `log-line ${type}`;
    const timestamp = new Date().toLocaleTimeString();
    logLine.innerHTML = `[${timestamp}] > ${message}`;
    consoleOutput.appendChild(logLine);
    logLine.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    while (consoleOutput.children.length > 500) {
        consoleOutput.removeChild(consoleOutput.firstChild);
    }
}

function updateProgress(sent, failed, total) {
    const progress = total > 0 ? ((sent + failed) / total) * 100 : 0;
    progressBar.style.width = `${progress}%`;
    sentCountSpan.textContent = `ENVOYÉS: ${sent}`;
    failCountSpan.textContent = `ÉCHOUÉS: ${failed}`;
}

async function loadFailedCount() {
    try {
        const response = await fetch('/api/failed-count');
        const data = await response.json();
        if (data.count > 0) {
            failedSection.style.display = 'block';
            const lastError = data.batches[0]?.lastError || 'Inconnu';
            failedInfo.innerHTML = `⚠️ ${data.count} lot(s) échoué(s) disponibles. Dernière erreur: ${lastError}`;
        } else {
            failedSection.style.display = 'none';
        }
    } catch (e) {
        console.error('Failed to load failed count:', e);
    }
}

retryFailedBtn.onclick = async () => {
    if (!isAuthenticated) {
        addLog('❌ Veuillez vous authentifier d\'abord', 'error');
        await authenticate();
        if (!isAuthenticated) return;
    }
    
    addLog('🔄 Relance des lots échoués...', 'system');
    
    const data = {
        smtpHost: document.getElementById('smtpHost')?.value || '',
        smtpPort: document.getElementById('smtpPort')?.value || '25',
        smtpUsername: document.getElementById('smtpUsername')?.value || '',
        smtpPassword: document.getElementById('smtpPassword')?.value || '',
        maillist: document.getElementById('maillist')?.value || '',
        fromName: document.getElementById('fromName')?.value || '',
        fromEmail: document.getElementById('fromEmail')?.value || '',
        subject: document.getElementById('subject')?.value || '',
        message: document.getElementById('message')?.value || '',
        customHeaders: document.getElementById('customHeaders')?.value || '',
        threads: document.getElementById('threads')?.value || '1',
        bccCount: document.getElementById('bccCount')?.value || '1',
        testEmail: document.getElementById('testEmail')?.value || '',
        testInterval: document.getElementById('testInterval')?.value || '',
        pauseEvery: document.getElementById('pauseEvery')?.value || '',
        pauseTime: document.getElementById('pauseTime')?.value || '',
        useBcc: document.getElementById('useBcc')?.checked || false,
        useReplyTo: document.getElementById('useReplyTo')?.checked || false,
        replyTo: document.getElementById('useReplyTo')?.checked ? 'keevcloud@gmail.com' : undefined,
        attachmentFiles: selectedAttachments,
        emailDelay: document.getElementById('emailDelay')?.value || '0',
        retry: true
    };
    
    try {
        const response = await fetch('/retry-failed', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await response.json();
        if (result.success) {
            addLog('✅ Processus de relance initié', 'success');
        } else {
            if (result.error === 'Session non authentifiée. Veuillez vous authentifier.') {
                addLog('❌ Session expirée. Veuillez vous réauthentifier.', 'error');
                isAuthenticated = false;
                await authenticate();
                retryFailedBtn.onclick();
            } else {
                addLog(`❌ Relance échouée: ${result.error}`, 'error');
            }
        }
    } catch (e) {
        addLog(`❌ Erreur relance: ${e.message}`, 'error');
    }
};

// === SOUMISSION DU FORMULAIRE ===
form.onsubmit = async (e) => {
    e.preventDefault();
    
    if (!isAuthenticated) {
        addLog('❌ Veuillez vous authentifier d\'abord', 'error');
        await authenticate();
        if (!isAuthenticated) return;
    }
    
    if (isSending) {
        addLog('⚠️ Processus déjà en cours. Veuillez attendre ou abandonner.', 'warning');
        return;
    }
    
    isSending = true;
    isPaused = false;
    isStopped = false;
    
    sendBtn.disabled = true;
    pauseBtn.disabled = false;
    stopBtn.disabled = false;
    pauseBtn.innerHTML = '<span class="btn-content">⏸️ PAUSE</span>';
    
    addLog('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', 'system');
    addLog('🚀 LANCEMENT DE LA SÉQUENCE', 'system');
    addLog('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', 'system');
    
    // Récupération des valeurs
    const useBccCheckbox = document.getElementById('useBcc');
    const useReplyToCheckbox = document.getElementById('useReplyTo');
    
    const useBccValue = useBccCheckbox ? useBccCheckbox.checked : false;
    const useReplyToValue = useReplyToCheckbox ? useReplyToCheckbox.checked : false;
    
    // Log de debug
    addLog(`🔍 CONFIGURATION:`, 'info');
    addLog(`   ├─ Mode: ${isScalingo ? 'SCALINGO' : 'LOCAL'}`, 'info');
    addLog(`   ├─ Mode BCC: ${useBccValue ? 'ON' : 'OFF'}`, 'info');
    addLog(`   ├─ Threads: ${document.getElementById('threads')?.value || '1'}`, 'info');
    addLog(`   └─ Délai entre emails: ${document.getElementById('emailDelay')?.value || '0'}ms`, 'info');
    
    // Construction des données
    const data = {
        smtpHost: document.getElementById('smtpHost')?.value || '',
        smtpPort: document.getElementById('smtpPort')?.value || (isScalingo ? '25' : '587'),
        smtpUsername: document.getElementById('smtpUsername')?.value || '',
        smtpPassword: document.getElementById('smtpPassword')?.value || '',
        maillist: document.getElementById('maillist')?.value || '',
        fromName: document.getElementById('fromName')?.value || '',
        fromEmail: document.getElementById('fromEmail')?.value || '',
        subject: document.getElementById('subject')?.value || '',
        message: document.getElementById('message')?.value || '',
        customHeaders: document.getElementById('customHeaders')?.value || '',
        threads: document.getElementById('threads')?.value || '1',
        bccCount: document.getElementById('bccCount')?.value || '1',
        testEmail: document.getElementById('testEmail')?.value || '',
        testInterval: document.getElementById('testInterval')?.value || '',
        pauseEvery: document.getElementById('pauseEvery')?.value || '',
        pauseTime: document.getElementById('pauseTime')?.value || '',
        useBcc: useBccValue,
        useReplyTo: useReplyToValue,
        replyTo: useReplyToValue ? 'keevcloud@gmail.com' : undefined,
        attachmentFiles: selectedAttachments,
        emailDelay: document.getElementById('emailDelay')?.value || '0'
    };
    
    // Validation des champs requis
    if (!data.fromEmail) {
        addLog('❌ ERREUR: EMAIL_EXPÉDITEUR requis', 'error');
        resetForm();
        return;
    }
    
    if (!data.maillist) {
        addLog('❌ ERREUR: Liste des cibles requise', 'error');
        resetForm();
        return;
    }
    
    if (!data.subject) {
        addLog('❌ ERREUR: Objet requis', 'error');
        resetForm();
        return;
    }
    
    if (!data.message) {
        addLog('❌ ERREUR: Contenu du message requis', 'error');
        resetForm();
        return;
    }
    
    try {
        const response = await fetch('/send', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        if (!result.success) {
            if (result.error === 'Session non authentifiée. Veuillez vous authentifier.' || 
                result.error === 'Session expirée. Veuillez vous réauthentifier.') {
                addLog('❌ Session expirée. Veuillez vous réauthentifier.', 'error');
                isAuthenticated = false;
                await authenticate();
                if (isAuthenticated) {
                    form.onsubmit(e);
                }
            } else {
                addLog(`❌ Échec du démarrage: ${result.error || 'Erreur inconnue'}`, 'error');
            }
            resetForm();
        }
    } catch (e) {
        addLog(`❌ Erreur
